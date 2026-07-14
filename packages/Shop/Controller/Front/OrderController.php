<?php

namespace Mublo\Packages\Shop\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Session\SessionInterface;
use Mublo\Infrastructure\Cache\CacheInterface;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Service\PaymentReceiptService;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Packages\Shop\Service\OrderCancelService;
use Mublo\Packages\Shop\Service\OrderFieldService;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Packages\Shop\Service\ReviewService;
use Mublo\Packages\Shop\Service\ShipmentService;
use Mublo\Packages\Shop\Service\ExchangeService;

/**
 * Front 주문 컨트롤러
 *
 * /shop/order, /shop/orders 라우트 처리
 */
class OrderController
{
    private OrderService $orderService;
    private AuthContextInterface $authService;
    private OrderFieldService $orderFieldService;
    private OrderStateResolver $orderStateResolver;
    private ReviewService $reviewService;
    private SessionInterface $session;
    private ShopConfigService $shopConfigService;
    private ?CacheInterface $cache;
    private PaymentReceiptService $receiptService;
    private ?OrderCancelService $cancelService;
    private ?ShipmentService $shipmentService;
    private ?ExchangeService $exchangeService;

    /** 비회원 주문 세션 키 (CartController와 동일) */
    private const GUEST_ORDER_SESSION_KEY = 'shop_guest_order_nos';

    /** 비회원 주문조회 무차별 대입 방어 (IP 기준) */
    private const LOOKUP_MAX_ATTEMPTS = 10;
    private const LOOKUP_WINDOW_SECONDS = 60;

    public function __construct(
        OrderService $orderService,
        AuthContextInterface $authService,
        OrderFieldService $orderFieldService,
        OrderStateResolver $orderStateResolver,
        ReviewService $reviewService,
        SessionInterface $session,
        ShopConfigService $shopConfigService,
        ?CacheInterface $cache,
        PaymentReceiptService $receiptService,
        ?OrderCancelService $cancelService = null,
        ?ShipmentService $shipmentService = null,
        ?ExchangeService $exchangeService = null
    ) {
        $this->orderService = $orderService;
        $this->authService = $authService;
        $this->orderFieldService = $orderFieldService;
        $this->orderStateResolver = $orderStateResolver;
        $this->reviewService = $reviewService;
        $this->session = $session;
        $this->shopConfigService = $shopConfigService;
        $this->cache = $cache;
        $this->receiptService = $receiptService;
        $this->cancelService = $cancelService;
        $this->shipmentService = $shipmentService;
        $this->exchangeService = $exchangeService;
    }

    /**
     * 주문의 영수증 URL 추출 (PG가 verify에서 표준 receipt_url 키로 정규화한 값).
     * 현재 도메인의 성공 결제 트랜잭션이 없으면 빈 문자열.
     */
    private function resolveReceiptUrl(int $domainId, string $orderNo): string
    {
        return $this->receiptService->getReceiptUrl($domainId, $orderNo);
    }

    /**
     * 비회원이 현재 세션에서 생성/조회한 주문인지 확인
     */
    private function isGuestOrderOwner(string $orderNo): bool
    {
        $list = $this->session->get(self::GUEST_ORDER_SESSION_KEY, []);
        return is_array($list) && in_array($orderNo, $list, true);
    }

    /**
     * 비회원 주문번호를 세션에 기록 (상세 재접근 시 소유권 검증용).
     * 결제 통과(CartController) 외에, 주문조회 폼 통과 시에도 호출한다.
     */
    private function rememberGuestOrder(string $orderNo): void
    {
        if ($orderNo === '') {
            return;
        }
        $list = $this->session->get(self::GUEST_ORDER_SESSION_KEY, []);
        if (!is_array($list)) {
            $list = [];
        }
        if (!in_array($orderNo, $list, true)) {
            $list[] = $orderNo;
            $this->session->set(self::GUEST_ORDER_SESSION_KEY, $list);
        }
    }

    /**
     * 주문 완료 페이지
     */
    public function complete(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $user = $this->authService->currentUser();
        $orderNo = $params['orderNo'] ?? $params[0] ?? '';

        if ($orderNo === '') {
            return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Order/Complete'))
                ->withStatusCode(404)
                ->withData(['message' => '주문 정보를 찾을 수 없습니다.']);
        }

        // 비회원 주문이라도 같은 세션에서 생성한 것이면 접근 허용
        $isGuestOwner = $this->isGuestOrderOwner($orderNo);
        if ($user === null && !$isGuestOwner) {
            return RedirectResponse::to('/login');
        }

        $memberId = $user ? ($this->authService->id() ?? 0) : 0;

        // 주문 정보 조회
        $result = $this->orderService->getOrder($orderNo);

        if ($result->isFailure()) {
            return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Order/Complete'))
                ->withStatusCode(404)
                ->withData(['message' => $result->getMessage()]);
        }

        $data = $result->getData();
        $order = $data['order'] ?? [];
        $domainId = $context->getDomainId() ?? 1;
        if ((int) ($order['domain_id'] ?? 0) !== $domainId) {
            return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Order/Complete'))
                ->withStatusCode(404)
                ->withData(['message' => '주문 정보를 찾을 수 없습니다.']);
        }
        if (!$this->orderService->isCustomerVisibleOrder($orderNo)) {
            return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Order/Complete'))
                ->withStatusCode(404)
                ->withData(['message' => '주문 정보를 찾을 수 없습니다.']);
        }

        // 소유권 검증: 회원은 자신의 주문, 비회원은 세션이 보유한 비회원 주문만 접근
        $orderMemberId = (int) ($order['member_id'] ?? 0);
        if ($orderMemberId !== $memberId || ($memberId === 0 && !$isGuestOwner)) {
            return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Order/Complete'))
                ->withStatusCode(403)
                ->withData(['message' => '주문 정보에 접근할 수 없습니다.']);
        }

        // 완료 페이지 표시용 주문 정보 (복호화 완료된 상태)
        // 결제 금액 = 상품 + 배송 + 추가 + 세금 - 쿠폰 - 포인트
        $grandTotal = (int) ($order['total_price'] ?? 0)
            + (int) ($order['shipping_fee'] ?? 0)
            + (int) ($order['extra_price'] ?? 0)
            + (int) ($order['tax_amount'] ?? 0)
            - (int) ($order['coupon_discount'] ?? 0)
            - (int) ($order['point_used'] ?? 0);
        $safeOrder = [
            'order_no'         => $order['order_no'] ?? '',
            'order_status'     => $order['order_status'] ?? '',
            'total_price'      => $order['total_price'] ?? 0,
            'shipping_fee'     => $order['shipping_fee'] ?? 0,
            'shipping_breakdown' => $order['shipping_breakdown'] ?? null,
            'coupon_discount'  => (int) ($order['coupon_discount'] ?? 0),
            'point_used'       => (int) ($order['point_used'] ?? 0),
            'grand_total'      => max(0, $grandTotal),
            'payment_method'   => $order['payment_method'] ?? '',
            'payment_gateway'  => $order['payment_gateway'] ?? '',
            'bank_account_info' => $order['bank_account_info'] ?? null,
            'recipient_name'   => $order['recipient_name'] ?? '',
            'shipping_zip'     => $order['shipping_zip'] ?? '',
            'shipping_address1' => $order['shipping_address1'] ?? '',
            'shipping_address2' => $order['shipping_address2'] ?? '',
            'created_at'       => $order['created_at'] ?? '',
        ];

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Order/Complete'))
            ->withData([
                'order'      => $safeOrder,
                'orderItems' => $data['items'] ?? [],
                'receiptUrl' => $this->resolveReceiptUrl($domainId, $orderNo),
            ]);
    }

    /**
     * 내 주문 목록 (로그인 필수)
     */
    public function index(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $user = $this->authService->currentUser();
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        // 접점 기반 정리: 미결제 attempting 고아를 스로틀로 best-effort 스윕
        $this->orderService->maybeExpireStaleAttempting($domainId);
        // 접점 기반 자동 구매확정: 발송 후 N일 지난 주문을 스로틀로 확정 스윕
        $this->orderService->maybeAutoConfirm($domainId);

        $isGuest = ($user === null);
        // 상품명 검색 키워드 (회원만 의미 있음)
        $keyword = $isGuest ? '' : trim((string) ($request->get('keyword') ?? ''));

        if ($isGuest) {
            // 비회원: 세션이 보유(결제/조회로 증명)한 주문만 표시.
            // 증명된 주문이 없으면 로그인(회원) 또는 비회원 주문조회로 유도한다.
            $guestOrderNos = $this->session->get(self::GUEST_ORDER_SESSION_KEY, []);
            if (!is_array($guestOrderNos) || $guestOrderNos === []) {
                return RedirectResponse::to('/login?redirect=' . rawurlencode('/shop/orders') . '&intent=order_lookup');
            }
            $result = $this->orderService->getGuestOrders($guestOrderNos);
        } else {
            $memberId = $this->authService->id() ?? 0;
            $page = max(1, (int) ($request->get('page') ?? 1));
            $result = $this->orderService->getMemberOrders($domainId, $memberId, $page, 10, $keyword);
        }

        $data = $result->isSuccess() ? $result->getData() : ['items' => [], 'pagination' => []];
        $items = $data['items'] ?? [];
        $pagination = $data['pagination'] ?? [
            'totalItems' => 0,
            'perPage' => 20,
            'currentPage' => 1,
            'totalPages' => 1,
        ];
        $pagination['pageNums'] = 10;

        // 상태 라벨 매핑용
        $allStates = $this->orderStateResolver->getAllStates($domainId);

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Order/Index'))
            ->withData([
                'orders'     => $items,
                'pagination' => $pagination,
                'allStates'  => $allStates,
                'keyword'    => $keyword,
                'isGuest'    => $isGuest,
            ]);
    }

    /**
     * 주문 상세
     *
     * 회원은 자신의 주문, 비회원은 같은 세션이 소유 증명한 주문(결제 통과 또는
     * 주문조회 폼 통과)만 열람할 수 있다. 소유 증명이 없는 비회원은 주문조회 폼으로 유도.
     */
    public function view(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $orderNo = $params['orderNo'] ?? $params[0] ?? '';

        // 소유권 검증 (회원/비회원 공통) — view·cancel 동일 게이트
        $access = $this->resolveAccessibleOrder($orderNo, $context);
        if (!$access['ok']) {
            if (!empty($access['redirect'])) {
                return RedirectResponse::to($access['redirect']);
            }
            return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Order/View'))
                ->withStatusCode($access['status'] ?? 404)
                ->withData([
                    'message' => $access['message'] ?? '주문 정보를 찾을 수 없습니다.',
                    'isGuest' => $access['isGuest'],
                ]);
        }

        $isGuest = $access['isGuest'];
        $domainId = $access['domainId'];
        $order = $access['order'];
        $data = $access['data'];

        // 커스텀 필드 값 조회
        $orderFieldValues = $this->orderFieldService->getOrderFieldValues($orderNo);

        // 상태 라벨 + 전체 상태 목록 (타임라인용)
        $currentStatus = $order['order_status'] ?? '';
        $statusLabel = $this->orderStateResolver->getLabel($domainId, $currentStatus);
        $allStates = $this->orderStateResolver->getAllStates($domainId);

        // 항목별 구매확정/후기 상태 — 비회원 주문도 회원과 동일하게 처리
        $items = $data['items'] ?? [];
        $reviewMeta = $this->buildReviewMeta($domainId, $items, $currentStatus);

        $exchangeClaimsByDetail = [];
        foreach ($this->exchangeService?->getByOrderNo($domainId, $orderNo) ?? [] as $claim) {
            $detailId = (int) ($claim['order_detail_id'] ?? 0);
            $exchangeClaimsByDetail[$detailId][] = $claim;
            $claimStatus = \Mublo\Packages\Shop\Enum\ClaimStatus::tryFrom((string) ($claim['return_status'] ?? ''));
            if ($claimStatus?->isActive() && isset($reviewMeta[$detailId])) {
                $reviewMeta[$detailId]['confirmed'] = false;
                $reviewMeta[$detailId]['canConfirm'] = false;
                $reviewMeta[$detailId]['pending'] = true;
            }
        }
        $exchangeOptionsByDetail = [];
        if ($this->exchangeService !== null) {
            foreach ($items as $item) {
                $detailId = (int) ($item['order_detail_id'] ?? 0);
                $itemAction = $this->resolveAction($domainId, (string) ($item['status'] ?? $currentStatus));
                $exchangeOptionsByDetail[$detailId] = $itemAction === \Mublo\Packages\Shop\Enum\OrderAction::DELIVERED
                    ? $this->exchangeService->getExchangeOptions($domainId, $detailId)
                    : [];
            }
        }

        // 취소/반품 주문 타임라인용 상태 로그 (실제 거쳐온 경로 재구성에 사용)
        $orderLogs = $this->orderService->getOrderLogs($orderNo);

        // 배송(운송장) 정보 — 택배사·송장번호·추적링크 표시용
        $shipments = $this->shipmentService ? $this->shipmentService->getByOrderNo($orderNo) : [];

        // 목록으로 복귀할 때 페이지/검색어 보존 (목록에서 ?page=N&keyword=... 로 진입한 경우)
        $req = $context->getRequest();
        $listPage = max(1, (int) ($req->get('page') ?? 1));
        $listKeyword = trim((string) ($req->get('keyword') ?? ''));

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Order/View'))
            ->withData([
                'order'             => $order,
                'orderItems'        => $items,
                'orderFieldValues'  => $orderFieldValues,
                'statusLabel'       => $statusLabel,
                'allStates'         => $allStates,
                'orderLogs'         => $orderLogs,
                'shipments'         => $shipments,
                'reviewMeta'        => $reviewMeta,
                'exchangeClaimsByDetail' => $exchangeClaimsByDetail,
                'exchangeOptionsByDetail' => $exchangeOptionsByDetail,
                'isGuest'           => $isGuest,
                'listPage'          => $listPage,
                'listKeyword'       => $listKeyword,
            ]);
    }

    /**
     * 사용자 주문 취소 (AJAX)
     *
     * 회원/비회원 모두 자신이 소유 증명한 주문만 취소할 수 있다(view와 동일 게이트).
     * 실제 취소/환불 정책은 OrderCancelService가 담당:
     * - received(미결제/입금대기): 즉시 취소
     * - paid(결제완료): PG는 자동 결제취소 후 취소, 무통장은 취소요청 접수
     * - 그 외 상태: 취소 불가
     */
    public function cancel(array $params, Context $context): JsonResponse
    {
        if ($this->cancelService === null) {
            return JsonResponse::error('주문 취소를 처리할 수 없습니다.');
        }

        $orderNo = $params['orderNo'] ?? $params[0] ?? '';

        $access = $this->resolveAccessibleOrder($orderNo, $context);
        if (!$access['ok']) {
            return JsonResponse::error($access['message'] ?? '주문 정보에 접근할 수 없습니다.');
        }

        $reason = trim((string) ($context->getRequest()->json('reason') ?? ''));

        $result = $this->cancelService->cancelByCustomer($orderNo, $access['domainId'], $reason);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 사용자 구매확정 (배송중/배송완료 → 구매확정).
     *
     * 확정 후 해당 주문의 항목들에 구매후기 작성 버튼이 노출된다(회원 한정).
     */
    public function confirm(array $params, Context $context): JsonResponse
    {
        $orderNo = $params['orderNo'] ?? $params[0] ?? '';

        $access = $this->resolveAccessibleOrder($orderNo, $context);
        if (!$access['ok']) {
            return JsonResponse::error($access['message'] ?? '주문 정보에 접근할 수 없습니다.');
        }

        if ($this->exchangeService !== null) {
            foreach ($this->exchangeService->getByOrderNo($access['domainId'], $orderNo) as $claim) {
                $claimStatus = \Mublo\Packages\Shop\Enum\ClaimStatus::tryFrom((string) ($claim['return_status'] ?? ''));
                if ($claimStatus?->isActive()) {
                    return JsonResponse::error('교환이 진행 중인 주문은 구매확정할 수 없습니다.');
                }
            }
        }

        $result = $this->orderService->confirmByBuyer($orderNo, $access['domainId']);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 고객 교환 신청 (회원 및 소유권을 확인한 비회원). */
    public function exchangeItem(array $params, Context $context): JsonResponse
    {
        if ($this->exchangeService === null) {
            return JsonResponse::error('교환 기능을 사용할 수 없습니다.');
        }
        $orderNo = (string) ($params['orderNo'] ?? '');
        $detailId = (int) ($params['detailId'] ?? 0);
        $access = $this->resolveAccessibleOrder($orderNo, $context);
        if (!$access['ok']) {
            return JsonResponse::error($access['message'] ?? '주문 정보에 접근할 수 없습니다.');
        }
        $request = $context->getRequest();
        $data = [
            'quantity' => (int) ($request->json('quantity', 0) ?? 0),
            'target_option_id' => (int) ($request->json('target_option_id', 0) ?? 0),
            'target_option_code' => (string) ($request->json('target_option_code', '') ?? ''),
            'reason_type' => (string) ($request->json('reason_type', 'OTHER') ?? 'OTHER'),
            'reason_detail' => (string) ($request->json('reason_detail', '') ?? ''),
            'responsibility' => (string) ($request->json('responsibility', 'CUSTOMER') ?? 'CUSTOMER'),
        ];
        $memberId = (int) ($access['order']['member_id'] ?? 0);
        $result = $this->exchangeService->request($access['domainId'], $orderNo, $detailId, $data, 'CUSTOMER', $memberId ?: null);
        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 회수 전 고객 교환 신청 취소. */
    public function cancelExchange(array $params, Context $context): JsonResponse
    {
        if ($this->exchangeService === null) {
            return JsonResponse::error('교환 기능을 사용할 수 없습니다.');
        }
        $orderNo = (string) ($params['orderNo'] ?? '');
        $claimId = (int) ($params['claimId'] ?? 0);
        $access = $this->resolveAccessibleOrder($orderNo, $context);
        if (!$access['ok']) {
            return JsonResponse::error($access['message'] ?? '주문 정보에 접근할 수 없습니다.');
        }
        $claim = $this->exchangeService->get($access['domainId'], $claimId);
        if ($claim === null || (string) ($claim['order_no'] ?? '') !== $orderNo) {
            return JsonResponse::error('교환 건을 찾을 수 없습니다.');
        }
        $memberId = (int) ($access['order']['member_id'] ?? 0);
        $result = $this->exchangeService->cancel($access['domainId'], $claimId, 'CUSTOMER', $memberId ?: null, '고객 신청 취소');
        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 주문 상세 접근 게이트 (소유권 검증) — view·cancel 공용
     *
     * 회원은 자신의 주문, 비회원은 같은 세션이 소유 증명한 주문만 접근 가능.
     *
     * @return array{
     *   ok:bool, isGuest:bool, message?:string, status?:int, redirect?:string,
     *   domainId?:int, memberId?:int, order?:array, data?:array
     * }
     */
    private function resolveAccessibleOrder(string $orderNo, Context $context): array
    {
        $user = $this->authService->currentUser();
        $isGuest = ($user === null);

        if ($orderNo === '') {
            return ['ok' => false, 'isGuest' => $isGuest, 'status' => 404, 'message' => '주문 정보를 찾을 수 없습니다.'];
        }

        $isGuestOwner = $isGuest && $this->isGuestOrderOwner($orderNo);

        // 소유 증명이 없는 비회원 → 주문조회 폼으로
        if ($isGuest && !$isGuestOwner) {
            return ['ok' => false, 'isGuest' => $isGuest, 'redirect' => '/shop/order/lookup', 'message' => '주문 정보에 접근할 수 없습니다.'];
        }

        $domainId = $context->getDomainId() ?? 1;
        $memberId = $isGuest ? 0 : ($this->authService->id() ?? 0);

        $result = $this->orderService->getOrder($orderNo);
        if ($result->isFailure()) {
            return ['ok' => false, 'isGuest' => $isGuest, 'status' => 404, 'message' => $result->getMessage()];
        }

        $data = $result->getData();
        $order = $data['order'] ?? [];
        if ((int) ($order['domain_id'] ?? 0) !== $domainId) {
            return ['ok' => false, 'isGuest' => $isGuest, 'status' => 404, 'message' => '주문 정보를 찾을 수 없습니다.'];
        }
        if (!$this->orderService->isCustomerVisibleOrder($orderNo)) {
            return ['ok' => false, 'isGuest' => $isGuest, 'status' => 404, 'message' => '주문 정보를 찾을 수 없습니다.'];
        }

        // 소유권 검증: 회원은 자신의 주문, 비회원은 세션 보유 비회원 주문만
        $orderMemberId = (int) ($order['member_id'] ?? 0);
        $ownershipOk = $isGuest
            ? ($orderMemberId === 0 && $isGuestOwner)
            : ($orderMemberId === $memberId);
        if (!$ownershipOk) {
            return ['ok' => false, 'isGuest' => $isGuest, 'status' => 403, 'message' => '주문 정보에 접근할 수 없습니다.'];
        }

        return [
            'ok'       => true,
            'isGuest'  => $isGuest,
            'domainId' => $domainId,
            'memberId' => $memberId,
            'order'    => $order,
            'data'     => $data,
        ];
    }

    /**
     * 비회원 주문조회 폼 (GET)
     */
    public function lookupForm(array $params, Context $context): ViewResponse
    {
        return $this->renderLookup($context->getDomainId() ?? 1);
    }

    /**
     * 비회원 주문조회 처리 (POST) — 주문인명 + 연락처
     *
     * 일치 주문이 1건이면 상세로 리다이렉트, 여러 건이면 목록을 표시한다.
     * 조회 성공 시 세션에 주문번호를 기록해 상세 재접근 시 게이트를 재통과하지 않아도 된다.
     */
    public function lookup(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        // 무차별 대입 방어 (IP 기준)
        if ($this->isLookupRateLimited($context)) {
            return $this->renderLookup($domainId, '조회 시도가 너무 많습니다. 잠시 후 다시 시도해주세요.');
        }

        $request = $context->getRequest();
        $ordererName = trim((string) ($request->post('orderer_name') ?? ''));
        $ordererPhone = trim((string) ($request->post('orderer_phone') ?? ''));

        $result = $this->orderService->lookupGuestOrders($ordererName, $ordererPhone);
        if ($result->isFailure()) {
            return $this->renderLookup($domainId, $result->getMessage(), $ordererName, $ordererPhone);
        }

        $orders = $result->getData()['orders'] ?? [];

        // 소유 증명 기록(인증). 이후 표시는 기존 라우팅에 위임한다:
        // 세션 스탬프 덕분에 /shop/order(s) 가 비회원에게도 접근을 허용한다.
        foreach ($orders as $o) {
            $this->rememberGuestOrder((string) ($o['order_no'] ?? ''));
        }

        // 단건은 주문 상세로, 다건은 주문 목록으로 (회원과 동일한 라우트/뷰 재사용)
        if (count($orders) === 1) {
            return RedirectResponse::to('/shop/order/' . ($orders[0]['order_no'] ?? ''));
        }
        return RedirectResponse::to('/shop/orders');
    }

    /**
     * 주문조회 폼 렌더 헬퍼 (인증 전용 게이트 — 결과 표시는 /shop/order(s)에 위임)
     */
    private function renderLookup(
        int $domainId,
        ?string $error = null,
        string $ordererName = '',
        string $ordererPhone = ''
    ): ViewResponse {
        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Order/Lookup'))
            ->withData([
                'error'        => $error,
                'ordererName'  => $ordererName,
                'ordererPhone' => $ordererPhone,
            ]);
    }

    /**
     * 비회원 주문조회 무차별 대입 방어 (IP 기준 슬라이딩 카운터)
     */
    private function isLookupRateLimited(Context $context): bool
    {
        if ($this->cache === null) {
            return false;
        }
        $ip = $context->getRequest()->getClientIp() ?: 'unknown';
        $key = 'shop:guest-lookup:' . md5($ip);
        $count = (int) $this->cache->get($key, 0);
        if ($count >= self::LOOKUP_MAX_ATTEMPTS) {
            return true;
        }
        $this->cache->set($key, $count + 1, self::LOOKUP_WINDOW_SECONDS);
        return false;
    }

    /**
     * 주문 항목별 후기 메타 구성
     *
     * @return array<int,array{confirmed:bool,reviewed:bool,review:?array}>
     *         [order_detail_id => [...]]
     */
    private function buildReviewMeta(int $domainId, array $items, string $orderStatus): array
    {
        if (empty($items)) {
            return [];
        }

        $detailIds = array_map(static fn($it) => (int) ($it['order_detail_id'] ?? 0), $items);
        $reviewedMap = $this->reviewService->getReviewedDetailIds($domainId, $detailIds);

        $orderAction = $this->resolveAction($domainId, $orderStatus);

        $meta = [];
        foreach ($items as $item) {
            $detailId = (int) ($item['order_detail_id'] ?? 0);
            if ($detailId <= 0) {
                continue;
            }

            $itemAction = $this->resolveAction($domainId, (string) ($item['status'] ?? ''));
            $reviewId = $reviewedMap[$detailId] ?? 0;

            $review = null;
            if ($reviewId > 0) {
                $r = $this->reviewService->getDetail($domainId, $reviewId);
                if ($r) {
                    $review = [
                        'review_id'   => (int) $r['review_id'],
                        'rating'      => (int) ($r['rating'] ?? 5),
                        'content'     => (string) ($r['content'] ?? ''),
                        'images'      => array_values(array_filter([
                            $r['image1'] ?? null,
                            $r['image2'] ?? null,
                            $r['image3'] ?? null,
                        ])),
                        'admin_reply' => (string) ($r['admin_reply'] ?? ''),
                        'created_at'  => substr((string) ($r['created_at'] ?? ''), 0, 10),
                    ];
                }
            }

            $meta[$detailId] = [
                'confirmed'  => $this->isReviewable($orderAction, $itemAction),
                'canConfirm' => $this->canBuyerConfirm($orderAction, $itemAction),
                'pending'    => $this->isReviewPending($orderAction, $itemAction),
                'reviewed'   => $reviewId > 0,
                'review'     => $review,
            ];
        }

        return $meta;
    }

    /**
     * 상태 id → OrderAction (없거나 미해석 시 null)
     */
    private function resolveAction(int $domainId, string $stateId): ?OrderAction
    {
        if ($stateId === '') {
            return null;
        }
        $def = $this->orderStateResolver->getState($domainId, $stateId);
        return $def ? $this->orderStateResolver->getAction($stateId, $def) : null;
    }

    /**
     * 후기 작성 가능 여부.
     *
     * 이 시스템에서 항목 상태(shop_order_details.status)는 종종 초기값(received)에
     * 머무르고 구매확정은 주문 헤더(order_status)에서 진행된다. 따라서
     * "주문이 구매확정" 또는 "항목 자체가 구매확정"이면 작성 가능하되,
     * 항목이 취소/반품 계열이면 제외한다.
     */
    private function isReviewable(?OrderAction $orderAction, ?OrderAction $itemAction): bool
    {
        if ($itemAction === OrderAction::CONFIRMED) {
            return true;
        }
        if ($this->isReviewExcludedAction($itemAction)) {
            return false;
        }
        return $orderAction === OrderAction::CONFIRMED;
    }

    /**
     * 아직 작성 불가하지만 향후 작성 가능해질 상태(진행 중)인지.
     * 취소·반품 등 후기가 영영 불가능한 상태는 제외하여, disabled 플레이스홀더 버튼 노출 여부 판단에 사용한다.
     */
    private function isReviewPending(?OrderAction $orderAction, ?OrderAction $itemAction): bool
    {
        if ($this->isReviewable($orderAction, $itemAction)) {
            return false;
        }
        return !$this->isReviewExcludedAction($itemAction)
            && !$this->isReviewExcludedAction($orderAction);
    }

    /**
     * 취소·반품(요청 포함) 등 후기 작성이 불가능한 종료 상태인지.
     */
    private function isReviewExcludedAction(?OrderAction $action): bool
    {
        return $action !== null && in_array($action, [
            OrderAction::CANCEL_REQUESTED,
            OrderAction::CANCELLED,
            OrderAction::RETURN_REQUESTED,
            OrderAction::RETURNED,
        ], true);
    }

    /**
     * 구매자가 지금 구매확정 버튼을 누를 수 있는지.
     * 주문이 배송중/배송완료(아직 미확정)이고 항목이 취소/반품 계열이 아니면 가능.
     * (확정하면 후기 작성 버튼이 노출된다)
     */
    private function canBuyerConfirm(?OrderAction $orderAction, ?OrderAction $itemAction): bool
    {
        if ($this->isReviewExcludedAction($itemAction)) {
            return false;
        }
        return in_array($orderAction, [OrderAction::SHIPPING, OrderAction::DELIVERED], true);
    }
}
