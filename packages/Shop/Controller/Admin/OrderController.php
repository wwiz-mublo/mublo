<?php
declare(strict_types=1);
namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\OrderFieldService;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Packages\Shop\Service\RefundService;
use Mublo\Packages\Shop\Service\OrderMemoService;
use Mublo\Packages\Shop\Service\ShipmentService;
use Mublo\Packages\Shop\Service\ClaimService;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Infrastructure\Storage\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Admin OrderController
 *
 * 주문 관리 컨트롤러 (FSM 기반 + 환불 + 메모)
 */
class OrderController
{
    private OrderService $orderService;
    private OrderFieldService $orderFieldService;
    private OrderStateResolver $stateResolver;
    private RefundService $refundService;
    private OrderMemoService $memoService;
    private AuthContextInterface $authService;
    private ?ShipmentService $shipmentService;
    private ?ClaimService $claimService;

    public function __construct(
        OrderService $orderService,
        OrderFieldService $orderFieldService,
        OrderStateResolver $stateResolver,
        RefundService $refundService,
        OrderMemoService $memoService,
        AuthContextInterface $authService,
        ?ShipmentService $shipmentService = null,
        ?ClaimService $claimService = null
    ) {
        $this->orderService = $orderService;
        $this->orderFieldService = $orderFieldService;
        $this->stateResolver = $stateResolver;
        $this->refundService = $refundService;
        $this->memoService = $memoService;
        $this->authService = $authService;
        $this->shipmentService = $shipmentService;
        $this->claimService = $claimService;
    }

    /**
     * 주문 목록
     *
     * GET /admin/shop/orders
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        // 접점 기반 정리: 미결제 attempting 고아를 스로틀로 best-effort 스윕
        $this->orderService->maybeExpireStaleAttempting($domainId);
        // 접점 기반 자동 구매확정: 발송 후 N일 지난 주문을 스로틀로 확정 스윕
        $this->orderService->maybeAutoConfirm($domainId);

        $page = (int) ($request->get('page') ?? 1);
        $perPage = (int) ($request->get('per_page') ?? 20);

        $filters = [
            'keyword' => $request->get('keyword') ?? '',
            'search_field' => $request->get('search_field') ?? '',
            'order_status' => $request->get('order_status') ?? '',
            'date_from' => $request->get('date_from') ?? '',
            'date_to' => $request->get('date_to') ?? '',
            'payment_method' => $request->get('payment_method') ?? '',
        ];

        // '전체'(per_page<=0)는 검색/필터가 있을 때만 허용 (대량 로딩 방지)
        $hasFilter = ($filters['keyword'] !== '')
            || ($filters['order_status'] !== '')
            || ($filters['date_from'] !== '')
            || ($filters['date_to'] !== '')
            || ($filters['payment_method'] !== '');
        if ($perPage <= 0 && !$hasFilter) {
            $perPage = 20;
        }

        $result = $this->orderService->getOrderList($domainId, $filters, $page, $perPage);

        // FSM 기반 상태 옵션 + 배지 색상 빌드
        // 색상: 설정에 color가 있으면 우선, 없으면 action 기반 기본색 (override-ready)
        $allStates = $this->stateResolver->getAllStates($domainId);
        $statusOptions = [];
        $statusColors = [];
        foreach ($allStates as $state) {
            $statusOptions[$state['id']] = $state['label'];
            $statusColors[$state['id']] = $state['color'] ?? OrderAction::badgeColor($state['action'] ?? '');
        }

        // 운송장 일괄 표시: 이 페이지 주문들 중 운송장 보유 주문번호 집합(N+1 회피)
        $orders = $result->get('items', []);
        $shippedSet = [];
        if ($this->shipmentService) {
            $orderNos = array_column($orders, 'order_no');
            $shippedSet = array_flip($this->shipmentService->getShippedOrderNos($orderNos));
        }
        // 배송정보 입력 가능한 상태 id 집합 (delivery_editable)
        $deliveryEditableStates = [];
        foreach ($allStates as $state) {
            if (!empty($state['delivery_editable'])) {
                $deliveryEditableStates[$state['id']] = true;
            }
        }
        $deliveryCompanies = $this->shipmentService ? $this->shipmentService->getDeliveryCompanies() : [];

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Order/List')
            ->withData([
                'pageTitle' => '주문 관리',
                'orders' => $orders,
                'pagination' => $result->get('pagination', []),
                'filters' => $filters,
                'orderStatusOptions' => $statusOptions,
                'orderStatusColors' => $statusColors,
                'perPage' => $perPage,
                'hasFilter' => $hasFilter,
                'shippedSet' => $shippedSet,
                'deliveryEditableStates' => $deliveryEditableStates,
                'deliveryCompanies' => $deliveryCompanies,
            ]);
    }

    /**
     * 주문 상세
     *
     * GET /admin/shop/orders/{orderNo}
     */
    public function view(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $orderNo = $params['orderNo'] ?? $params[0] ?? $request->query('order_no', '');

        if (empty($orderNo)) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withData(['message' => '주문을 찾을 수 없습니다.']);
        }

        $result = $this->orderService->getOrder($orderNo);

        if ($result->isFailure()) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withData(['message' => $result->getMessage()]);
        }

        $order = $result->get('order');

        // 도메인 경계 검증
        if ((int) ($order['domain_id'] ?? 0) !== $domainId) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withData(['message' => '주문을 찾을 수 없습니다.']);
        }

        $currentStatusId = $order['order_status'] ?? '';

        // FSM 기반 데이터 (라벨 + 배지 색상, color override 우선)
        $allStates = $this->stateResolver->getAllStates($domainId);
        $statusOptions = [];
        $statusColors = [];
        foreach ($allStates as $state) {
            $statusOptions[$state['id']] = $state['label'];
            $statusColors[$state['id']] = $state['color'] ?? OrderAction::badgeColor($state['action'] ?? '');
        }

        $currentStatusLabel = $this->stateResolver->getLabel($domainId, $currentStatusId);
        $availableTransitions = $this->stateResolver->getAvailableTransitions($domainId, $currentStatusId);

        // 주문 로그
        $orderLogs = $this->orderService->getOrderLogs($orderNo);

        // 주문 추가 필드
        $orderFieldValues = $this->orderFieldService->getOrderFieldValues($orderNo);

        // 반품 정보
        $orderReturns = $this->orderService->getOrderReturns($orderNo);

        // 환불 정보
        $refundInfo = $this->refundService->getRefundableAmount($orderNo);
        $paymentTransactions = $this->refundService->getTransactionHistory($orderNo);

        // 배송(운송장) 정보 + 택배사 목록 + 현재 상태의 배송정보 편집 가능 여부
        $shipments = $this->shipmentService ? $this->shipmentService->getByOrderNo($orderNo) : [];
        $deliveryCompanies = $this->shipmentService ? $this->shipmentService->getDeliveryCompanies() : [];
        $deliveryEditable = $this->stateResolver->isDeliveryEditable($domainId, $currentStatusId);
        // 배송비 그룹 = 출고 단위. 둘 이상이면 송장을 그룹별로 따로 받는다.
        $shippingGroups = $this->shipmentService ? $this->shipmentService->getShippingGroups($orderNo) : [];
        $shipmentItemNames = $this->shipmentService ? $this->shipmentService->itemNamesByShipment($orderNo, $shipments) : [];

        // 선택 상품 일괄 변경 대상은 배송 단계만 연다. 취소·반품·구매확정은 환불·재고·적립
        // 후처리가 딸려 있어 전용 흐름이 담당하며, 여기서 상태만 바꾸면 그것을 건너뛴다.
        $bulkItemStatusOptions = [];
        foreach ($this->stateResolver->getAllStates($domainId) as $state) {
            $stateId = (string) ($state['id'] ?? '');
            if ($stateId === '') {
                continue;
            }
            if (in_array(
                $this->stateResolver->getAction($stateId, $state),
                [OrderAction::PREPARING, OrderAction::SHIPPING, OrderAction::DELIVERED],
                true
            )) {
                $bulkItemStatusOptions[$stateId] = (string) ($state['label'] ?? $stateId);
            }
        }

        // 관리자 메모
        $orderMemos = $this->memoService->getMemos($orderNo);

        // 목록 복귀용 검색 쿼리 보존 (상세 링크에 실려온 필터/페이지 파라미터)
        $listQuery = http_build_query(array_filter([
            'keyword'        => $request->get('keyword') ?? '',
            'search_field'   => $request->get('search_field') ?? '',
            'order_status'   => $request->get('order_status') ?? '',
            'date_from'      => $request->get('date_from') ?? '',
            'date_to'        => $request->get('date_to') ?? '',
            'payment_method' => $request->get('payment_method') ?? '',
            'per_page'       => $request->get('per_page') ?? '',
            'page'           => $request->get('page') ?? '',
        ], static fn($v) => $v !== '' && $v !== null));

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Order/View')
            ->withData([
                'pageTitle' => '주문 상세 - ' . $orderNo,
                'order' => $order,
                'orderItems' => $result->get('items', []),
                'orderStatusOptions' => $statusOptions,
                'orderStatusColors' => $statusColors,
                'availableTransitions' => $availableTransitions,
                'currentStatusLabel' => $currentStatusLabel,
                'orderLogs' => $orderLogs,
                'orderFieldValues' => $orderFieldValues,
                'orderReturns' => $orderReturns,
                'refundInfo' => $refundInfo->isSuccess() ? $refundInfo->getData() : [],
                'paymentTransactions' => $paymentTransactions,
                'shipments' => $shipments,
                'deliveryCompanies' => $deliveryCompanies,
                'deliveryEditable' => $deliveryEditable,
                'shippingGroups' => $shippingGroups,
                'shipmentItemNames' => $shipmentItemNames,
                'bulkItemStatusOptions' => $bulkItemStatusOptions,
                'orderMemos' => $orderMemos,
                'memoTypeLabels' => OrderMemoService::TYPE_LABELS,
                'domainId' => $domainId,
                'listQuery' => $listQuery,
            ]);
    }

    /**
     * 주문 상태 변경 (FSM 검증)
     *
     * POST /admin/shop/orders/{orderNo}/status
     */
    public function updateStatus(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $orderNo = $params['orderNo'] ?? $params[0] ?? $request->json('order_no', '');
        $status = $request->json('order_status', '');
        $reason = $request->json('reason', '');

        if (empty($orderNo)) {
            return JsonResponse::error('주문번호가 필요합니다.');
        }

        if (empty($status)) {
            return JsonResponse::error('변경할 주문 상태를 선택해주세요.');
        }

        if ($domainGuard = $this->assertOrderInDomain($orderNo, $domainId)) {
            return $domainGuard;
        }

        $result = $this->orderService->updateStatus($orderNo, $status, $domainId, $reason, 'STAFF');

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    // ===== 아이템 관리 =====

    /**
     * 선택 아이템 일괄 상태 변경 (배송 단계 전용)
     *
     * 배송준비·배송중·배송완료만 허용한다. 취소·반품·구매확정은 환불·재고·적립처럼
     * 돈이 오가는 후처리가 딸려 있어 전용 흐름이 담당하며, 여기서 상태만 바꾸면
     * 그 후처리를 통째로 건너뛴다.
     *
     * POST /admin/shop/orders/{orderNo}/items/bulk-status
     */
    public function bulkUpdateItemStatus(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $orderNo = (string) ($params['orderNo'] ?? '');
        $status = trim((string) ($request->json('order_status', '') ?? ''));
        $reason = trim((string) ($request->json('reason', '') ?? ''));
        $detailIds = array_values(array_filter(
            array_map('intval', (array) $request->json('detail_ids', [])),
            static fn(int $id): bool => $id > 0
        ));

        if ($orderNo === '' || $detailIds === []) {
            return JsonResponse::error('변경할 상품을 선택해주세요.');
        }
        if ($status === '') {
            return JsonResponse::error('변경할 상태를 선택해주세요.');
        }
        if ($domainGuard = $this->assertOrderInDomain($orderNo, $domainId)) {
            return $domainGuard;
        }

        $action = $this->stateResolver->getAction(
            $status,
            $this->stateResolver->getState($domainId, $status) ?? []
        );
        if (!in_array($action, [OrderAction::PREPARING, OrderAction::SHIPPING, OrderAction::DELIVERED], true)) {
            return JsonResponse::error('일괄 변경은 배송 단계(배송준비·배송중·배송완료)만 가능합니다.');
        }

        $changed = $this->orderService->advanceItemsToState(
            $orderNo,
            $domainId,
            $status,
            $detailIds,
            $reason !== '' ? $reason : '선택 상품 일괄 변경',
            'STAFF',
        );
        $skipped = count($detailIds) - $changed;

        if ($changed === 0) {
            // 되돌아가는 전이이거나 클레임이 걸린 상품만 골랐을 때
            return JsonResponse::error('선택한 상품은 그 상태로 옮길 수 없습니다. 이미 지난 단계이거나 교환·반품이 진행 중인지 확인해주세요.');
        }

        $label = $this->stateResolver->getLabel($domainId, $status);
        $message = "{$changed}개 상품을 '{$label}'(으)로 변경했습니다."
            . ($skipped > 0 ? " {$skipped}개는 그 상태로 옮길 수 없어 건너뛰었습니다." : '');

        return JsonResponse::success(['changed' => $changed, 'skipped' => $skipped], $message);
    }

    /**
     * 아이템 상태 변경
     *
     * POST /admin/shop/orders/{orderNo}/items/{detailId}/status
     */
    public function updateItemStatus(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $orderNo = $params['orderNo'] ?? '';
        $detailId = (int) ($params['detailId'] ?? 0);
        $status = $request->json('order_status', '');
        $reason = $request->json('reason', '');

        if (empty($orderNo) || $detailId <= 0) {
            return JsonResponse::error('주문번호와 상품 ID가 필요합니다.');
        }

        if (empty($status)) {
            return JsonResponse::error('변경할 상태를 선택해주세요.');
        }

        if ($domainGuard = $this->assertOrderInDomain($orderNo, $domainId)) {
            return $domainGuard;
        }

        $result = $this->orderService->updateItemStatus($orderNo, $detailId, $status, $domainId, $reason);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 아이템 취소
     *
     * POST /admin/shop/orders/{orderNo}/items/{detailId}/cancel
     */
    public function cancelItem(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $orderNo = $params['orderNo'] ?? '';
        $detailId = (int) ($params['detailId'] ?? 0);
        $reason = $request->json('reason', '');

        if (empty($orderNo) || $detailId <= 0) {
            return JsonResponse::error('주문번호와 상품 ID가 필요합니다.');
        }

        if (empty($reason)) {
            return JsonResponse::error('취소 사유를 입력해주세요.');
        }

        if ($domainGuard = $this->assertOrderInDomain($orderNo, $domainId)) {
            return $domainGuard;
        }

        $result = $this->orderService->cancelOrderItem($orderNo, $detailId, $reason, $domainId);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 반품 요청 접수
     *
     * POST /admin/shop/orders/{orderNo}/items/{detailId}/return
     */
    public function returnItem(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $orderNo = $params['orderNo'] ?? '';
        $detailId = (int) ($params['detailId'] ?? 0);
        $returnType = $request->json('return_type', '');
        $reasonType = $request->json('reason_type', '');
        $reasonDetail = $request->json('reason_detail', '');

        if (empty($orderNo) || $detailId <= 0) {
            return JsonResponse::error('주문번호와 상품 ID가 필요합니다.');
        }

        if ($returnType !== 'RETURN') {
            return JsonResponse::error('유효한 반품 유형을 선택해주세요.');
        }

        if ($domainGuard = $this->assertOrderInDomain($orderNo, $domainId)) {
            return $domainGuard;
        }

        if ($this->claimService === null) {
            return JsonResponse::error('교환·반품 기능을 사용할 수 없습니다.');
        }
        // 고객이 전화로 요청한 반품을 관리자가 대신 접수한다. 승인·회수·검수는
        // 교환·반품 관리에서 이어서 진행한다.
        $result = $this->claimService->request(
            $domainId,
            $orderNo,
            $detailId,
            [
                'quantity' => max(1, (int) ($request->json('quantity', 1) ?? 1)),
                'reason_type' => (string) $reasonType,
                'reason_detail' => (string) $reasonDetail,
            ],
            'STAFF',
            null,
            'RETURN',
        );

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    // ===== 환불 =====

    /**
     * 환불 처리
     *
     * POST /admin/shop/orders/{orderNo}/refund
     */
    public function refund(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $orderNo = $params['orderNo'] ?? '';
        $amount = (int) $request->json('amount', 0);
        $refundMethod = $request->json('refund_method', '');
        $reason = $request->json('reason', '');
        $bankInfo = [
            'bank' => $request->json('refund_bank', ''),
            'account' => $request->json('refund_account', ''),
            'holder' => $request->json('refund_holder', ''),
        ];

        if (empty($orderNo)) {
            return JsonResponse::error('주문번호가 필요합니다.');
        }
        if ($amount <= 0) {
            return JsonResponse::error('환불 금액을 입력해주세요.');
        }
        if (empty($refundMethod) || !in_array($refundMethod, ['PG_CANCEL', 'BANK', 'POINT'], true)) {
            return JsonResponse::error('환불 방법을 선택해주세요.');
        }
        if (empty($reason)) {
            return JsonResponse::error('환불 사유를 입력해주세요.');
        }

        if ($domainGuard = $this->assertOrderInDomain($orderNo, $domainId)) {
            return $domainGuard;
        }

        $staffId = $this->authService->id() ?? 0;

        $result = $this->refundService->processRefund(
            $orderNo, $amount, $refundMethod, $reason, $domainId, $staffId, $bankInfo
        );

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        // 환불이 상태를 끈다: 전액 환불(잔여 0)이고 취소 가능 상태면 자동으로 주문취소 전이.
        // 전이 시 OrderStatusChangedEvent → 포인트·쿠폰 자동 복원. 부분 환불은 상태 유지.
        $data = $result->getData();
        $autoCancelled = false;
        if ((int) ($data['remaining'] ?? -1) === 0) {
            $orderResult = $this->orderService->getOrder($orderNo);
            $currentStatus = $orderResult->isSuccess()
                ? (string) ($orderResult->getData()['order']['order_status'] ?? '')
                : '';
            if (in_array($currentStatus, [
                OrderAction::RECEIVED->value,
                OrderAction::PAID->value,
                OrderAction::CANCEL_REQUESTED->value,
            ], true)) {
                $transition = $this->orderService->updateStatus(
                    $orderNo,
                    OrderAction::CANCELLED->value,
                    $domainId,
                    '전액 환불 처리로 자동 주문취소',
                    'STAFF'
                );
                $autoCancelled = $transition->isSuccess();
            }
        }

        $data['auto_cancelled'] = $autoCancelled;
        $message = $result->getMessage() . ($autoCancelled ? ' 주문이 취소 처리되었습니다.' : '');

        return JsonResponse::success($data, $message);
    }

    /**
     * POINT 환불의 포인트 지급 재시도 (멱등 — 이미 지급된 건은 멱등 응답)
     *
     * POST /admin/shop/orders/{orderNo}/refund-point-retry
     */
    public function retryRefundPoint(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $orderNo = $params['orderNo'] ?? '';
        $txnId = (int) $request->json('transaction_id', 0);

        if (empty($orderNo) || $txnId <= 0) {
            return JsonResponse::error('환불 거래 정보가 필요합니다.');
        }

        if ($domainGuard = $this->assertOrderInDomain($orderNo, $domainId)) {
            return $domainGuard;
        }

        $result = $this->refundService->retryPointCredit($domainId, $orderNo, $txnId, $this->authService->id() ?? 0);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    // ===== 배송(운송장) =====

    /**
     * 송장 등록
     *
     * POST /admin/shop/orders/{orderNo}/shipments
     */
    public function registerShipment(array $params, Context $context): JsonResponse
    {
        if ($this->shipmentService === null) {
            return JsonResponse::error('배송 기능을 사용할 수 없습니다.');
        }

        $orderNo = $params['orderNo'] ?? '';
        $request = $context->getRequest();
        if (empty($orderNo)) {
            return JsonResponse::error('주문번호가 필요합니다.');
        }

        if ($domainGuard = $this->assertOrderInDomain($orderNo, $context->getDomainId() ?? 1)) {
            return $domainGuard;
        }

        $result = $this->shipmentService->registerShipment($orderNo, [
            'company_id'  => $request->json('company_id', null),
            'invoice_no'  => trim((string) $request->json('invoice_no', '')),
            'admin_memo'  => trim((string) $request->json('admin_memo', '')) ?: null,
            'shipping_group_key' => trim((string) ($request->json('shipping_group_key', '') ?? '')),
        ]);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 송장 수정
     *
     * POST /admin/shop/orders/{orderNo}/shipments/{shipmentId}
     */
    public function updateShipment(array $params, Context $context): JsonResponse
    {
        if ($this->shipmentService === null) {
            return JsonResponse::error('배송 기능을 사용할 수 없습니다.');
        }

        $shipmentId = (int) ($params['shipmentId'] ?? 0);
        $request = $context->getRequest();
        if ($shipmentId <= 0) {
            return JsonResponse::error('배송 정보 ID가 필요합니다.');
        }

        $orderNo = (string) ($params['orderNo'] ?? '');
        if ($domainGuard = $this->assertOrderInDomain($orderNo, $context->getDomainId() ?? 1)) {
            return $domainGuard;
        }

        $result = $this->shipmentService->updateShipment($shipmentId, [
            'company_id'  => $request->json('company_id', null),
            'invoice_no'  => trim((string) $request->json('invoice_no', '')),
            'admin_memo'  => trim((string) $request->json('admin_memo', '')) ?: null,
        ], $orderNo);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 송장 삭제
     *
     * POST /admin/shop/orders/{orderNo}/shipments/{shipmentId}/delete
     */
    public function deleteShipment(array $params, Context $context): JsonResponse
    {
        if ($this->shipmentService === null) {
            return JsonResponse::error('배송 기능을 사용할 수 없습니다.');
        }

        $shipmentId = (int) ($params['shipmentId'] ?? 0);
        if ($shipmentId <= 0) {
            return JsonResponse::error('배송 정보 ID가 필요합니다.');
        }

        $orderNo = (string) ($params['orderNo'] ?? '');
        if ($domainGuard = $this->assertOrderInDomain($orderNo, $context->getDomainId() ?? 1)) {
            return $domainGuard;
        }

        $result = $this->shipmentService->deleteShipment($shipmentId, $orderNo);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    // ===== 메모 =====

    /**
     * 메모 추가
     *
     * POST /admin/shop/orders/{orderNo}/memos
     */
    public function addMemo(array $params, Context $context): JsonResponse
    {
        $orderNo = $params['orderNo'] ?? '';
        $request = $context->getRequest();
        $content = trim($request->json('content', ''));
        $memoType = $request->json('memo_type', 'MEMO');
        $staffId = $this->authService->id() ?? 0;

        if (empty($orderNo)) {
            return JsonResponse::error('주문번호가 필요합니다.');
        }
        if (empty($content)) {
            return JsonResponse::error('메모 내용을 입력해주세요.');
        }

        if ($domainGuard = $this->assertOrderInDomain($orderNo, $context->getDomainId() ?? 1)) {
            return $domainGuard;
        }

        $result = $this->memoService->addMemo($orderNo, $content, $memoType, $staffId);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 메모 삭제
     *
     * POST /admin/shop/orders/{orderNo}/memos/{memoId}/delete
     */
    public function deleteMemo(array $params, Context $context): JsonResponse
    {
        $memoId = (int) ($params['memoId'] ?? 0);
        $staffId = $this->authService->id() ?? 0;

        if ($memoId <= 0) {
            return JsonResponse::error('메모 ID가 필요합니다.');
        }

        if ($domainGuard = $this->assertOrderInDomain((string) ($params['orderNo'] ?? ''), $context->getDomainId() ?? 1)) {
            return $domainGuard;
        }

        $result = $this->memoService->deleteMemo($memoId, $staffId);

        return $result->isSuccess()
            ? JsonResponse::success([], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 주문이 현재 관리자 도메인 소속인지 검증한다. 아니면(또는 없음) 에러 응답을 반환한다.
     *
     * 멀티테넌트 격리: 한 도메인의 관리자가 다른 도메인의 주문(상태/환불/메모/배송 등)을
     * 조작하지 못하도록 mutation 진입점에서 강제한다. read 경로(view)의 도메인 검증과 대칭이며,
     * 비소속 도메인에는 주문 존재를 노출하지 않도록 '찾을 수 없음'으로 응답한다.
     */
    private function assertOrderInDomain(string $orderNo, int $domainId): ?JsonResponse
    {
        $result = $this->orderService->getOrder($orderNo);
        if ($result->isFailure()) {
            return JsonResponse::error('주문을 찾을 수 없습니다.');
        }
        $order = $result->get('order');
        if ((int) ($order['domain_id'] ?? 0) !== $domainId) {
            return JsonResponse::error('주문을 찾을 수 없습니다.');
        }
        return null;
    }

    /**
     * 운송장 엑셀 일괄 업로드
     *
     * POST /admin/shop/orders/upload-invoices (multipart, file)
     * 다운로드한 주문 엑셀에 택배사·송장번호를 채워 업로드하면 일괄 등록한다.
     */
    public function uploadInvoices(array $params, Context $context): JsonResponse
    {
        if ($this->shipmentService === null) {
            return JsonResponse::error('배송 기능을 사용할 수 없습니다.');
        }

        $domainId = $context->getDomainId() ?? 1;

        $file = UploadedFile::fromGlobal('file');
        if ($file === null || !$file->isValid()) {
            return JsonResponse::error('업로드된 파일이 없습니다.');
        }
        if (!in_array(strtolower($file->getExtension()), ['xlsx', 'xls', 'csv'], true)) {
            return JsonResponse::error('xlsx/xls/csv 파일만 업로드할 수 있습니다.');
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return JsonResponse::error('파일이 너무 큽니다. (최대 5MB)');
        }

        try {
            $rows = $this->parseInvoiceSheet($file->getTmpName());
        } catch (\Throwable $e) {
            return JsonResponse::error('엑셀을 읽을 수 없습니다: ' . $e->getMessage());
        }
        if (empty($rows)) {
            return JsonResponse::error('처리할 행이 없습니다. (주문번호/송장번호 열을 확인하세요)');
        }

        // 도메인 소유 검증: 업로드된 주문번호 중 현재 도메인 소유만 처리
        $orderNos = array_values(array_unique(array_filter(
            array_map(static fn(array $r) => trim((string) ($r['order_no'] ?? '')), $rows)
        )));
        $validOrderNos = [];
        if (!empty($orderNos)) {
            $listResult = $this->orderService->getOrderList($domainId, ['order_nos' => $orderNos], 1, count($orderNos));
            if ($listResult->isSuccess()) {
                $validOrderNos = array_map(
                    static fn(array $o) => (string) ($o['order_no'] ?? ''),
                    $listResult->getData()['items'] ?? []
                );
            }
        }

        $result = $this->shipmentService->bulkRegisterFromRows($rows, $validOrderNos);

        return JsonResponse::success(
            $result,
            $result['success'] . '건 등록' . (count($result['failed']) > 0 ? ', ' . count($result['failed']) . '건 실패' : '')
        );
    }

    /**
     * 운송장 엑셀 파싱 — 헤더에서 주문번호/택배사/송장번호 열을 찾아 행 추출
     *
     * @return array<int, array{order_no:string, courier:string, invoice_no:string}>
     */
    private function parseInvoiceSheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        // formatData=true: 송장번호 등 숫자셀을 표시값 문자열로(지수표기 방지)
        $data = $sheet->toArray(null, true, true, false);
        if (empty($data)) {
            return [];
        }

        $header = array_map(static fn($v) => trim((string) $v), array_shift($data));
        $colOrder = $this->findHeaderIndex($header, ['주문번호', 'order_no']);
        $colCourier = $this->findHeaderIndex($header, ['택배사', 'courier']);
        $colInvoice = $this->findHeaderIndex($header, ['송장번호', 'invoice_no']);

        if ($colOrder === null || $colInvoice === null) {
            throw new \RuntimeException('주문번호/송장번호 열을 찾을 수 없습니다.');
        }

        $rows = [];
        foreach ($data as $row) {
            $rows[] = [
                'order_no'   => trim((string) ($row[$colOrder] ?? '')),
                'courier'    => $colCourier !== null ? trim((string) ($row[$colCourier] ?? '')) : '',
                'invoice_no' => trim((string) ($row[$colInvoice] ?? '')),
            ];
        }
        return $rows;
    }

    private function findHeaderIndex(array $header, array $candidates): ?int
    {
        foreach ($header as $i => $label) {
            if (in_array($label, $candidates, true)) {
                return (int) $i;
            }
        }
        return null;
    }
}
