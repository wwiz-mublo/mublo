<?php

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
    ) {
        $this->shipmentRepository = $shipmentRepository;
        $this->orderRepository    = $orderRepository;
    }

    /**
     * 주문에 송장 등록
     */
    public function registerShipment(string $orderNo, array $data, bool $publishEvent = true): Result
    {
        if (empty($data['invoice_no'])) {
            return Result::failure('송장번호를 입력해주세요.');
        }

        $insertData = [
            'order_no'         => $orderNo,
            'order_detail_id'  => isset($data['order_detail_id']) ? (int) $data['order_detail_id'] : null,
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
    public function getByOrderNo(string $orderNo): array
    {
        $shipments = $this->shipmentRepository->getByOrderNo($orderNo);

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
