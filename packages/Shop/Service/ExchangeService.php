<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Enum\ClaimResponsibility;
use Mublo\Packages\Shop\Enum\ClaimStatus;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Event\ClaimStatusChangedEvent;
use Mublo\Packages\Shop\Repository\ClaimRepository;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Contract\Security\SensitiveValueCodecInterface;

final class ExchangeService
{
    private const REASON_TYPES = [
        'CHANGE_MIND', 'DEFECT', 'WRONG_PRODUCT', 'WRONG_OPTION', 'LATE_DELIVERY', 'OTHER',
    ];

    /** 외부 확장 이벤트에 공개해도 되는 비식별 클레임 필드. */
    private const PUBLIC_CLAIM_FIELDS = [
        'return_id', 'domain_id', 'order_no', 'order_detail_id', 'return_type', 'return_status',
        'original_item_status', 'reason_type', 'responsibility', 'quantity', 'exchange_shipping_fee',
        'fee_status', 'fee_method', 'collect_courier', 'collect_invoice', 'requested_at', 'accepted_at',
        'collected_at', 'inspected_at', 'reshipped_at', 'cancelled_at', 'completed_at', 'created_at',
        'updated_at', 'source_goods_id', 'source_goods_name', 'source_goods_image', 'source_option_mode',
        'source_option_id', 'source_option_code', 'source_option_name', 'source_option_price',
        'ordered_quantity', 'current_item_status', 'exchange_item_id', 'target_goods_id',
        'target_option_mode', 'target_option_id', 'target_option_code', 'target_option_name',
        'target_option_price', 'exchange_quantity', 'stock_reservation_status', 'stock_reserved_at',
        'stock_released_at', 'inspection_result', 'source_restocked_at',
    ];

    public function __construct(
        private ClaimRepository $claims,
        private OrderRepository $orders,
        private ProductOptionRepository $options,
        private OrderStateResolver $orderStates,
        private ClaimStateMachine $stateMachine,
        private ExchangeStockService $stock,
        private ShipmentService $shipments,
        private SensitiveValueCodecInterface $encryption,
        private ?EventDispatcher $events = null,
    ) {}

    public function list(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        return $this->claims->list($domainId, $filters, $page, $perPage);
    }

    public function get(int $domainId, int $claimId): ?array
    {
        $claim = $this->claims->findInDomain($domainId, $claimId);
        if ($claim === null) {
            return null;
        }
        $claim = $this->decryptClaim($claim);
        $claim['logs'] = $this->claims->getLogs($domainId, $claimId);
        $claim['shipments'] = $this->shipments->getByClaimId($claimId);
        $claim['next_statuses'] = array_map(
            static fn(ClaimStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
            $this->stateMachine->next((string) $claim['return_status'])
        );
        return $claim;
    }

    public function getByOrderNo(int $domainId, string $orderNo): array
    {
        return array_map(function (array $claim) use ($domainId): array {
            $full = $this->get($domainId, (int) $claim['return_id']);
            return $full ?? $claim;
        }, $this->claims->getByOrderNo($domainId, $orderNo));
    }

    public function getActiveByDetailId(int $domainId, int $detailId): array
    {
        return $this->claims->getActiveByDetailId($domainId, $detailId);
    }

    /** 고객/관리자 선택용 동일 상품 교환 옵션. */
    public function getExchangeOptions(int $domainId, int $detailId): array
    {
        $item = $this->orders->getItemInDomain($domainId, $detailId);
        if ($item === null) {
            return [];
        }
        $mode = (string) ($item['option_mode'] ?? 'NONE');
        $sourcePrice = (int) ($item['option_price'] ?? 0);
        $goodsId = (int) ($item['goods_id'] ?? 0);

        if ($mode === 'NONE') {
            return [[
                'option_mode' => 'NONE', 'option_id' => 0, 'option_code' => '',
                'option_name' => '동일 상품', 'option_price' => 0, 'stock_quantity' => null,
            ]];
        }
        if ($mode === 'COMBINATION') {
            $result = [];
            foreach ($this->options->getCombos($goodsId) as $combo) {
                if (empty($combo['is_active']) || (int) ($combo['extra_price'] ?? 0) !== $sourcePrice) {
                    continue;
                }
                $result[] = [
                    'option_mode' => 'COMBINATION',
                    'option_id' => (int) $combo['combo_id'],
                    'option_code' => (string) ($combo['combination_key'] ?? ''),
                    'option_name' => (string) ($combo['combination_key'] ?? ''),
                    'option_price' => (int) ($combo['extra_price'] ?? 0),
                    'stock_quantity' => $combo['stock_quantity'] === null ? null : (int) $combo['stock_quantity'],
                ];
            }
            return $result;
        }

        $result = [];
        foreach ($this->options->getByProduct($goodsId) as $group) {
            $option = $group['option'];
            if (!$option->isBasic()) {
                continue;
            }
            foreach ($group['values'] as $value) {
                if (empty($value['is_active']) || (int) ($value['extra_price'] ?? 0) !== $sourcePrice) {
                    continue;
                }
                $optionId = $option->getOptionId();
                $valueId = (int) $value['value_id'];
                $result[] = [
                    'option_mode' => 'SINGLE',
                    'option_id' => $optionId,
                    'option_code' => "opt-{$optionId}-{$valueId}",
                    'option_name' => (string) ($value['value_name'] ?? ''),
                    'option_price' => (int) ($value['extra_price'] ?? 0),
                    'stock_quantity' => $value['stock_quantity'] === null ? null : (int) $value['stock_quantity'],
                ];
            }
        }
        return $result;
    }

    public function request(
        int $domainId,
        string $orderNo,
        int $detailId,
        array $data,
        string $changedBy = 'CUSTOMER',
        ?int $changedById = null,
    ): Result {
        $reasonType = strtoupper(trim((string) ($data['reason_type'] ?? 'OTHER')));
        if (!in_array($reasonType, self::REASON_TYPES, true)) {
            return Result::failure('유효하지 않은 교환 사유입니다.');
        }
        $responsibilityValue = strtoupper((string) ($data['responsibility'] ?? 'MANUAL'));
        if ($changedBy === 'CUSTOMER') {
            // 고객 요청 값은 비용 회피에 악용될 수 있으므로 서버 정책으로 귀책을 확정한다.
            $responsibilityValue = in_array($reasonType, ['DEFECT', 'WRONG_PRODUCT', 'LATE_DELIVERY'], true)
                ? ClaimResponsibility::SELLER->value
                : ClaimResponsibility::CUSTOMER->value;
        }
        $responsibility = ClaimResponsibility::tryFrom($responsibilityValue);
        if ($responsibility === null) {
            return Result::failure('교환 귀책 구분이 올바르지 않습니다.');
        }
        $quantity = (int) ($data['quantity'] ?? 0);
        if ($quantity <= 0) {
            return Result::failure('교환 수량을 입력해주세요.');
        }

        try {
            $created = $this->claims->transaction(function () use (
                $domainId, $orderNo, $detailId, $data, $changedBy, $changedById,
                $reasonType, $responsibility, $quantity
            ): array {
                $item = $this->claims->lockOrderDetailInDomain($domainId, $detailId);
                if ($item === null || (string) $item['order_no'] !== $orderNo) {
                    throw new \DomainException('주문 상품을 찾을 수 없습니다.');
                }
                if ($this->resolveOrderAction($domainId, (string) ($item['status'] ?? '')) !== OrderAction::DELIVERED) {
                    throw new \DomainException('배송 완료된 상품만 교환을 신청할 수 있습니다.');
                }
                if ($this->claims->hasBlockingNonExchangeClaim($domainId, $detailId)) {
                    throw new \DomainException('취소·반품이 처리 중이거나 완료된 상품은 교환을 신청할 수 없습니다.');
                }
                $claimedQuantity = $this->claims->getActiveQuantityForUpdate($domainId, $detailId);
                if ($claimedQuantity + $quantity > (int) ($item['quantity'] ?? 0)) {
                    throw new \DomainException('교환 가능한 수량을 초과했습니다.');
                }

                $target = $this->resolveTarget($item, $data);
                $order = $this->orders->findByOrderNoInDomain($domainId, $orderNo);
                if ($order === null) {
                    throw new \DomainException('주문을 찾을 수 없습니다.');
                }
                $fee = $responsibility === ClaimResponsibility::CUSTOMER
                    ? $this->resolveExchangeFee($order, (int) $item['goods_id'])
                    : 0;
                $feeStatus = $fee > 0 ? 'UNPAID' : 'WAIVED';
                $pickup = $this->pickupSnapshot($order, $data);

                $claimId = $this->claims->createClaim([
                    'domain_id' => $domainId,
                    'order_no' => $orderNo,
                    'order_detail_id' => $detailId,
                    'member_id' => (int) ($order['member_id'] ?? 0),
                    'return_type' => 'EXCHANGE',
                    'return_status' => ClaimStatus::REQUESTED->value,
                    'original_item_status' => (string) ($item['status'] ?? ''),
                    'reason_type' => $reasonType,
                    'reason_detail' => trim((string) ($data['reason_detail'] ?? '')),
                    'responsibility' => $responsibility->value,
                    'quantity' => $quantity,
                    'refund_amount' => 0,
                    'return_shipping_fee' => 0,
                    'exchange_shipping_fee' => $fee,
                    'fee_status' => $feeStatus,
                    'pickup_name' => $pickup['name'],
                    'pickup_phone' => $pickup['phone'],
                    'pickup_zipcode' => $pickup['zipcode'],
                    'pickup_address1' => $pickup['address1'],
                    'pickup_address2' => $pickup['address2'],
                    'requested_at' => date('Y-m-d H:i:s'),
                ]);
                $this->claims->createExchangeItem([
                    'return_id' => $claimId,
                    'source_order_detail_id' => $detailId,
                    'goods_id' => (int) $item['goods_id'],
                    'option_mode' => $target['option_mode'],
                    'option_id' => $target['option_id'],
                    'option_code' => $target['option_code'] ?: null,
                    'option_name' => $target['option_name'] ?: null,
                    'option_price' => $target['option_price'],
                    'quantity' => $quantity,
                ]);
                $this->orders->updateItemReturn($detailId, 'EXCHANGE', ClaimStatus::REQUESTED->value);
                $this->claims->addLog($claimId, $domainId, '', ClaimStatus::REQUESTED->value, $changedBy, $changedById, (string) ($data['reason_detail'] ?? ''));
                return ['claim_id' => $claimId];
            });
        } catch (\DomainException $e) {
            return Result::failure($e->getMessage());
        } catch (\Throwable $e) {
            error_log('[SHOP][EXCHANGE] request failed: ' . $e->getMessage());
            return Result::failure('교환 신청 처리 중 오류가 발생했습니다.');
        }

        $claim = $this->claims->findInDomain($domainId, (int) $created['claim_id']);
        if ($claim !== null) {
            $this->dispatch($claim, '', ClaimStatus::REQUESTED->value);
        }
        return Result::success('교환 신청이 접수되었습니다.', $created);
    }

    public function accept(int $domainId, int $claimId, ?int $staffId, string $reason = ''): Result
    {
        return $this->runTransition($domainId, $claimId, ClaimStatus::ACCEPTED, 'STAFF', $staffId, $reason,
            function (array $claim): array {
                if (!$this->stock->reserveReplacement($claim)) {
                    throw new \DomainException('교환 상품 재고가 부족하거나 예약할 수 없습니다.');
                }
                return ['accepted_at' => date('Y-m-d H:i:s')];
            }
        );
    }

    public function refuse(int $domainId, int $claimId, ?int $staffId, string $reason): Result
    {
        if (trim($reason) === '') {
            return Result::failure('거절 사유를 입력해주세요.');
        }
        return $this->runTransition($domainId, $claimId, ClaimStatus::REFUSED, 'STAFF', $staffId, $reason,
            fn(array $claim): array => ['refused_reason' => $reason, 'completed_at' => date('Y-m-d H:i:s')]
        );
    }

    public function cancel(int $domainId, int $claimId, string $changedBy, ?int $changedById, string $reason = ''): Result
    {
        return $this->runTransition($domainId, $claimId, ClaimStatus::CANCELLED, $changedBy, $changedById, $reason,
            function (array $claim): array {
                if (!$this->stock->releaseReplacement($claim)) {
                    throw new \DomainException('예약 재고를 복구하지 못했습니다.');
                }
                return ['cancelled_at' => date('Y-m-d H:i:s')];
            },
            $changedBy === 'CUSTOMER' ? [ClaimStatus::REQUESTED->value] : null
        );
    }

    public function startCollection(int $domainId, int $claimId, array $shipment, ?int $staffId): Result
    {
        $registeredShipment = null;
        $result = $this->runTransition($domainId, $claimId, ClaimStatus::COLLECTING, 'STAFF', $staffId, '',
            function (array $claim) use ($shipment, &$registeredShipment): array {
                $result = $this->shipments->registerShipment((string) $claim['order_no'], [
                    'order_detail_id' => (int) $claim['order_detail_id'],
                    'claim_id' => (int) $claim['return_id'],
                    'shipment_role' => 'COLLECTION',
                    'company_id' => $shipment['company_id'] ?? null,
                    'invoice_no' => $shipment['invoice_no'] ?? '',
                    'admin_memo' => $shipment['admin_memo'] ?? null,
                ], false);
                if ($result->isFailure()) {
                    throw new \DomainException($result->getMessage());
                }
                $registeredShipment = $result->getData()['shipment'] ?? null;
                return [
                    'collect_courier' => $this->resolveCourierName(isset($shipment['company_id']) ? (int) $shipment['company_id'] : null),
                    'collect_invoice' => trim((string) ($shipment['invoice_no'] ?? '')),
                ];
            }
        );
        if ($result->isSuccess() && is_array($registeredShipment)) {
            $this->shipments->publishRegisteredShipment($registeredShipment);
        }
        return $result;
    }

    public function markCollected(int $domainId, int $claimId, ?int $staffId): Result
    {
        return $this->runTransition($domainId, $claimId, ClaimStatus::COLLECTED, 'STAFF', $staffId, '',
            fn(array $claim): array => ['collected_at' => date('Y-m-d H:i:s')]
        );
    }

    public function startInspection(int $domainId, int $claimId, ?int $staffId): Result
    {
        return $this->runTransition($domainId, $claimId, ClaimStatus::INSPECTING, 'STAFF', $staffId);
    }

    public function inspect(int $domainId, int $claimId, bool $approved, string $inspectionResult, ?int $staffId, string $reason = ''): Result
    {
        $allowed = ['SALEABLE', 'DEFECTIVE', 'DISCARD', 'WRONG_ITEM'];
        if (!in_array($inspectionResult, $allowed, true)) {
            return Result::failure('검수 결과를 선택해주세요.');
        }
        // 거절 건은 회수품을 고객에게 되돌려 보낸다(REJECTED → RETURNING → CLOSED).
        // '정상 재판매' 는 그 동선과 모순이라 거절 사유가 될 수 없다.
        if (!$approved && $inspectionResult === 'SALEABLE') {
            return Result::failure('검수 거절 시에는 정상 재판매를 선택할 수 없습니다. 거절 사유에 맞는 검수 결과를 골라주세요.');
        }
        $target = $approved ? ClaimStatus::READY_TO_SHIP : ClaimStatus::REJECTED;
        return $this->runTransition($domainId, $claimId, $target, 'STAFF', $staffId, $reason,
            function (array $claim) use ($approved, $inspectionResult, $reason): array {
                // 재고를 건드리기 전에 막을 수 있는 것부터 막는다 (롤백 낭비 방지)
                if ($approved && ($claim['fee_status'] ?? '') === 'UNPAID') {
                    throw new \DomainException('교환 배송비 납부 확인 후 재출고 대기로 변경할 수 있습니다.');
                }
                // 승인 건만 회수품을 판매 재고로 되돌린다. 거절 건의 회수품은 고객에게
                // 반송되므로, 재고로 잡으면 없는 물건을 파는 상태가 된다.
                if ($approved && !$this->stock->restockSource($claim, $inspectionResult)) {
                    throw new \DomainException('회수 상품 재고를 처리하지 못했습니다.');
                }
                if (!$approved && !$this->stock->releaseReplacement($claim)) {
                    throw new \DomainException('예약된 교환 상품 재고를 복구하지 못했습니다.');
                }
                $this->claims->updateExchangeItem((int) $claim['return_id'], ['inspection_result' => $inspectionResult]);
                return ['inspected_at' => date('Y-m-d H:i:s'), 'staff_memo' => $reason ?: null];
            }
        );
    }

    public function markFeePaid(int $domainId, int $claimId, string $method, ?int $staffId): Result
    {
        if (!in_array($method, ['BANK', 'COD', 'MANUAL'], true)) {
            return Result::failure('교환비 납부 방법이 올바르지 않습니다.');
        }
        try {
            $updated = $this->claims->transaction(function () use ($domainId, $claimId, $method, $staffId): bool {
                $claim = $this->claims->findForUpdate($domainId, $claimId);
                if ($claim === null || ($claim['return_type'] ?? '') !== 'EXCHANGE') {
                    throw new \DomainException('교환 건을 찾을 수 없습니다.');
                }
                if (($claim['fee_status'] ?? '') === 'PAID') {
                    return true;
                }
                $status = ClaimStatus::tryFrom((string) ($claim['return_status'] ?? ''));
                if (!$status?->isActive() || ($claim['fee_status'] ?? '') !== 'UNPAID') {
                    throw new \DomainException('현재 상태에서는 교환 배송비를 납부 처리할 수 없습니다.');
                }
                $ok = $this->claims->updateClaim($domainId, $claimId, [
                    'fee_status' => 'PAID', 'fee_method' => $method, 'fee_paid_at' => date('Y-m-d H:i:s'),
                ]);
                $this->claims->addLog($claimId, $domainId, (string) $claim['return_status'], (string) $claim['return_status'], 'STAFF', $staffId, '교환 배송비 납부 확인', ['method' => $method]);
                return $ok;
            });
        } catch (\DomainException $e) {
            return Result::failure($e->getMessage());
        }
        return $updated ? Result::success('교환 배송비 납부를 확인했습니다.') : Result::failure('교환비 상태를 변경하지 못했습니다.');
    }

    public function reship(int $domainId, int $claimId, array $shipment, ?int $staffId): Result
    {
        $registeredShipment = null;
        $result = $this->runTransition($domainId, $claimId, ClaimStatus::RESHIPPING, 'STAFF', $staffId, '',
            function (array $claim) use ($shipment, &$registeredShipment): array {
                $result = $this->shipments->registerShipment((string) $claim['order_no'], [
                    'order_detail_id' => (int) $claim['order_detail_id'],
                    'claim_id' => (int) $claim['return_id'],
                    'shipment_role' => 'EXCHANGE_OUTBOUND',
                    'company_id' => $shipment['company_id'] ?? null,
                    'invoice_no' => $shipment['invoice_no'] ?? '',
                    'admin_memo' => $shipment['admin_memo'] ?? null,
                ], false);
                if ($result->isFailure()) {
                    throw new \DomainException($result->getMessage());
                }
                $registeredShipment = $result->getData()['shipment'] ?? null;
                if (!$this->stock->markReplacementShipped($claim)) {
                    throw new \DomainException('교환 상품 재고 예약을 발송 처리하지 못했습니다.');
                }
                return ['reshipped_at' => date('Y-m-d H:i:s')];
            }
        );
        if ($result->isSuccess() && is_array($registeredShipment)) {
            $this->shipments->publishRegisteredShipment($registeredShipment);
        }
        return $result;
    }

    public function complete(int $domainId, int $claimId, ?int $staffId): Result
    {
        return $this->runTransition($domainId, $claimId, ClaimStatus::COMPLETED, 'STAFF', $staffId, '',
            function (array $claim): array {
                $outbound = array_filter(
                    $this->shipments->getByClaimId((int) $claim['return_id']),
                    static fn(array $shipment): bool => ($shipment['shipment_role'] ?? '') === 'EXCHANGE_OUTBOUND'
                );
                $delivered = array_filter($outbound, static fn(array $shipment): bool => ($shipment['shipment_status'] ?? '') === 'DELIVERED');
                if ($outbound === [] || $delivered === []) {
                    throw new \DomainException('교환 상품 배송완료 확인 후 교환을 완료할 수 있습니다.');
                }
                return ['completed_at' => date('Y-m-d H:i:s')];
            }
        );
    }

    public function returnRejected(int $domainId, int $claimId, array $shipment, ?int $staffId): Result
    {
        $registeredShipment = null;
        $result = $this->runTransition($domainId, $claimId, ClaimStatus::RETURNING, 'STAFF', $staffId, '',
            function (array $claim) use ($shipment, &$registeredShipment): array {
                $result = $this->shipments->registerShipment((string) $claim['order_no'], [
                    'order_detail_id' => (int) $claim['order_detail_id'],
                    'claim_id' => (int) $claim['return_id'],
                    'shipment_role' => 'REJECTED_RETURN',
                    'company_id' => $shipment['company_id'] ?? null,
                    'invoice_no' => $shipment['invoice_no'] ?? '',
                    'admin_memo' => $shipment['admin_memo'] ?? null,
                ], false);
                if ($result->isFailure()) {
                    throw new \DomainException($result->getMessage());
                }
                $registeredShipment = $result->getData()['shipment'] ?? null;
                return [];
            }
        );
        if ($result->isSuccess() && is_array($registeredShipment)) {
            $this->shipments->publishRegisteredShipment($registeredShipment);
        }
        return $result;
    }

    public function closeRejected(int $domainId, int $claimId, ?int $staffId): Result
    {
        return $this->runTransition($domainId, $claimId, ClaimStatus::CLOSED, 'STAFF', $staffId, '',
            function (array $claim): array {
                $returns = array_filter(
                    $this->shipments->getByClaimId((int) $claim['return_id']),
                    static fn(array $shipment): bool => ($shipment['shipment_role'] ?? '') === 'REJECTED_RETURN'
                );
                $delivered = array_filter($returns, static fn(array $shipment): bool => ($shipment['shipment_status'] ?? '') === 'DELIVERED');
                if ($returns === [] || $delivered === []) {
                    throw new \DomainException('고객 반송 배송완료 확인 후 종결할 수 있습니다.');
                }
                return ['completed_at' => date('Y-m-d H:i:s')];
            }
        );
    }

    private function runTransition(
        int $domainId,
        int $claimId,
        ClaimStatus $target,
        string $changedBy,
        ?int $changedById,
        string $reason = '',
        ?callable $beforeTransition = null,
        ?array $allowedCurrent = null,
    ): Result {
        try {
            $transition = $this->claims->transaction(function () use (
                $domainId, $claimId, $target, $changedBy, $changedById, $reason, $beforeTransition, $allowedCurrent
            ): array {
                $claim = $this->claims->findForUpdate($domainId, $claimId);
                if ($claim === null || ($claim['return_type'] ?? '') !== 'EXCHANGE') {
                    throw new \DomainException('교환 건을 찾을 수 없습니다.');
                }
                if (empty($claim['exchange_item_id']) && !in_array($target, [ClaimStatus::REFUSED, ClaimStatus::CANCELLED], true)) {
                    throw new \DomainException('기존 교환 데이터에 대상 옵션이 없습니다. 거절 또는 취소 후 다시 접수해주세요.');
                }
                $current = (string) $claim['return_status'];
                if ($allowedCurrent !== null && !in_array($current, $allowedCurrent, true)) {
                    throw new \DomainException('현재 상태에서는 요청한 처리를 할 수 없습니다.');
                }
                if (!$this->stateMachine->canTransition($current, $target->value)) {
                    throw new \DomainException(ClaimStatus::tryFrom($current)?->label() . ' 상태에서 ' . $target->label() . '(으)로 변경할 수 없습니다.');
                }
                $extra = $beforeTransition ? (array) $beforeTransition($claim) : [];
                if (!$this->claims->compareAndSetStatus($domainId, $claimId, $current, $target->value, $extra)) {
                    throw new \DomainException('다른 처리와 충돌했습니다. 새로고침 후 다시 시도해주세요.');
                }
                $this->claims->addLog($claimId, $domainId, $current, $target->value, $changedBy, $changedById, $reason);
                $this->syncItemSummary($domainId, (int) $claim['order_detail_id'], $target);
                return ['previous' => $current, 'claim' => $this->claims->findForUpdate($domainId, $claimId) ?? $claim];
            });
        } catch (\DomainException $e) {
            return Result::failure($e->getMessage());
        } catch (\Throwable $e) {
            error_log('[SHOP][EXCHANGE] transition failed: ' . $e->getMessage());
            return Result::failure('교환 상태 처리 중 오류가 발생했습니다.');
        }

        $this->dispatch($transition['claim'], $transition['previous'], $target->value);
        return Result::success($target->label() . ' 처리가 완료되었습니다.', ['claim_id' => $claimId, 'status' => $target->value]);
    }

    private function syncItemSummary(int $domainId, int $detailId, ClaimStatus $terminalCandidate): void
    {
        $active = $this->claims->getActiveByDetailId($domainId, $detailId);
        if ($active !== []) {
            $this->orders->updateItemReturn($detailId, 'EXCHANGE', (string) $active[0]['return_status']);
            return;
        }
        if ($terminalCandidate === ClaimStatus::COMPLETED) {
            $this->orders->updateItemReturn($detailId, 'EXCHANGE', ClaimStatus::COMPLETED->value);
            return;
        }
        if ($this->claims->hasCompletedExchange($domainId, $detailId)) {
            $this->orders->updateItemReturn($detailId, 'EXCHANGE', ClaimStatus::COMPLETED->value);
            return;
        }
        $this->orders->updateItemReturn($detailId, 'NONE', 'NONE');
    }

    private function resolveTarget(array $item, array $data): array
    {
        $mode = (string) ($item['option_mode'] ?? 'NONE');
        $goodsId = (int) ($item['goods_id'] ?? 0);
        $sourcePrice = (int) ($item['option_price'] ?? 0);
        $optionId = (int) ($data['target_option_id'] ?? 0);
        $optionCode = trim((string) ($data['target_option_code'] ?? ''));

        if ($mode === 'NONE') {
            return ['option_mode' => 'NONE', 'option_id' => 0, 'option_code' => '', 'option_name' => null, 'option_price' => 0];
        }
        if ($mode === 'COMBINATION') {
            $combo = $this->options->findCombo($optionId);
            if ($combo === null || (int) ($combo['goods_id'] ?? 0) !== $goodsId || empty($combo['is_active'])) {
                throw new \DomainException('교환 대상 옵션을 찾을 수 없습니다.');
            }
            if ((int) ($combo['extra_price'] ?? 0) !== $sourcePrice) {
                throw new \DomainException('가격이 다른 옵션은 반품 후 다시 주문해주세요.');
            }
            return [
                'option_mode' => 'COMBINATION', 'option_id' => $optionId,
                'option_code' => (string) ($combo['combination_key'] ?? ''),
                'option_name' => (string) ($combo['combination_key'] ?? ''),
                'option_price' => (int) ($combo['extra_price'] ?? 0),
            ];
        }
        if (!preg_match('/^opt-(\d+)-(\d+)$/', $optionCode, $matches) || (int) $matches[1] !== $optionId) {
            throw new \DomainException('교환 대상 옵션 형식이 올바르지 않습니다.');
        }
        $option = $this->options->findRawOption($optionId);
        $value = $this->options->findValue((int) $matches[2]);
        if ($option === null || (int) ($option['goods_id'] ?? 0) !== $goodsId
            || ($option['option_type'] ?? 'BASIC') !== 'BASIC' || empty($option['is_active'])
            || $value === null || (int) ($value['option_id'] ?? 0) !== $optionId || empty($value['is_active'])) {
            throw new \DomainException('교환 대상 옵션을 찾을 수 없습니다.');
        }
        if ((int) ($value['extra_price'] ?? 0) !== $sourcePrice) {
            throw new \DomainException('가격이 다른 옵션은 반품 후 다시 주문해주세요.');
        }
        return [
            'option_mode' => 'SINGLE', 'option_id' => $optionId, 'option_code' => $optionCode,
            'option_name' => (string) ($value['value_name'] ?? ''), 'option_price' => (int) ($value['extra_price'] ?? 0),
        ];
    }

    private function resolveExchangeFee(array $order, int $goodsId): int
    {
        $breakdown = $order['shipping_breakdown'] ?? [];
        if (is_string($breakdown)) {
            $breakdown = json_decode($breakdown, true);
        }
        foreach ((array) $breakdown as $group) {
            $goodsIds = array_map('intval', (array) ($group['goods_ids'] ?? []));
            if (in_array($goodsId, $goodsIds, true)) {
                return max(0, (int) ($group['exchange_cost'] ?? 0));
            }
        }
        return 0;
    }

    private function pickupSnapshot(array $order, array $data): array
    {
        $plain = [
            'name' => trim((string) ($data['pickup_name'] ?? '')),
            'phone' => trim((string) ($data['pickup_phone'] ?? '')),
            'zipcode' => trim((string) ($data['pickup_zipcode'] ?? '')),
            'address1' => trim((string) ($data['pickup_address1'] ?? '')),
            'address2' => trim((string) ($data['pickup_address2'] ?? '')),
        ];
        return [
            'name' => $plain['name'] !== '' ? $this->encryption->encrypt($plain['name']) : ($order['recipient_name'] ?? null),
            'phone' => $plain['phone'] !== '' ? $this->encryption->encrypt($plain['phone']) : ($order['recipient_phone'] ?? null),
            'zipcode' => $plain['zipcode'] !== '' ? $plain['zipcode'] : ($order['shipping_zip'] ?? null),
            'address1' => $plain['address1'] !== '' ? $this->encryption->encrypt($plain['address1']) : ($order['shipping_address1'] ?? null),
            'address2' => $plain['address2'] !== '' ? $this->encryption->encrypt($plain['address2']) : ($order['shipping_address2'] ?? null),
        ];
    }

    private function decryptClaim(array $claim): array
    {
        foreach (['pickup_name', 'pickup_phone', 'pickup_address1', 'pickup_address2', 'recipient_name', 'recipient_phone', 'shipping_address1', 'shipping_address2'] as $field) {
            if (!empty($claim[$field])) {
                try {
                    $claim[$field] = $this->encryption->decrypt((string) $claim[$field]) ?? '';
                } catch (\Throwable) {
                    $claim[$field] = '';
                }
            }
        }
        return $claim;
    }

    private function resolveOrderAction(int $domainId, string $stateId): ?OrderAction
    {
        $definition = $this->orderStates->getState($domainId, $stateId);
        return $definition ? $this->orderStates->getAction($stateId, $definition) : OrderAction::tryFrom($stateId);
    }

    private function resolveCourierName(?int $companyId): ?string
    {
        if ($companyId === null || $companyId <= 0) {
            return null;
        }
        foreach ($this->shipments->getDeliveryCompanies() as $company) {
            if ((int) ($company['company_id'] ?? 0) === $companyId) {
                return (string) ($company['company_name'] ?? '') ?: null;
            }
        }
        return null;
    }

    private function dispatch(array $claim, string $previous, string $new): void
    {
        // 차단 목록은 새 DB 컬럼이 추가될 때 개인정보를 놓칠 수 있으므로 허용 목록만 공개한다.
        $publicClaim = array_intersect_key($claim, array_flip(self::PUBLIC_CLAIM_FIELDS));
        $this->events?->dispatch(new ClaimStatusChangedEvent(
            (int) $claim['return_id'],
            (int) $claim['domain_id'],
            (string) $claim['order_no'],
            (int) $claim['order_detail_id'],
            $previous,
            $new,
            $publicClaim,
        ));
    }
}
