<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Enum\ClaimReason;
use Mublo\Packages\Shop\Enum\ClaimResponsibility;
use Mublo\Packages\Shop\Enum\ClaimStatus;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Event\ClaimStatusChangedEvent;
use Mublo\Packages\Shop\Repository\ClaimRepository;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Contract\Security\SensitiveValueCodecInterface;

final class ClaimService
{

    /** 이 서비스가 다루는 클레임 유형 (취소는 결제 취소 흐름이 따로 담당한다). */
    public const CLAIM_TYPES = ['EXCHANGE', 'RETURN'];

    public static function typeLabel(string $returnType): string
    {
        return $returnType === 'RETURN' ? '반품' : '교환';
    }

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
        private ?OrderService $orderFlow = null,
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
        $returnType = (string) ($claim['return_type'] ?? 'EXCHANGE');
        $claim['next_statuses'] = array_map(
            static fn(ClaimStatus $status): array => ['value' => $status->value, 'label' => $status->label($returnType)],
            $this->stateMachine->next((string) $claim['return_status'], $returnType)
        );
        return $claim;
    }

    public function getByOrderNo(int $domainId, string $orderNo, ?string $returnType = null): array
    {
        return array_map(function (array $claim) use ($domainId): array {
            $full = $this->get($domainId, (int) $claim['return_id']);
            return $full ?? $claim;
        }, $this->claims->getByOrderNo($domainId, $orderNo, $returnType));
    }

    /**
     * 주문 목록에서 클레임 유무를 한눈에 보이기 위한 일괄 조회.
     *
     * @param string[] $orderNos
     * @return array<string, string> [order_no => EXCHANGE|RETURN|MIXED]
     */
    public function getActiveClaimTypesByOrderNo(int $domainId, array $orderNos): array
    {
        return $this->claims->getActiveClaimTypesByOrderNo($domainId, $orderNos);
    }

    public function getActiveByDetailId(int $domainId, int $detailId, ?string $returnType = null): array
    {
        return $this->claims->getActiveByDetailId($domainId, $detailId, $returnType);
    }

    /**
     * 클레임(반품·교환) 신청 가능 여부 — 프론트 버튼과 서버 접수가 함께 쓰는 단일 규칙.
     *
     * 두 유형의 조건은 같다. 받은 물건이어야 돌려보내든 바꾸든 할 수 있기 때문이다.
     * 주문상품 상태가 우선이고, 아직 배송완료로 따라오지 못한 품목은 주문 헤더로
     * 폴백한다. 부분 배송에서는 먼저 도착한 품목만 신청할 수 있어야 하므로 품목
     * 기준을 버릴 수 없고, 반대로 송장을 쓰지 않는 상점에서는 헤더가 유일한
     * 신호이기 때문이다. 취소·반품·구매확정으로 넘어간 건은 어느 쪽이든 거부한다.
     */
    public function isClaimable(int $domainId, array $item, string $orderStatus): bool
    {
        $itemAction = $this->resolveOrderAction($domainId, (string) ($item['status'] ?? ''));
        if (in_array($itemAction, [
            OrderAction::CONFIRMED,
            OrderAction::CANCEL_REQUESTED,
            OrderAction::CANCELLED,
            OrderAction::RETURN_REQUESTED,
            OrderAction::RETURNED,
        ], true)) {
            return false;
        }
        if ($itemAction === OrderAction::DELIVERED) {
            return true;
        }

        // 품목이 배송 전이어도, 주문 전체가 배송완료면 배송이 끝난 것으로 본다.
        // (구매확정된 주문은 클레임이 닫힌 것으로 보고 폴백을 열지 않는다)
        return $this->resolveOrderAction($domainId, $orderStatus) === OrderAction::DELIVERED;
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

    /**
     * 클레임 접수 (교환 또는 반품).
     *
     * 두 유형은 사유·귀책·수량·회수지를 똑같이 받는다. 갈리는 것은 교환이 대상 옵션과
     * 교환 배송비를 갖는 데 비해, 반품은 환불액과 반품 배송비를 갖는다는 점뿐이다.
     */
    public function request(
        int $domainId,
        string $orderNo,
        int $detailId,
        array $data,
        string $changedBy = 'CUSTOMER',
        ?int $changedById = null,
        string $returnType = 'EXCHANGE',
    ): Result {
        if (!in_array($returnType, self::CLAIM_TYPES, true)) {
            return Result::failure('유효하지 않은 클레임 유형입니다.');
        }
        $typeLabel = self::typeLabel($returnType);
        $reason = ClaimReason::tryFrom(strtoupper(trim((string) ($data['reason_type'] ?? 'OTHER'))));
        if ($reason === null) {
            return Result::failure("유효하지 않은 {$typeLabel} 사유입니다.");
        }
        $reasonType = $reason->value;
        $responsibilityValue = strtoupper((string) ($data['responsibility'] ?? 'MANUAL'));
        if ($changedBy === 'CUSTOMER') {
            // 고객 요청 값은 비용 회피에 악용될 수 있으므로 서버 정책으로 귀책을 확정한다.
            $responsibilityValue = $reason->isSellerFault()
                ? ClaimResponsibility::SELLER->value
                : ClaimResponsibility::CUSTOMER->value;
        }
        $responsibility = ClaimResponsibility::tryFrom($responsibilityValue);
        if ($responsibility === null) {
            return Result::failure('귀책 구분이 올바르지 않습니다.');
        }
        $quantity = (int) ($data['quantity'] ?? 0);
        if ($quantity <= 0) {
            return Result::failure("{$typeLabel} 수량을 입력해주세요.");
        }

        try {
            $created = $this->claims->transaction(function () use (
                $domainId, $orderNo, $detailId, $data, $changedBy, $changedById,
                $reasonType, $responsibility, $quantity, $returnType, $typeLabel
            ): array {
                $item = $this->claims->lockOrderDetailInDomain($domainId, $detailId);
                if ($item === null || (string) $item['order_no'] !== $orderNo) {
                    throw new \DomainException('주문 상품을 찾을 수 없습니다.');
                }
                // 프론트 버튼과 같은 규칙으로 판정한다 (품목 우선 · 헤더 폴백).
                // 품목만으로 결론이 나면 주문 헤더는 읽지 않는다.
                $order = null;
                $claimable = $this->isClaimable($domainId, $item, '');
                if (!$claimable) {
                    $order = $this->orders->findByOrderNoInDomain($domainId, $orderNo);
                    $claimable = $order !== null
                        && $this->isClaimable($domainId, $item, (string) ($order['order_status'] ?? ''));
                }
                if (!$claimable) {
                    throw new \DomainException("배송 완료된 상품만 {$typeLabel}을(를) 신청할 수 있습니다.");
                }
                // 교환과 반품을 한 줄에 섞을 수 있다 — 3개 중 2개는 불량이라 교환하고
                // 1개는 필요 없어져 반품하는 일은 실제로 일어난다. 서로 다른 개체이므로
                // 충돌하지 않으며, 겹침은 아래 수량 회계가 막는다(취소는 늘 전량이라
                // 그 수량이 통째로 잡히고 품목 상태도 취소로 바뀐다).
                $claimedQuantity = $this->claims->getActiveQuantityForUpdate($domainId, $detailId);
                if ($claimedQuantity + $quantity > (int) ($item['quantity'] ?? 0)) {
                    throw new \DomainException("{$typeLabel} 가능한 수량을 초과했습니다.");
                }

                $target = $returnType === 'EXCHANGE' ? $this->resolveTarget($item, $data) : null;
                $order ??= $this->orders->findByOrderNoInDomain($domainId, $orderNo);
                if ($order === null) {
                    throw new \DomainException('주문을 찾을 수 없습니다.');
                }

                // 고객 귀책일 때만 비용을 물린다. 교환비는 따로 받아야 하므로 납부 상태를
                // 두지만, 반품비는 환불액에서 빼므로 따로 받을 것이 없다.
                $customerFault = $responsibility === ClaimResponsibility::CUSTOMER;
                $exchangeFee = 0;
                $returnFee = 0;
                $refundAmount = 0;
                $feeStatus = 'WAIVED';
                if ($returnType === 'EXCHANGE') {
                    $exchangeFee = $customerFault ? $this->resolveGroupCost($order, (int) $item['goods_id'], 'exchange_cost') : 0;
                    $feeStatus = $exchangeFee > 0 ? 'UNPAID' : 'WAIVED';
                } else {
                    $returnFee = $customerFault ? $this->resolveGroupCost($order, (int) $item['goods_id'], 'return_cost') : 0;
                    $unitPrice = (int) ($item['goods_price'] ?? 0) + (int) ($item['option_price'] ?? 0);
                    $refundAmount = max(0, $unitPrice * $quantity - $returnFee);
                }
                $pickup = $this->pickupSnapshot($order, $data);

                $claimId = $this->claims->createClaim([
                    'domain_id' => $domainId,
                    'order_no' => $orderNo,
                    'order_detail_id' => $detailId,
                    'member_id' => (int) ($order['member_id'] ?? 0),
                    'return_type' => $returnType,
                    'return_status' => ClaimStatus::REQUESTED->value,
                    'original_item_status' => (string) ($item['status'] ?? ''),
                    'reason_type' => $reasonType,
                    'reason_detail' => trim((string) ($data['reason_detail'] ?? '')),
                    'responsibility' => $responsibility->value,
                    'quantity' => $quantity,
                    'refund_amount' => $refundAmount,
                    'return_shipping_fee' => $returnFee,
                    'exchange_shipping_fee' => $exchangeFee,
                    'fee_status' => $feeStatus,
                    'pickup_name' => $pickup['name'],
                    'pickup_phone' => $pickup['phone'],
                    'pickup_zipcode' => $pickup['zipcode'],
                    'pickup_address1' => $pickup['address1'],
                    'pickup_address2' => $pickup['address2'],
                    'requested_at' => date('Y-m-d H:i:s'),
                ]);
                // 대상 옵션은 교환에만 있다. 반품은 돌려받고 끝이라 바꿔 보낼 상품이 없다.
                if ($target !== null) {
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
                }
                $this->orders->updateItemReturn($detailId, $returnType, ClaimStatus::REQUESTED->value);
                $this->claims->addLog($claimId, $domainId, '', ClaimStatus::REQUESTED->value, $changedBy, $changedById, (string) ($data['reason_detail'] ?? ''));
                return ['claim_id' => $claimId];
            });
        } catch (\DomainException $e) {
            return Result::failure($e->getMessage());
        } catch (\Throwable $e) {
            error_log('[SHOP][CLAIM] request failed: ' . $e->getMessage());
            return Result::failure("{$typeLabel} 신청 처리 중 오류가 발생했습니다.");
        }

        $claim = $this->claims->findInDomain($domainId, (int) $created['claim_id']);
        if ($claim !== null) {
            $this->dispatch($claim, '', ClaimStatus::REQUESTED->value);
        }
        return Result::success("{$typeLabel} 신청이 접수되었습니다.", $created);
    }

    public function accept(int $domainId, int $claimId, ?int $staffId, string $reason = ''): Result
    {
        return $this->runTransition($domainId, $claimId, ClaimStatus::ACCEPTED, 'STAFF', $staffId, $reason,
            function (array $claim): array {
                // 바꿔 보낼 상품을 잡아두는 일이라 교환에만 있다. 반품은 돌려받고 끝이다.
                if (($claim['return_type'] ?? '') === 'EXCHANGE' && !$this->stock->reserveReplacement($claim)) {
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
                if (($claim['return_type'] ?? '') === 'EXCHANGE' && !$this->stock->releaseReplacement($claim)) {
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
        // 거절은 회수품을 고객에게 되돌려 보내는 처리다. 이유가 없으면 고객도 운영자도
        // 나중에 왜 거절됐는지 알 수 없다 (신청 거절 refuse() 와 같은 규칙).
        if (!$approved && trim($reason) === '') {
            return Result::failure('검수 거절 사유를 입력해주세요.');
        }
        // 승인 이후 갈래는 유형이 정한다. 교환은 바꿔 보내고, 반품은 환불한다.
        $claim = $this->claims->findInDomain($domainId, $claimId);
        if ($claim === null) {
            return Result::failure('클레임 건을 찾을 수 없습니다.');
        }
        $isExchange = ($claim['return_type'] ?? '') === 'EXCHANGE';
        $target = $approved
            ? ($isExchange ? ClaimStatus::READY_TO_SHIP : ClaimStatus::READY_TO_REFUND)
            : ClaimStatus::REJECTED;

        return $this->runTransition($domainId, $claimId, $target, 'STAFF', $staffId, $reason,
            function (array $claim) use ($approved, $inspectionResult, $reason, $isExchange): array {
                // 재고를 건드리기 전에 막을 수 있는 것부터 막는다 (롤백 낭비 방지).
                // 교환비는 따로 받아야 하지만 반품비는 환불액에서 빼므로 이 관문이 없다.
                if ($approved && $isExchange && ($claim['fee_status'] ?? '') === 'UNPAID') {
                    throw new \DomainException('교환 배송비 납부 확인 후 재출고 대기로 변경할 수 있습니다.');
                }
                // 승인 건만 회수품을 판매 재고로 되돌린다. 거절 건의 회수품은 고객에게
                // 반송되므로, 재고로 잡으면 없는 물건을 파는 상태가 된다.
                if ($approved && !$this->stock->restockSource($claim, $inspectionResult)) {
                    throw new \DomainException('회수 상품 재고를 처리하지 못했습니다.');
                }
                if (!$approved && $isExchange && !$this->stock->releaseReplacement($claim)) {
                    throw new \DomainException('예약된 교환 상품 재고를 복구하지 못했습니다.');
                }
                return [
                    'inspected_at' => date('Y-m-d H:i:s'),
                    'staff_memo' => $reason ?: null,
                    'inspection_result' => $inspectionResult,
                ];
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

    /**
     * 반품 환불 완료 확인 (환불대기 → 반품완료).
     *
     * 실제 환불은 환불 처리 화면(RefundService)이 수행한다. 돈이 나가는 동작을
     * 클레임 상태 변경에 딸려 자동 실행하지 않는다 — 교환의 '교환비 납부 확인'과
     * 같은 방식으로, 관리자가 환불한 사실을 여기서 확정한다.
     */
    public function completeRefund(int $domainId, int $claimId, ?int $staffId, string $reason = ''): Result
    {
        return $this->runTransition($domainId, $claimId, ClaimStatus::COMPLETED, 'STAFF', $staffId, $reason,
            function (array $claim): array {
                if (($claim['return_type'] ?? '') !== 'RETURN') {
                    throw new \DomainException('반품 건에서만 환불 완료로 처리할 수 있습니다.');
                }
                return [
                    'refunded_at' => date('Y-m-d H:i:s'),
                    'completed_at' => date('Y-m-d H:i:s'),
                ];
            }
        );
    }

    /** 클레임 상세가 환불 실행에 필요한 값만 추린다. */
    public function getRefundContext(int $domainId, int $claimId): ?array
    {
        $claim = $this->claims->findInDomain($domainId, $claimId);
        if ($claim === null || ($claim['return_type'] ?? '') !== 'RETURN') {
            return null;
        }
        return [
            'order_no' => (string) ($claim['order_no'] ?? ''),
            'refund_amount' => (int) ($claim['refund_amount'] ?? 0),
            'status' => (string) ($claim['return_status'] ?? ''),
        ];
    }

    public function reship(int $domainId, int $claimId, array $shipment, ?int $staffId): Result
    {
        $registeredShipment = null;
        $result = $this->runTransition($domainId, $claimId, ClaimStatus::RESHIPPING, 'STAFF', $staffId, '',
            function (array $claim) use ($shipment, &$registeredShipment): array {
                if (($claim['return_type'] ?? '') !== 'EXCHANGE') {
                    throw new \DomainException('재출고는 교환 건에서만 할 수 있습니다.');
                }
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
                $returnType = (string) ($claim['return_type'] ?? '');
                if ($claim === null || !in_array($returnType, self::CLAIM_TYPES, true)) {
                    throw new \DomainException('클레임 건을 찾을 수 없습니다.');
                }
                // 대상 옵션은 교환에만 필요하다. 반품은 바꿔 보낼 상품이 없다.
                if ($returnType === 'EXCHANGE'
                    && empty($claim['exchange_item_id'])
                    && !in_array($target, [ClaimStatus::REFUSED, ClaimStatus::CANCELLED], true)
                ) {
                    throw new \DomainException('기존 교환 데이터에 대상 옵션이 없습니다. 거절 또는 취소 후 다시 접수해주세요.');
                }
                $current = (string) $claim['return_status'];
                if ($allowedCurrent !== null && !in_array($current, $allowedCurrent, true)) {
                    throw new \DomainException('현재 상태에서는 요청한 처리를 할 수 없습니다.');
                }
                if (!$this->stateMachine->canTransition($current, $target->value, $returnType)) {
                    throw new \DomainException(
                        ClaimStatus::tryFrom($current)?->label($returnType)
                        . ' 상태에서 ' . $target->label($returnType) . '(으)로 변경할 수 없습니다.'
                    );
                }
                $extra = $beforeTransition ? (array) $beforeTransition($claim) : [];
                if (!$this->claims->compareAndSetStatus($domainId, $claimId, $current, $target->value, $extra)) {
                    throw new \DomainException('다른 처리와 충돌했습니다. 새로고침 후 다시 시도해주세요.');
                }
                $this->claims->addLog($claimId, $domainId, $current, $target->value, $changedBy, $changedById, $reason);
                $this->syncItemSummary($domainId, (int) $claim['order_detail_id'], $target, $returnType);
                return [
                    'previous' => $current,
                    'return_type' => $returnType,
                    'claim' => $this->claims->findForUpdate($domainId, $claimId) ?? $claim,
                ];
            });
        } catch (\DomainException $e) {
            return Result::failure($e->getMessage());
        } catch (\Throwable $e) {
            error_log('[SHOP][CLAIM] transition failed: ' . $e->getMessage());
            return Result::failure('클레임 상태 처리 중 오류가 발생했습니다.');
        }

        $this->dispatch($transition['claim'], $transition['previous'], $target->value);
        return Result::success(
            $target->label((string) $transition['return_type']) . ' 처리가 완료되었습니다.',
            ['claim_id' => $claimId, 'status' => $target->value]
        );
    }

    private function syncItemSummary(int $domainId, int $detailId, ClaimStatus $terminalCandidate, string $returnType): void
    {
        $active = $this->claims->getActiveByDetailId($domainId, $detailId);
        if ($active !== []) {
            $this->orders->updateItemReturn(
                $detailId,
                (string) ($active[0]['return_type'] ?? $returnType),
                (string) $active[0]['return_status']
            );
            return;
        }
        if ($terminalCandidate === ClaimStatus::COMPLETED
            || $this->claims->hasCompletedClaim($domainId, $detailId, $returnType)
        ) {
            $this->orders->updateItemReturn($detailId, $returnType, ClaimStatus::COMPLETED->value);
            $this->markFullyReturnedItem($domainId, $detailId, $returnType);
            return;
        }
        $this->orders->updateItemReturn($detailId, 'NONE', 'NONE');
    }

    /**
     * 주문한 수량 전부가 반품 완료됐으면 주문상품도 반품완료로 옮긴다.
     *
     * 클레임은 수량 단위라 부분 반품에서는 줄 전체를 반품완료로 만들 수 없다. 하지만
     * 전량이 돌아왔는데도 품목이 배송완료로 남으면, 돌려보낸 상품을 고객이 구매확정할
     * 수 있고 주문 상태 종합도 그 품목을 살아 있는 것으로 센다.
     * (부분 반품은 남은 수량이 있으므로 배송완료 그대로 둔다)
     */
    private function markFullyReturnedItem(int $domainId, int $detailId, string $returnType): void
    {
        if ($returnType !== 'RETURN' || $this->orderFlow === null) {
            return;
        }
        $item = $this->orders->getItemInDomain($domainId, $detailId);
        $orderedQuantity = (int) ($item['quantity'] ?? 0);
        if ($orderedQuantity <= 0) {
            return;
        }
        if ($this->claims->getCompletedQuantity($domainId, $detailId, 'RETURN') < $orderedQuantity) {
            return;
        }
        // 상태 전이는 OrderService 를 거친다 — 이력·이벤트·주문 상태 종합이 함께 돌아야 한다
        $this->orderFlow->advanceItemsToAction(
            (string) ($item['order_no'] ?? ''),
            $domainId,
            OrderAction::RETURNED,
            [$detailId],
            '반품 완료',
            'STAFF',
        );
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

    /**
     * 주문에 얼린 배송비 그룹에서 교환비·반품비를 꺼낸다.
     *
     * 주문 당시 값이므로 이후 배송 템플릿을 고쳐도 접수된 클레임의 비용은 변하지 않는다.
     */
    private function resolveGroupCost(array $order, int $goodsId, string $costKey): int
    {
        $breakdown = $order['shipping_breakdown'] ?? [];
        if (is_string($breakdown)) {
            $breakdown = json_decode($breakdown, true);
        }
        foreach ((array) $breakdown as $group) {
            $goodsIds = array_map('intval', (array) ($group['goods_ids'] ?? []));
            if (in_array($goodsId, $goodsIds, true)) {
                return max(0, (int) ($group[$costKey] ?? 0));
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
            'zipcode' => $plain['zipcode'] !== '' ? $this->encryption->encrypt($plain['zipcode']) : ($order['shipping_zip'] ?? null),
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
        // 우편번호는 암호화 정합을 맞추기 전에 평문으로 저장된 값이 남아 있을 수 있다.
        // 복호화에 실패하면 지우지 말고 원본을 그대로 보여준다.
        foreach (['pickup_zipcode', 'shipping_zip'] as $field) {
            if (!empty($claim[$field])) {
                try {
                    $claim[$field] = $this->encryption->decrypt((string) $claim[$field]) ?: $claim[$field];
                } catch (\Throwable) {
                    // 평문 레거시 값 — 그대로 둔다
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
