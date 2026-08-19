<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Repository\ShipmentRepository;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Packages\Shop\Event\ShipmentRegisteredEvent;
use Mublo\Packages\Shop\Event\ShipmentStatusChangedEvent;
use Mublo\Packages\Shop\Event\ShipmentDeletedEvent;

/**
 * ShipmentService
 *
 * 배송 추적 비즈니스 로직
 *
 * shop_shipments 테이블은 주문의 실제 택배 배송 정보를 저장합니다.
 * 주문 상태(order_status)와는 별개로 택배사/송장번호/배송 상태를 관리하며,
 * 택배 추적 URL 생성, 배송 상태 업데이트 등을 담당합니다.
 *
 * 책임:
 * - 송장 등록 및 수정
 * - 배송 상태 업데이트 (READY → PICKED_UP → IN_TRANSIT → DELIVERED)
 * - 주문별 배송 정보 조회
 * - 택배 추적 URL 생성
 */
class ShipmentService
{
    private ShipmentRepository $shipmentRepository;
    private ?OrderRepository $orderRepository;

    /** 허용 배송 상태 전이 맵 */
    private const ALLOWED_TRANSITIONS = [
        'READY'      => ['PICKED_UP', 'FAILED'],
        'PICKED_UP'  => ['IN_TRANSIT', 'FAILED'],
        'IN_TRANSIT' => ['DELIVERED', 'FAILED'],
        'DELIVERED'  => [],
        'FAILED'     => ['READY'],
    ];

    public function __construct(
        ShipmentRepository $shipmentRepository,
        ?OrderRepository $orderRepository = null,
        private ?EventDispatcher $eventDispatcher = null,
        private ?ShipmentGroupResolver $groupResolver = null,
    ) {
        $this->shipmentRepository = $shipmentRepository;
        $this->orderRepository    = $orderRepository;
    }

    /**
     * 주문에 송장 등록
     *
     * shipping_group_key를 주면 그 배송비 그룹에 귀속시킨다. 배송지가 하나여도
     * 그룹이 다르면 출고지가 다르므로(그룹마다 반품지가 따로 스냅샷된다) 송장은
     * 그룹 단위가 기본이다. 지정이 없으면 종전처럼 주문 전체 묶음배송으로 본다.
     */
    public function registerShipment(string $orderNo, array $data, bool $publishEvent = true): Result
    {
        if (empty($data['invoice_no'])) {
            return Result::failure('송장번호를 입력해주세요.');
        }

        $groupKey = trim((string) ($data['shipping_group_key'] ?? ''));
        if ($groupKey !== '' && !$this->isKnownGroupKey($orderNo, $groupKey)) {
            return Result::failure('주문에 없는 배송 그룹입니다.');
        }

        $insertData = [
            'order_no'         => $orderNo,
            'order_detail_id'  => isset($data['order_detail_id']) ? (int) $data['order_detail_id'] : null,
            'shipping_group_key' => $groupKey !== '' ? $groupKey : null,
            'claim_id'         => isset($data['claim_id']) ? (int) $data['claim_id'] : null,
            'shipment_role'    => $this->normalizeShipmentRole((string) ($data['shipment_role'] ?? 'ORIGINAL')),
            'company_id'       => isset($data['company_id']) ? (int) $data['company_id'] : null,
            'invoice_no'       => trim($data['invoice_no']),
            'shipment_status'  => 'READY',
            'admin_memo'       => $data['admin_memo'] ?? null,
        ];

        $shipmentId = $this->shipmentRepository->create($insertData);
        if (!$shipmentId) {
            return Result::failure('송장 등록에 실패했습니다.');
        }

        $shipment = ['shipment_id' => $shipmentId] + $insertData;
        if ($publishEvent) {
            $this->publishRegisteredShipment($shipment);
        }

        return Result::success('송장이 등록되었습니다.', [
            'shipment_id' => $shipmentId,
            'shipment' => $shipment,
        ]);
    }

    /** 트랜잭션 밖에서 송장 등록 사후 이벤트를 발행한다. */
    public function publishRegisteredShipment(array $shipment): void
    {
        $shipmentId = (int) ($shipment['shipment_id'] ?? 0);
        $orderNo = (string) ($shipment['order_no'] ?? '');
        if ($shipmentId <= 0 || $orderNo === '') {
            return;
        }
        $this->eventDispatcher?->dispatch(new ShipmentRegisteredEvent($shipmentId, $orderNo, $shipment));
    }

    /**
     * 운송장 엑셀 일괄 등록
     *
     * 파싱된 행들을 순회하며 송장을 등록한다. 택배사명은 활성 택배사 목록에서
     * company_id로 매핑하고, order_no는 도메인 소유 검증된 집합(validOrderNos)에
     * 있는 것만 처리한다(교차 도메인/미존재 주문 주입 방지).
     *
     * @param array $rows [['order_no'=>string, 'courier'=>string, 'invoice_no'=>string], ...]
     * @param array $validOrderNos 현재 도메인 소유로 확인된 주문번호 목록
     * @return array{success:int, failed:array<int,array{line:int,order_no:string,reason:string}>, total:int}
     */
    public function bulkRegisterFromRows(array $rows, array $validOrderNos): array
    {
        $companyByName = [];
        foreach ($this->getDeliveryCompanies() as $company) {
            $name = trim((string) ($company['company_name'] ?? ''));
            if ($name !== '') {
                $companyByName[$name] = (int) ($company['company_id'] ?? 0);
            }
        }

        $validSet = array_fill_keys(array_map('strval', $validOrderNos), true);

        $success = 0;
        $failed = [];

        foreach ($rows as $i => $row) {
            $line = (int) $i + 1;
            $orderNo = trim((string) ($row['order_no'] ?? ''));
            $invoice = trim((string) ($row['invoice_no'] ?? ''));
            $courier = trim((string) ($row['courier'] ?? ''));

            // 완전 빈 행은 건너뜀
            if ($orderNo === '' && $invoice === '' && $courier === '') {
                continue;
            }
            if ($orderNo === '') {
                $failed[] = ['line' => $line, 'order_no' => '', 'reason' => '주문번호 없음'];
                continue;
            }
            if (!isset($validSet[$orderNo])) {
                $failed[] = ['line' => $line, 'order_no' => $orderNo, 'reason' => '존재하지 않거나 접근할 수 없는 주문'];
                continue;
            }
            if ($invoice === '') {
                $failed[] = ['line' => $line, 'order_no' => $orderNo, 'reason' => '송장번호 없음'];
                continue;
            }

            $companyId = ($courier !== '' && isset($companyByName[$courier])) ? $companyByName[$courier] : null;

            $result = $this->registerShipment($orderNo, [
                'invoice_no' => $invoice,
                'company_id' => $companyId,
            ]);
            if ($result->isSuccess()) {
                $success++;
            } else {
                $failed[] = ['line' => $line, 'order_no' => $orderNo, 'reason' => $result->getMessage()];
            }
        }

        return ['success' => $success, 'failed' => $failed, 'total' => $success + count($failed)];
    }

    /**
     * 주문별 배송 정보 조회
     */
    public function getByOrderNo(string $orderNo, bool $includeClaims = false): array
    {
        $shipments = $this->shipmentRepository->getByOrderNo($orderNo, $includeClaims);

        return array_map(function (array $s) {
            $s['tracking_url'] = $this->buildTrackingUrl(
                $s['tracking_url_template'] ?? null,
                $s['invoice_no'] ?? ''
            );
            return $s;
        }, $shipments);
    }

    public function getByClaimId(int $claimId): array
    {
        return $this->withTrackingUrls($this->shipmentRepository->getByClaimId($claimId));
    }

    /**
     * 배송 상태 업데이트
     */
    public function updateStatus(
        int $shipmentId,
        string $newStatus,
        ?string $expectedOrderNo = null,
        ?int $expectedClaimId = null,
    ): Result
    {
        $allowedStatuses = ['READY', 'PICKED_UP', 'IN_TRANSIT', 'DELIVERED', 'FAILED'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            return Result::failure('유효하지 않은 배송 상태입니다.');
        }

        $shipment = $this->shipmentRepository->find($shipmentId);
        if (!$shipment) {
            return Result::failure('배송 정보를 찾을 수 없습니다.');
        }
        if ($expectedOrderNo !== null && (string) ($shipment['order_no'] ?? '') !== $expectedOrderNo) {
            return Result::failure('배송 정보를 찾을 수 없습니다.');
        }
        if ($expectedClaimId !== null && (int) ($shipment['claim_id'] ?? 0) !== $expectedClaimId) {
            return Result::failure('배송 정보를 찾을 수 없습니다.');
        }
        if ($expectedOrderNo !== null && $expectedClaimId === null && !empty($shipment['claim_id'])) {
            return Result::failure('교환 운송장은 교환 관리에서 처리해주세요.');
        }

        $currentStatus = $shipment['shipment_status'];
        $allowed       = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            return Result::failure("'{$currentStatus}' 상태에서 '{$newStatus}'으로 변경할 수 없습니다.");
        }

        if ($this->shipmentRepository->updateStatus($shipmentId, $newStatus) <= 0) {
            return Result::failure('배송 상태 업데이트에 실패했습니다.');
        }

        $this->eventDispatcher?->dispatch(new ShipmentStatusChangedEvent(
            $shipmentId,
            (string) ($shipment['order_no'] ?? ''),
            (string) $currentStatus,
            $newStatus,
            $shipment,
        ));
        return Result::success('배송 상태가 업데이트되었습니다.');
    }

    /**
     * 송장 정보 수정 (송장번호, 택배사, 메모)
     */
    public function updateShipment(int $shipmentId, array $data, ?string $expectedOrderNo = null): Result
    {
        $shipment = $this->shipmentRepository->find($shipmentId);
        if (!$shipment) {
            return Result::failure('배송 정보를 찾을 수 없습니다.');
        }
        if ($expectedOrderNo !== null && (string) ($shipment['order_no'] ?? '') !== $expectedOrderNo) {
            return Result::failure('배송 정보를 찾을 수 없습니다.');
        }
        if ($expectedOrderNo !== null && !empty($shipment['claim_id'])) {
            return Result::failure('교환 운송장은 교환 관리에서 처리해주세요.');
        }

        $allowed   = ['company_id', 'invoice_no', 'admin_memo'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if (isset($updateData['invoice_no'])) {
            $updateData['invoice_no'] = trim($updateData['invoice_no']);
            if (empty($updateData['invoice_no'])) {
                return Result::failure('송장번호를 입력해주세요.');
            }
        }

        if ($updateData === []) {
            return Result::failure('수정할 배송 정보가 없습니다.');
        }
        if ($this->shipmentRepository->update($shipmentId, $updateData) <= 0) {
            return Result::failure('배송 정보 수정에 실패했습니다.');
        }
        return Result::success('배송 정보가 수정되었습니다.');
    }

    /**
     * 송장 삭제
     */
    public function deleteShipment(int $shipmentId, ?string $expectedOrderNo = null): Result
    {
        $shipment = $this->shipmentRepository->find($shipmentId);
        if (!$shipment) {
            return Result::failure('배송 정보를 찾을 수 없습니다.');
        }
        if ($expectedOrderNo !== null && (string) ($shipment['order_no'] ?? '') !== $expectedOrderNo) {
            return Result::failure('배송 정보를 찾을 수 없습니다.');
        }
        if ($expectedOrderNo !== null && !empty($shipment['claim_id'])) {
            return Result::failure('교환 운송장은 교환 관리에서 처리해주세요.');
        }

        if ($this->shipmentRepository->delete($shipmentId) <= 0) {
            return Result::failure('배송 정보 삭제에 실패했습니다.');
        }
        $this->eventDispatcher?->dispatch(new ShipmentDeletedEvent(
            $shipmentId,
            (string) ($shipment['order_no'] ?? ''),
            $shipment,
        ));
        return Result::success('배송 정보가 삭제되었습니다.');
    }

    /**
     * 활성 택배사 목록 (송장 입력 드롭다운용)
     */
    public function getDeliveryCompanies(): array
    {
        return $this->shipmentRepository->getActiveCompanies();
    }

    /**
     * 운송장이 등록된 주문번호 집합 (목록 화면 일괄 표시용)
     *
     * @param string[] $orderNos
     * @return string[]
     */
    public function getShippedOrderNos(array $orderNos): array
    {
        return $this->shipmentRepository->getOrderNosWithShipments($orderNos);
    }

    /**
     * 택배 추적 URL 생성
     *
     * 두 가지 템플릿 형식을 모두 지원한다:
     * - 치환형: "https://trace.carrier.com/tracking/{invoice_no}"
     * - 프리픽스형(시드 데이터): "https://kdexp.com/...?barcode=" → 끝에 송장번호 append
     */
    private function buildTrackingUrl(?string $template, string $invoiceNo): ?string
    {
        $template = $template !== null ? trim($template) : '';
        if ($template === '' || $invoiceNo === '') {
            return null;
        }
        if (strpos($template, '{invoice_no}') !== false) {
            return str_replace('{invoice_no}', urlencode($invoiceNo), $template);
        }
        // 프리픽스형: 송장번호를 뒤에 붙인다
        return $template . urlencode($invoiceNo);
    }

    /**
     * 주문의 배송비 그룹 목록 (관리자 송장 입력 단위).
     *
     * 그룹이 하나뿐인 주문은 빈 배열을 돌려준다 — 쪼갤 것이 없으면 선택을
     * 시키지 않는 편이 낫다.
     */
    public function getShippingGroups(string $orderNo): array
    {
        if ($this->orderRepository === null || $this->groupResolver === null) {
            return [];
        }
        $order = $this->orderRepository->find($orderNo);
        if ($order === null) {
            return [];
        }
        $groups = $this->groupResolver->resolve($order->toArray(), $this->orderRepository->getItems($orderNo));
        return count($groups) > 1 ? $groups : [];
    }

    /**
     * 송장별 포함 상품명 (관리자·고객 화면 공통).
     *
     * 출고 그룹이 하나뿐이면 모든 송장이 주문 전체를 싣고 있다는 뜻이라 빈 배열을
     * 돌려준다 — 상품명을 따로 붙여봐야 같은 말의 반복이다.
     *
     * @return array<int, string[]> [shipment_id => 상품명 목록]
     */
    public function itemNamesByShipment(string $orderNo, array $shipments): array
    {
        if ($this->orderRepository === null || $this->groupResolver === null || $shipments === []) {
            return [];
        }
        $order = $this->orderRepository->find($orderNo);
        if ($order === null) {
            return [];
        }
        $orderArray = $order->toArray();
        $items = $this->orderRepository->getItems($orderNo);
        if (count($this->groupResolver->resolve($orderArray, $items)) < 2) {
            return [];
        }

        $nameByDetailId = [];
        foreach ($items as $item) {
            $nameByDetailId[(int) ($item['order_detail_id'] ?? 0)] = trim((string) ($item['goods_name'] ?? ''));
        }

        $names = [];
        foreach ($shipments as $shipment) {
            $shipmentId = (int) ($shipment['shipment_id'] ?? 0);
            if ($shipmentId <= 0 || !empty($shipment['claim_id'])) {
                continue;
            }
            $detailIds = $this->groupResolver->detailIdsForShipment($shipment, $orderArray, $items);
            $names[$shipmentId] = array_values(array_filter(array_map(
                static fn(int $detailId): string => $nameByDetailId[$detailId] ?? '',
                $detailIds
            )));
        }
        return $names;
    }

    private function isKnownGroupKey(string $orderNo, string $groupKey): bool
    {
        if ($this->orderRepository === null || $this->groupResolver === null) {
            return false;
        }
        $order = $this->orderRepository->find($orderNo);
        if ($order === null) {
            return false;
        }
        return $this->groupResolver->hasGroupKey(
            $order->toArray(),
            $this->orderRepository->getItems($orderNo),
            $groupKey
        );
    }

    private function normalizeShipmentRole(string $role): string
    {
        return in_array($role, ['ORIGINAL', 'COLLECTION', 'EXCHANGE_OUTBOUND', 'REJECTED_RETURN'], true)
            ? $role
            : 'ORIGINAL';
    }

    private function withTrackingUrls(array $shipments): array
    {
        return array_map(function (array $shipment): array {
            $shipment['tracking_url'] = $this->buildTrackingUrl(
                $shipment['tracking_url_template'] ?? null,
                (string) ($shipment['invoice_no'] ?? '')
            );
            return $shipment;
        }, $shipments);
    }
}
