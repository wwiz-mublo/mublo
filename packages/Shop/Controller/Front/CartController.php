<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Packages\Shop\Service\CartService;
use Mublo\Packages\Shop\Service\CartCheckoutService;
use Mublo\Packages\Shop\Service\DirectBuyService;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\OrderPointService;
use Mublo\Packages\Shop\Service\CouponService;
use Mublo\Packages\Shop\Service\PaymentService;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Packages\Shop\Service\MemberAddressService;
use Mublo\Packages\Shop\Service\OrderFieldService;
use Mublo\Contract\Member\PolicyQueryInterface;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Enum\PaymentMethod;
use Mublo\Contract\Tracking\TrackingKeys;
use Mublo\Core\Session\SessionInterface;
use Mublo\Infrastructure\Storage\UploadedFile;

/**
 * Front 장바구니 컨트롤러
 *
 * /shop/cart, /shop/checkout 라우트 처리
 */
class CartController
{
    private CartService $cartService;
    private CartCheckoutService $cartCheckoutService;
    private DirectBuyService $directBuyService;
    private OrderService $orderService;
    private PaymentService $paymentService;
    private AuthContextInterface $authService;
    private MemberAddressService $addressService;
    private ShopConfigService $shopConfigService;
    private OrderFieldService $orderFieldService;
    private SessionInterface $session;
    private OrderPointService $orderPointService;
    private CouponService $couponService;
    private PolicyQueryInterface $policyService;

    public function __construct(
        CartService $cartService,
        CartCheckoutService $cartCheckoutService,
        DirectBuyService $directBuyService,
        OrderService $orderService,
        PaymentService $paymentService,
        AuthContextInterface $authService,
        MemberAddressService $addressService,
        ShopConfigService $shopConfigService,
        OrderFieldService $orderFieldService,
        SessionInterface $session,
        OrderPointService $orderPointService,
        CouponService $couponService,
        PolicyQueryInterface $policyService
    ) {
        $this->cartService = $cartService;
        $this->cartCheckoutService = $cartCheckoutService;
        $this->directBuyService = $directBuyService;
        $this->orderService = $orderService;
        $this->paymentService = $paymentService;
        $this->authService = $authService;
        $this->addressService = $addressService;
        $this->shopConfigService = $shopConfigService;
        $this->orderFieldService = $orderFieldService;
        $this->session = $session;
        $this->orderPointService = $orderPointService;
        $this->couponService = $couponService;
        $this->policyService = $policyService;
    }

    /**
     * 도메인 설정에서 체크아웃 필수 동의 약관 목록을 로드한다.
     *
     * @return array<int, array{policy_id:int, revision_id:int, title:string, version:string, content_hash:string, content:string}>
     */
    private function loadCheckoutPolicies(int $domainId, array $shopConfig, array $domainConfig): array
    {
        $ids = json_decode($shopConfig['checkout_policies'] ?? '', true);
        if (!is_array($ids) || empty($ids)) {
            return [];
        }

        $documents = [];
        foreach ($this->policyService->activeDocuments($domainId) as $document) {
            $documents[$document->policyId] = $document;
        }

        $policies = [];
        foreach (array_map('intval', $ids) as $policyId) {
            $document = $documents[$policyId] ?? null;
            if ($document === null) {
                continue;
            }
            $policies[] = [
                'policy_id' => $document->policyId,
                'revision_id' => $document->revisionId,
                'title' => $document->title,
                'version' => $document->version,
                'content_hash' => $document->contentHash,
                'content' => $this->policyService->renderDocument($document, $domainConfig),
            ];
        }

        return $policies;
    }

    /**
     * 장바구니 세션 ID 가져오기 또는 생성
     *
     * 쿠키/세션에서 cart_session_id를 조회하고,
     * 없으면 새로 생성하여 쿠키에 저장한다.
     * 쿠키 유효기간: 회원 cart_keep_days(기본 15일), 비회원 guest_cart_keep_days(기본 7일)
     */
    private function getCartSession(Context $context): string
    {
        $request = $context->getRequest();
        $sessionId = $request->cookie('cart_session_id') ?? '';

        $domainId = $context->getDomainId() ?? 1;
        $configResult = $this->shopConfigService->getConfig($domainId);
        $shopConfig = $configResult->get('config', []);

        $isLoggedIn = $this->authService->check();
        $keepDays = $isLoggedIn
            ? (int) ($shopConfig['cart_keep_days'] ?? 15)
            : (int) ($shopConfig['guest_cart_keep_days'] ?? 7);

        if ($sessionId === '') {
            $sessionId = bin2hex(random_bytes(16));
        }

        // 매 요청마다 쿠키 갱신 (유효기간 연장)
        setcookie('cart_session_id', $sessionId, time() + (86400 * $keepDays), '/', '', false, true);

        return $sessionId;
    }

    /**
     * 장바구니 목록 페이지 (배송 그룹핑)
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $cartSessionId = $this->getCartSession($context);
        $memberId = $this->authService->id() ?? 0;

        $result = $this->cartService->getGroupedCartList($cartSessionId, $memberId, $domainId);

        $groups = $result->isSuccess() ? ($result->getData()['groups'] ?? []) : [];
        $totals = $result->isSuccess() ? ($result->getData()['totals'] ?? []) : [
            'itemTotal' => 0, 'shippingTotal' => 0, 'pointTotal' => 0, 'grandTotal' => 0,
        ];
        $productData = $result->isSuccess() ? ($result->getData()['productData'] ?? []) : [];

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Cart/List'))
            ->withData([
                'groups'      => $groups,
                'totals'      => $totals,
                'productData' => $productData,
            ]);
    }

    /**
     * 장바구니 내 단일 항목 옵션 변경 (POST, JSON)
     */
    public function updateOption(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $cartSessionId = $this->getCartSession($context);
        $memberId = $this->authService->id() ?? 0;

        $cartItemId = (int) ($request->json('cart_item_id') ?? 0);
        if ($cartItemId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $result = $this->cartService->updateOptionByCartItem(
            $cartItemId,
            $cartSessionId,
            $memberId,
            $domainId,
            [
                'option_mode'     => $request->json('optionMode') ?? 'NONE',
                'quantity'        => max(1, (int) ($request->json('quantity') ?? 1)),
                'selectedOptions' => $request->json('selectedOptions') ?? [],
                'selectedExtras'  => $request->json('selectedExtras') ?? [],
            ]
        );

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success(null, $result->getMessage());
    }

    /**
     * 장바구니 담기 / 바로구매 (POST, JSON)
     *
     * JS ShopProductOption.getSubmitData() 구조를 그대로 전달:
     * - optionMode (camelCase)
     * - selectedOptions[], selectedExtras[]
     * - action: 'cart' | 'direct'
     */
    public function add(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $cartSessionId = $this->getCartSession($context);
        $memberId = $this->authService->id() ?? 0;

        $goodsId = (int) ($request->json('goods_id') ?? 0);
        if ($goodsId <= 0) {
            return JsonResponse::error('상품 정보가 올바르지 않습니다.');
        }

        // JS camelCase → Service 키 매핑
        $result = $this->cartService->addToCart([
            'cart_session_id' => $cartSessionId,
            'member_id'       => $memberId,
            'domain_id'       => $domainId,
            'goods_id'        => $goodsId,
            'action'          => $request->json('action') ?? 'cart',
            'option_mode'     => $request->json('optionMode') ?? 'NONE',
            'quantity'        => max(1, (int) ($request->json('quantity') ?? 1)),
            'selectedOptions' => $request->json('selectedOptions') ?? [],
            'selectedExtras'  => $request->json('selectedExtras') ?? [],
        ]);

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success($result->getData(), $result->getMessage());
    }

    /**
     * 장바구니 수량 변경 (POST, JSON)
     */
    public function update(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $cartSessionId = $this->getCartSession($context);
        $memberId = $this->authService->id() ?? 0;
        $cartItemId = (int) ($request->json('cart_item_id') ?? 0);
        $quantity = max(1, (int) ($request->json('quantity') ?? 1));

        if ($cartItemId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $result = $this->cartService->updateQuantity(
            $cartItemId,
            $quantity,
            $cartSessionId,
            $memberId,
            $domainId
        );

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success($result->getData(), $result->getMessage());
    }

    /**
     * 장바구니 아이템 삭제 (POST, JSON)
     */
    public function remove(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $cartSessionId = $this->getCartSession($context);
        $memberId = $this->authService->id() ?? 0;
        $cartItemId = (int) ($request->json('cart_item_id') ?? 0);

        if ($cartItemId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $result = $this->cartService->removeItem($cartItemId, $cartSessionId, $memberId, $domainId);

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success(null, $result->getMessage());
    }

    /**
     * 장바구니 아이템 가격 새로고침 (POST, JSON)
     *
     * 가격변동 항목을 현재 판매가로 갱신("현재가로 담기")
     */
    public function refreshPrice(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $cartSessionId = $this->getCartSession($context);
        $memberId = $this->authService->id() ?? 0;
        $cartItemId = (int) ($request->json('cart_item_id') ?? 0);

        if ($cartItemId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $result = $this->cartService->refreshItemPrice($cartItemId, $cartSessionId, $memberId, $domainId);

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success(null, $result->getMessage());
    }

    /**
     * 체크아웃 준비 (POST, JSON)
     *
     * 선택된 cart_item_ids를 검증하여 세션에 저장
     */
    public function recalculate(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $cartSessionId = $this->getCartSession($context);
        $memberId = $this->authService->id() ?? 0;
        $cartItemIds = $request->json('cart_item_ids') ?? [];

        $result = $this->cartService->recalculateSelection($cartSessionId, $memberId, $domainId, $cartItemIds);

        return JsonResponse::success($result->getData(), '');
    }

    /**
     * 체크아웃 배송비 재계산 (POST, JSON)
     *
     * 배송지 우편번호 입력/변경 시 도서산간 추가배송비를 반영해 합계를 다시 계산한다.
     * 청구는 payment()가 서버에서 동일하게 재계산하므로, 이 응답은 표시 갱신용이다.
     */
    public function recalculateShipping(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $shippingZip = trim($request->json('shipping_zip') ?? '');
        $checkoutMode = trim($request->json('checkout_mode') ?? '');

        if ($checkoutMode === 'direct') {
            $directData = $this->directBuyService->getDirectBuyData($domainId);
            if (empty($directData['items'])) {
                return JsonResponse::error('바로구매 정보가 만료되었습니다.');
            }
            $shipping = $this->directBuyService->calculateShipping($domainId, $shippingZip);
            $totalPrice = (int) ($directData['totalPrice'] ?? 0);
        } else {
            $cartSessionId = $this->getCartSession($context);
            $memberId = $this->authService->id() ?? 0;
            $cartItemIds = $request->json('cart_item_ids') ?? [];

            $cartResult = $this->cartService->getItems($cartSessionId, $memberId, $domainId);
            $items = $cartResult->isSuccess() ? ($cartResult->getData()['items'] ?? []) : [];
            if (!empty($cartItemIds)) {
                $selectedIds = array_map('intval', $cartItemIds);
                $items = array_values(array_filter($items, fn($item) =>
                    in_array((int) ($item['cart_item_id'] ?? 0), $selectedIds, true)
                ));
            }
            $totals = $this->cartCheckoutService->calculateTotals($items, $shippingZip);
            return JsonResponse::success([
                'shippingFee'       => $totals['shippingFee'],
                'extraShippingFee'  => $totals['extraShippingFee'] ?? 0,
                'shippingBreakdown' => $totals['shippingBreakdown'] ?? [],
                'grandTotal'        => $totals['grandTotal'],
                'unresolved'        => $totals['unresolved'],
            ], '');
        }

        // 바로구매 응답 구성
        $shippingFee = $shipping['total'];
        return JsonResponse::success([
            'shippingFee'       => $shippingFee,
            'extraShippingFee'  => (int) ($shipping['extra_total'] ?? 0),
            'shippingBreakdown' => $this->cartCheckoutService->buildShippingBreakdown($shipping['groups'] ?? []),
            'grandTotal'        => $totalPrice + (int) ($shippingFee ?? 0),
            'unresolved'        => (bool) ($shipping['unresolved'] ?? false),
        ], '');
    }

    public function prepareCheckout(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $cartSessionId = $this->getCartSession($context);
        $cartItemIds = $request->json('cart_item_ids') ?? [];
        $memberId = $this->authService->id() ?? 0;

        if (empty($cartItemIds)) {
            return JsonResponse::error('주문할 상품을 선택해주세요.');
        }

        $result = $this->cartCheckoutService->prepareCheckout(
            $cartSessionId,
            $cartItemIds,
            $memberId,
            $domainId
        );

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        // 체크아웃 페이지가 선택한 항목만 표시하도록 세션에 저장
        $this->cartCheckoutService->saveCheckoutSelection($cartItemIds);

        return JsonResponse::success(['redirect' => '/shop/checkout'], '체크아웃 준비가 완료되었습니다.');
    }

    /**
     * 체크아웃 페이지
     *
     * ?mode=direct → 바로구매 세션 사용
     * ?guest=1 → 비회원 주문 모드
     * 기본 → 장바구니 아이템 사용
     */
    public function checkout(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $request = $context->getRequest();
        $user = $this->authService->currentUser();
        $isGuest = $request->query('guest') === '1';

        if ($user === null && !$isGuest) {
            // 로그인 후 같은 흐름으로 복귀할 수 있도록 query string 보존
            $passthrough = $request->all();
            unset($passthrough['guest']);
            $checkoutUrl = '/shop/checkout' . ($passthrough ? '?' . http_build_query($passthrough) : '');
            return RedirectResponse::to('/login?redirect=' . urlencode($checkoutUrl) . '&intent=checkout');
        }

        $domainId = $context->getDomainId() ?? 1;
        $cartSessionId = $this->getCartSession($context);
        $memberId = $user ? ($this->authService->id() ?? 0) : 0;
        $mode = $request->query('mode') ?? '';

        // 바로구매 모드: 세션에서 데이터 로드
        if ($mode === 'direct') {
            $directData = $this->directBuyService->getDirectBuyData($domainId);
            if (!$directData) {
                return RedirectResponse::to('/shop/products');
            }
            $cartItems = $directData['items'] ?? [];
            $totals = [
                'totalPrice' => $directData['totalPrice'] ?? 0,
                'shippingFee' => $directData['shippingFee'] ?? 0,
                'totalPoint' => 0,
                'totalQuantity' => array_sum(array_column($cartItems, 'quantity')),
                'grandTotal' => ($directData['totalPrice'] ?? 0) + ($directData['shippingFee'] ?? 0),
                'unresolved' => false,
            ];
        } else {
            // 일반 장바구니 모드
            $cartResult = $this->cartService->getItems($cartSessionId, $memberId, $domainId);
            $cartItems = $cartResult->isSuccess() ? ($cartResult->getData()['items'] ?? []) : [];

            // 장바구니에서 체크한 항목만 결제 (선택 정보는 prepareCheckout에서 세션 저장)
            $selectedIds = $this->cartCheckoutService->getCheckoutSelection();
            if (!empty($selectedIds)) {
                $cartItems = array_values(array_filter(
                    $cartItems,
                    fn($item) => in_array((int) ($item['cart_item_id'] ?? 0), $selectedIds, true)
                ));
            }

            if (empty($cartItems)) {
                return RedirectResponse::to('/shop/cart');
            }

            $totals = $this->cartCheckoutService->calculateTotals($cartItems);
        }

        // 관리자가 선택한 PG만 필터링하여 결제 수단 조회
        $configResult = $this->shopConfigService->getConfig($domainId);
        $shopConfig = $configResult->get('config', []);
        $enabledPgKeys = $this->parseEnabledPgKeys($shopConfig);

        // 관리자가 사용 PG를 하나도 체크하지 않은 경우 프론트 노출도 비움.
        // (PaymentService::getAvailableGateways는 빈 배열을 "전체 반환"으로
        //  해석하므로 그 분기를 타지 않도록 컨트롤러에서 단락)
        if (empty($enabledPgKeys)) {
            $gateways = [];
            $selectedGateway = null;
        } else {
            $gwResult = $this->paymentService->getAvailableGateways($enabledPgKeys);
            $gateways = $gwResult->isSuccess() ? ($gwResult->get('gateways', [])) : [];
            $selectedGateway = $this->paymentService->selectGatewayKey(
                $enabledPgKeys,
                null,
                (string) ($shopConfig['payment_pg_key'] ?? '')
            );
        }

        // 회원 저장 배송지 목록 조회
        $addresses = [];
        $defaultAddress = null;
        if ($memberId > 0) {
            $addrResult = $this->addressService->getList($memberId, $domainId);
            if ($addrResult->isSuccess()) {
                $addresses = $addrResult->get('addresses', []);
                foreach ($addresses as $addr) {
                    if (!empty($addr['is_default'])) {
                        $defaultAddress = $addr;
                        break;
                    }
                }
            }
        }

        // 주문 추가 필드 (활성 필드만)
        $orderFields = $this->orderFieldService->getActiveFields($domainId);

        // 활성화된 PG들의 체크아웃 JS 핸들러 수집
        $checkoutScripts = $this->paymentService->collectCheckoutScripts($enabledPgKeys);

        // 무통장 입금 계좌 (payment_bank_info JSON → 사용여부 + 계좌목록)
        $bankEnabled = false;
        $bankAccounts = [];
        $bankRaw = $shopConfig['payment_bank_info'] ?? '';
        if (is_string($bankRaw) && $bankRaw !== '') {
            $bankDecoded = json_decode($bankRaw, true);
            if (is_array($bankDecoded)) {
                if (isset($bankDecoded['accounts']) && is_array($bankDecoded['accounts'])) {
                    $bankEnabled = !empty($bankDecoded['enabled']);
                    $bankAccounts = $bankDecoded['accounts'];
                } elseif (isset($bankDecoded[0]) && is_array($bankDecoded[0])) {
                    $bankAccounts = $bankDecoded;
                }
            }
        }

        // 포인트 사용(결제) 컨텍스트: 설정 사용여부 + 회원 한도 + 보유 잔액
        $levelValue = $user?->levelValue ?? 0;
        $pointUsage = $this->orderPointService->getUsageContext($domainId, $memberId, $levelValue);

        // 결제 시 필수 동의 약관 (전자상거래법 개인정보/청약철회/거래조건 고지)
        $domainConfig = $context->getDomainInfo()?->getSiteConfig() ?? [];
        $checkoutPolicies = $this->loadCheckoutPolicies($domainId, $shopConfig, $domainConfig);

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Cart/Checkout'))
            ->withData([
                'cartItems'        => $cartItems,
                'totals'           => $totals,
                'gateways'         => $gateways,
                'selectedGateway'  => $selectedGateway,
                'member'           => $user ? [
                    'member_id' => $user->memberId,
                    'user_id' => $user->userId,
                    'nickname' => $user->nickname,
                    'level_value' => $user->levelValue,
                    'avatar' => $user->avatar,
                ] : null,
                'isGuest'          => $isGuest,
                'checkoutMode'     => $mode ?: 'cart',
                'addresses'        => $addresses,
                'defaultAddress'   => $defaultAddress,
                'orderFields'      => $orderFields,
                'checkoutScripts'  => $checkoutScripts,
                'bankEnabled'      => $bankEnabled,
                'bankAccounts'     => $bankAccounts,
                'pointUsage'       => $pointUsage,
                'checkoutPolicies' => $checkoutPolicies,
            ]);
    }

    /**
     * 결제 준비 (POST, JSON)
     *
     * 주문 생성 → PG prepare → 프론트에 결제 정보 반환
     * 프론트에서 PG 결제창을 열고, 완료 후 verify() 호출
     */
    public function payment(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $user = $this->authService->currentUser();
        $isGuest = (bool) $request->json('is_guest');

        if ($user === null && !$isGuest) {
            return JsonResponse::error('로그인이 필요합니다.', null, 401);
        }

        $domainId = $context->getDomainId() ?? 1;
        $cartSessionId = $this->getCartSession($context);
        $memberId = $user ? ($this->authService->id() ?? 0) : 0;

        $requestedGateway = trim($request->json('payment_gateway') ?? '');
        $paymentMethod = trim($request->json('payment_method') ?? '');
        $checkoutMode = trim($request->json('checkout_mode') ?? '');
        $cartItemIds = $request->json('cart_item_ids') ?? [];
        $pointUsed = max(0, (int) ($request->json('point_used') ?? 0));
        // 복수 쿠폰: coupon_ids(배열) 우선, 없으면 단일 coupon_id 폴백(하위호환)
        $couponIdsInput = $request->json('coupon_ids');
        if (is_array($couponIdsInput)) {
            $couponIds = array_values(array_filter(array_map('intval', $couponIdsInput), fn($id) => $id > 0));
        } else {
            $single = max(0, (int) ($request->json('coupon_id') ?? 0));
            $couponIds = $single > 0 ? [$single] : [];
        }

        // 주문인 정보 — 회원·비회원 공통으로 수집한다. (이메일은 추후 메일 발송과 함께 재도입 예정)
        // 회원도 가입 시 연락처를 받지 않으므로 결제 단계에서 직접 입력받는다.
        // 비회원 주문조회는 "주문인명 + 연락처"로 수행하므로 둘 다 필수.
        $ordererName = trim($request->json('orderer_name') ?? '');
        $ordererPhone = trim($request->json('orderer_phone') ?? '');

        if ($ordererName === '') {
            return JsonResponse::error('주문인을 입력해주세요.');
        }
        if ($ordererPhone === '') {
            return JsonResponse::error('주문인 연락처를 입력해주세요.');
        }

        // 배송 정보
        $shippingData = [
            'recipient_name'   => trim($request->json('recipient_name') ?? ''),
            'recipient_phone'  => trim($request->json('recipient_phone') ?? ''),
            'shipping_zip'     => trim($request->json('shipping_zip') ?? ''),
            'shipping_address1' => trim($request->json('shipping_address1') ?? ''),
            'shipping_address2' => trim($request->json('shipping_address2') ?? ''),
            'order_memo'       => trim($request->json('order_memo') ?? ''),
        ];

        if ($paymentMethod === '') {
            return JsonResponse::error('결제 수단을 선택해주세요.');
        }

        $configResult = $this->shopConfigService->getConfig($domainId);
        $shopConfig = $configResult->get('config', []);

        // 무통장입금: PG 게이트웨이가 필요 없는 수동 결제 수단
        $isBankTransfer = ($paymentMethod === 'BANK');
        $bankAccountInfo = null;  // 무통장 선택 계좌 정보 (JSON 직렬화 전 array)

        if ($isBankTransfer) {
            $paymentGateway = '';

            // 고객이 선택한 입금계좌 인덱스 → shop config의 계좌 목록에서 lookup
            $bankAccountIndex = $request->json('bank_account_index');
            if ($bankAccountIndex !== null && $bankAccountIndex !== '') {
                $bankAccountIndex = (int) $bankAccountIndex;
                $bankRaw = $shopConfig['payment_bank_info'] ?? '';
                $accounts = [];
                if ($bankRaw) {
                    $decoded = json_decode($bankRaw, true);
                    if (is_array($decoded)) {
                        $accounts = (isset($decoded['accounts']) && is_array($decoded['accounts']))
                            ? $decoded['accounts']
                            : $decoded;
                    }
                }
                if (isset($accounts[$bankAccountIndex]) && is_array($accounts[$bankAccountIndex])) {
                    $acc = $accounts[$bankAccountIndex];
                    $bankAccountInfo = [
                        'bank'    => (string) ($acc['bank'] ?? ''),
                        'account' => (string) ($acc['account'] ?? ''),
                        'holder'  => (string) ($acc['holder'] ?? ''),
                    ];
                }
            }
        } else {
            $enabledPgKeys = $this->parseEnabledPgKeys($shopConfig);
            $paymentGateway = $this->paymentService->selectGatewayKey(
                $enabledPgKeys,
                $requestedGateway,
                (string) ($shopConfig['payment_pg_key'] ?? '')
            );

            if ($paymentGateway === null || $paymentGateway === '') {
                return JsonResponse::error('사용 가능한 결제 게이트웨이가 없습니다.');
            }
        }

        if (empty($shippingData['recipient_name'])) {
            return JsonResponse::error('수령인 이름을 입력해주세요.');
        }

        if (empty($shippingData['shipping_address1'])) {
            return JsonResponse::error('배송 주소를 입력해주세요.');
        }

        // 배송비는 클라이언트 값을 믿지 않고 서버에서 재계산하여 청구한다.
        // 배송지 우편번호로 도서산간 추가배송비를 반영한다.
        $shippingZip = $shippingData['shipping_zip'];
        $shippingFee = 0;
        $shippingBreakdown = [];
        // 바로구매 모드: CartService 대신 DirectBuyService 세션 데이터 사용
        if ($checkoutMode === 'direct') {
            $directData = $this->directBuyService->getDirectBuyData($domainId);
            if (empty($directData['items'])) {
                return JsonResponse::error('바로구매 정보가 만료되었습니다. 다시 시도해주세요.');
            }
            $allItems = $directData['items'];

            $shipping = $this->directBuyService->calculateShipping($domainId, $shippingZip);
            if ($shipping === null || !empty($shipping['unresolved'])) {
                return JsonResponse::error('배송 정책이 설정되지 않았습니다. 관리자에게 문의해주세요.');
            }
            $shippingFee = (int) ($shipping['total'] ?? 0);
            $shippingBreakdown = $this->cartCheckoutService->buildShippingBreakdown($shipping['groups'] ?? []);
        } else {
            // 장바구니 아이템 조회
            $cartResult = $this->cartService->getItems($cartSessionId, $memberId, $domainId);
            $allItems = $cartResult->isSuccess() ? ($cartResult->getData()['items'] ?? []) : [];

            if (empty($allItems)) {
                return JsonResponse::error('장바구니에 상품이 없습니다.');
            }

            // cart_item_ids로 필터링 (지정된 경우)
            if (!empty($cartItemIds)) {
                $selectedIds = array_map('intval', $cartItemIds);
                $allItems = array_filter($allItems, fn($item) =>
                    in_array((int) ($item['cart_item_id'] ?? 0), $selectedIds)
                );
                $allItems = array_values($allItems);
            }

            if (empty($allItems)) {
                return JsonResponse::error('주문할 상품을 선택해주세요.');
            }

            $cartTotals = $this->cartCheckoutService->calculateTotals($allItems, $shippingZip);
            if (!empty($cartTotals['unresolved'])) {
                return JsonResponse::error('배송 정책이 설정되지 않았습니다. 관리자에게 문의해주세요.');
            }
            $shippingFee = (int) ($cartTotals['shippingFee'] ?? 0);
            $shippingBreakdown = $cartTotals['shippingBreakdown'] ?? [];
        }

        // 주문 추가 필드 검증
        $orderFieldValues = $request->json('order_fields') ?? [];
        if (!is_array($orderFieldValues)) {
            return JsonResponse::error('주문 추가 필드 형식이 올바르지 않습니다.');
        }
        $validateResult = $this->orderFieldService->validateValues($domainId, $orderFieldValues);
        if ($validateResult->isFailure()) {
            return JsonResponse::error($validateResult->getMessage());
        }

        $memberLevel = $user?->levelValue ?? 0;
        $productTotal = (int) ($this->cartCheckoutService->calculateTotals($allItems)['totalPrice'] ?? 0);

        // 쿠폰 적용(비파괴 미리보기) — 할인액을 먼저 확정해야 결제금액·포인트 한도가 맞음.
        // 실제 사용 처리(마킹)는 주문 생성 후 useCoupon()으로 수행.
        $couponDiscount = 0;
        $couponBreakdown = [];
        $couponItems = [];
        if (!empty($couponIds)) {
            if ($isGuest || $memberId <= 0) {
                return JsonResponse::error('비회원은 쿠폰을 사용할 수 없습니다.');
            }
            $couponItems = array_map(fn($it) => [
                'goods_id'      => (int) ($it['product']['goods_id'] ?? $it['goods_id'] ?? 0),
                'category_code' => (string) ($it['product']['category_code'] ?? $it['category_code'] ?? ''),
            ], $allItems);
            $couponPreview = $this->couponService->previewCouponStack(
                $couponIds, $productTotal, $memberId, $memberLevel, $couponItems, (int) $shippingFee
            );
            if ($couponPreview->isFailure()) {
                return JsonResponse::error($couponPreview->getMessage());
            }
            $couponDiscount = (int) $couponPreview->get('total_discount', 0);
            $couponBreakdown = (array) $couponPreview->get('breakdown', []);
        }

        // 포인트 사용(결제) 검증 — 단위/최소·최대/잔액/(쿠폰 적용 후) 주문금액
        if ($pointUsed > 0) {
            if ($isGuest || $memberId <= 0) {
                return JsonResponse::error('비회원은 포인트를 사용할 수 없습니다.');
            }
            $payableBeforePoint = max(0, $productTotal + (int) $shippingFee - $couponDiscount);
            $pointCtx = $this->orderPointService->getUsageContext($domainId, $memberId, $memberLevel);
            $pointValidation = $this->orderPointService->validate($pointUsed, $pointCtx, $payableBeforePoint);
            if ($pointValidation->isFailure()) {
                return JsonResponse::error($pointValidation->getMessage());
            }
        }

        // 0원 결제 판정(서버 권위): 쿠폰/포인트로 결제금액이 전액 상계되면 PG 승인이 불필요하다.
        // 배송비가 남으면 0원이 아니므로 그대로 PG/무통장 경로를 탄다.
        // 0원이면 결제수단 분기(무통장/PG)를 우회하고, 0원 전용 결제수단(FREE)으로 기록한 뒤
        // 주문 생성 직후 즉시 PAID 로 확정한다(아래 finalizeZeroAmountPayment).
        $payableAmount = $productTotal + (int) $shippingFee - $couponDiscount - $pointUsed;
        $isZeroPayment = ($payableAmount <= 0);
        if ($isZeroPayment) {
            $paymentGateway = '';                          // PG 우회
            $paymentMethod = PaymentMethod::FREE->value;   // 주문상세에 '0원 결제'로 표기
            $isBankTransfer = false;                       // 무통장 분기(입금대기/선차감) 미적용
            $bankAccountInfo = null;
        }

        // 필수 동의 약관 검증 + 동의 스냅샷 (전자상거래법: 개인정보 수집·이용/청약철회/거래조건 고지)
        // 관리자가 checkout_policies에 지정한 약관은 모두 동의해야 결제를 진행할 수 있다.
        // 클라이언트 검증과 무관하게 서버가 최종 권위이며, 동의 내역을 주문에 스냅샷으로 보존한다.
        $agreedPoliciesJson = null;
        $requiredPolicies = $this->loadCheckoutPolicies(
            $domainId, $shopConfig, $context->getDomainInfo()?->getSiteConfig() ?? []
        );
        if (!empty($requiredPolicies)) {
            $agreedIds = array_map('intval', (array) ($request->json('agreements') ?? []));
            $snapshot = [];
            foreach ($requiredPolicies as $p) {
                if (!in_array($p['policy_id'], $agreedIds, true)) {
                    return JsonResponse::error('필수 약관에 모두 동의해주세요.');
                }
                $snapshot[] = [
                    'policy_id' => $p['policy_id'],
                    'revision_id' => $p['revision_id'],
                    'version'   => $p['version'],
                    'content_hash' => $p['content_hash'],
                    'title'     => $p['title'],
                    'agreed_at' => date('Y-m-d H:i:s'),
                ];
            }
            $agreedPoliciesJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        }

        // 주문 생성
        $orderPayload = [
            'cart_session_id'   => $cartSessionId,
            'payment_gateway'   => $paymentGateway,
            'payment_method'    => $paymentMethod,
            'shipping_fee'      => $shippingFee,
            'shipping_breakdown' => !empty($shippingBreakdown) ? json_encode($shippingBreakdown, JSON_UNESCAPED_UNICODE) : null,
            'agreed_policies'   => $agreedPoliciesJson,
            'point_used'        => $pointUsed,
            'coupon_id'         => !empty($couponBreakdown) ? (int) $couponBreakdown[0]['coupon_id'] : null,
            'coupon_discount'   => $couponDiscount,
            'coupon_breakdown'  => !empty($couponBreakdown) ? json_encode($couponBreakdown, JSON_UNESCAPED_UNICODE) : null,
            'recipient_name'    => $shippingData['recipient_name'],
            'recipient_phone'   => $shippingData['recipient_phone'],
            'shipping_zip'      => $shippingData['shipping_zip'],
            'shipping_address1' => $shippingData['shipping_address1'],
            'shipping_address2' => $shippingData['shipping_address2'],
            'order_memo'        => $shippingData['order_memo'],
            'campaign_key'      => $this->session->get(TrackingKeys::CAMPAIGN_KEY),
        ];
        // 주문인 정보는 회원·비회원 모두 입력값을 그대로 기록한다.
        // (수령인 폴백에 의존하지 않으므로 주문인 ≠ 수령인이 정확히 보존됨)
        $orderPayload['orderer_name'] = $ordererName;
        $orderPayload['orderer_phone'] = $ordererPhone;

        // 무통장입금 선택 계좌 (JSON 직렬화하여 단일 컬럼 저장)
        if ($bankAccountInfo !== null) {
            $orderPayload['bank_account_info'] = json_encode($bankAccountInfo, JSON_UNESCAPED_UNICODE);
        }

        $orderResult = $this->orderService->createOrder($domainId, $memberId, $orderPayload, $allItems);

        if ($orderResult->isFailure()) {
            return JsonResponse::error($orderResult->getMessage());
        }

        $orderNo = $orderResult->get('order_no', '');
        $pointOrder = [
            'order_no' => $orderNo,
            'member_id' => $memberId,
            'domain_id' => $domainId,
            'point_used' => $pointUsed,
        ];

        // 결제수단과 무관하게 주문 생성 직후 포인트를 선점한다. 잔액 조정은 회원 행을
        // 잠그므로 동시에 생성된 두 주문이 같은 포인트를 모두 사용하는 일을 막는다.
        if ($pointUsed > 0) {
            $holdResult = $this->orderPointService->hold($pointOrder);
            if ($holdResult->isFailure()) {
                $this->orderService->updateStatus(
                    $orderNo, OrderAction::CANCELLED->value, $domainId,
                    '포인트 선점 실패: ' . $holdResult->getMessage(), 'SYSTEM'
                );
                return JsonResponse::error($holdResult->getMessage());
            }
        }

        // 주문 생성 직후 추가필드를 최종 경계에서 재검증·저장한다.
        // 실패하면 방금 선점한 포인트를 해제하고 주문을 취소한다.
        if (!empty($orderFieldValues)) {
            $fieldSaveResult = $this->orderFieldService->saveValues($orderNo, $domainId, $orderFieldValues);
            if ($fieldSaveResult->isFailure()) {
                $this->orderPointService->release($pointOrder);
                $this->orderService->updateStatus(
                    $orderNo,
                    OrderAction::CANCELLED->value,
                    $domainId,
                    '주문 추가 필드 저장 실패: ' . $fieldSaveResult->getMessage(),
                    'SYSTEM'
                );
                return JsonResponse::error($fieldSaveResult->getMessage());
            }
        }

        // 회원 "기본 배송지로 설정": 주문 생성 성공 후 배송지를 주소록에 기본으로 저장한다.
        // 실패해도 주문 흐름은 계속한다(배송지 저장은 부가 기능).
        if (!$isGuest && $memberId > 0 && (int) ($request->json('set_default') ?? 0) === 1) {
            $this->addressService->saveDefaultFromCheckout($memberId, $domainId, [
                'recipient_name'  => $shippingData['recipient_name'],
                'recipient_phone' => $shippingData['recipient_phone'],
                'zip_code'        => $shippingData['shipping_zip'],
                'address1'        => $shippingData['shipping_address1'],
                'address2'        => $shippingData['shipping_address2'],
            ]);
        }

        // 쿠폰 사용 처리(마킹) — 주문번호 확정 직후, PG·무통장 모두 즉시 claim한다.
        // PG도 생성 시점에 claim해야 결제창 대기 중(미결제 ATTEMPTING) 같은 단일사용 쿠폰이
        // 다른 주문에 중복 적용되어 결제되는 것을 막는다(previewCoupon이 is_used=1을 거부).
        // 미결제로 이탈한 주문은 취소 시 CouponRestoreSubscriber가 쿠폰을 복원하며,
        // PaymentCompletionService의 결제 완료 재마킹은 같은 주문번호에 대해 멱등 처리된다.
        // 0원 결제는 동일 요청 내에서 즉시 확정(finalizeZeroAmountPayment→이벤트 마킹)되어 창이 없으므로 제외.
        // 실패 시 주문 취소. (취소/반품 시 복원은 CouponRestoreSubscriber가 담당)
        if (!empty($couponBreakdown) && !$isZeroPayment) {
            $useResult = $this->couponService->markCouponsUsedForOrder($orderNo, $couponBreakdown);
            if ($useResult->isFailure()) {
                if ($pointUsed > 0) {
                    $this->orderPointService->release($pointOrder);
                }
                $this->orderService->updateStatus(
                    $orderNo, OrderAction::CANCELLED->value, $domainId,
                    '쿠폰 사용 처리 실패: ' . $useResult->getMessage(), 'SYSTEM'
                );
                return JsonResponse::error($useResult->getMessage());
            }
        }

        // 비회원 주문 소유권: 세션에 order_no를 기록해 verify/complete에서 검증
        if ($isGuest && $orderNo !== '') {
            $this->rememberGuestOrder($orderNo);
        }

        // 주문에 사용된 cart_item_ids 세션 저장 (verify에서 해당 아이템만 ORDERED 처리)
        $usedCartItemIds = array_filter(array_map(
            fn($item) => (int) ($item['cart_item_id'] ?? 0),
            $allItems
        ));
        if (!empty($usedCartItemIds)) {
            $this->cartCheckoutService->saveOrderCartItems($orderNo, array_values($usedCartItemIds));
        }

        // 0원 결제: 쿠폰/포인트로 전액 상계되어 PG 승인이 불필요.
        // 주문은 RECEIVED 로 생성되었으며, 여기서 즉시 PAID 로 확정한다.
        // 쿠폰 사용처리·장바구니 ORDERED 는 결제 완료 파이프라인이 처리하고,
        // 포인트는 위에서 이미 선점했으므로 완료 단계에서는 선점 이력만 확인한다.
        if ($isZeroPayment) {
            $finalizeResult = $this->paymentService->finalizeZeroAmountPayment($orderNo, $domainId);
            if ($finalizeResult->isFailure()) {
                $this->orderPointService->release($pointOrder);
                // 확정 실패 → 주문 취소(RECEIVED→CANCELLED).
                $this->orderService->updateStatus(
                    $orderNo, OrderAction::CANCELLED->value, $domainId,
                    '0원 결제 확정 실패: ' . $finalizeResult->getMessage(), 'SYSTEM'
                );
                return JsonResponse::error($finalizeResult->getMessage());
            }

            return JsonResponse::success([
                'order_no' => $orderNo,
                'paid'     => true,   // 프론트: PG 결제창 없이 즉시 완료 → redirect 처리
                'redirect' => '/shop/order/' . $orderNo . '/complete',
            ], '주문이 완료되었습니다.');
        }

        // 무통장입금: PG 결제창 없이 RECEIVED(주문접수) 상태로 완료.
        // 관리자가 입금 확인 후 RECEIVED→PAID로 전이한다.
        if ($isBankTransfer) {
            // 장바구니 정리 (PENDING → ORDERED)
            $orderCartItemIds = $this->cartCheckoutService->getOrderCartItems($orderNo);
            $this->cartCheckoutService->markOrdered($cartSessionId, $orderCartItemIds);

            return JsonResponse::success([
                'order_no' => $orderNo,
                'redirect' => '/shop/order/' . $orderNo . '/complete',
            ], '주문이 접수되었습니다. 입금 안내를 확인해주세요.');
        }

        // 주문명 생성 (PG 결제창 표시용)
        // CartService: $item['product']['goods_name'], DirectBuyService: $item['goods_name']
        $firstName = $allItems[0]['product']['goods_name'] ?? $allItems[0]['goods_name'] ?? '상품';
        $itemCount = count($allItems);
        $orderName = $itemCount > 1
            ? $firstName . ' 외 ' . ($itemCount - 1) . '건'
            : $firstName;

        // PG가 redirect/feedback URL을 자체 생성할 때 사용할 base URL 추출
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $baseUrl = $host !== '' ? ($scheme . '://' . $host) : '';
        $successUrl = '/shop/order/' . $orderNo . '/complete';
        $failUrl = '/shop/checkout';

        $paymentAmount = (int) $orderResult->get('payment_amount', 0);

        // PG 결제 준비 (prepare)
        $prepareResult = $this->paymentService->processPayment($paymentGateway, [
            'order_no'        => $orderNo,
            'amount'          => $paymentAmount,
            'payment_method'  => $paymentMethod,
            'order_name'      => $orderName,
            'customer_name'   => $ordererName,
            'customer_phone'  => $ordererPhone,
            'domain_id'       => $domainId,
            // 결제 결과를 되돌려받을 소비자 키 — PG 콜백이 이 값으로 PaymentConsumerInterface 를
            // 조회한다. 플러그인은 값을 옮기기만 하고 어느 패키지인지 알지 못한다.
            'consumer'        => 'shop',
            'base_url'        => $baseUrl,
            'success_url'     => $baseUrl !== '' ? ($baseUrl . $successUrl) : $successUrl,
            'fail_url'        => $baseUrl !== '' ? ($baseUrl . $failUrl) : $failUrl,
        ]);

        if ($prepareResult->isFailure()) {
            // PG 준비 실패 → 주문을 ATTEMPTING 으로 유지 (취소하지 않음).
            // 결제창 종료/중단(verify 미호출)과 동일하게 결제 미완료로 보고,
            // ATTEMPTING 만료 스윕에 정리를 맡긴다. PG 거부(예: 최소금액 미달)로
            // 곧바로 CANCELLED 가 찍혀 주문내역에 노출되던 문제를 방지.
            // 단, 상태는 그대로 두되 사유는 이력에 남겨 추적 가능하게 한다.
            $this->orderService->logEvent(
                $orderNo, $domainId,
                'PG 준비 실패: ' . $prepareResult->getMessage(), 'PAYMENT', 'SYSTEM'
            );
            return JsonResponse::error($prepareResult->getMessage());
        }

        $prepareData = $prepareResult->getData();
        $transactionId = (string) ($prepareData['transaction_id'] ?? '');

        // 프론트에 결제 정보 반환 (프론트가 PG 결제창 오픈)
        // pg_response: PG가 prepare에서 반환한 결제창 호출에 필요한 데이터 (mul_no, payurl 등)
        // status_url/status_payload: 팝업 PG가 결제창 종료 후 결제 상태 확인용
        return JsonResponse::success([
            'order_no'       => $orderNo,
            'order_name'     => $orderName,
            'gateway'        => $paymentGateway,
            'transaction_id' => $transactionId,
            'amount'         => $prepareData['amount'] ?? $paymentAmount,
            'pg_response'    => $prepareData['pg_response'] ?? [],
            'client_config'  => $this->paymentService->getClientConfig($paymentGateway),
            'success_url'    => $successUrl,
            'fail_url'       => $failUrl,
            'status_url'     => '/shop/checkout/verify',
            'status_payload' => [
                'order_no'        => $orderNo,
                'payment_gateway' => $paymentGateway,
                'transaction_id' => $transactionId,
            ],
        ], '결제를 진행해주세요.');
    }

    /**
     * 결제 설정의 활성 PG 키 목록 파싱
     *
     * @return string[]
     */
    private function parseEnabledPgKeys(array $shopConfig): array
    {
        if (empty($shopConfig['payment_pg_keys'])) {
            return [];
        }

        $decoded = is_string($shopConfig['payment_pg_keys'])
            ? json_decode($shopConfig['payment_pg_keys'], true)
            : $shopConfig['payment_pg_keys'];

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded)));
    }

    /**
     * 결제 검증 (POST, JSON)
     *
     * PG 결제창 완료 후 프론트에서 호출
     * PaymentService.verifyPayment()가 소유권/이중결제/금액 검증 + 상태 전이를 일괄 처리
     */
    public function verify(array $params, Context $context): JsonResponse
    {
        $user = $this->authService->currentUser();
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $orderNo = trim($request->json('order_no') ?? '');
        $paymentGateway = trim($request->json('payment_gateway') ?? '');
        $transactionId = trim($request->json('transaction_id') ?? '');

        if ($orderNo === '' || $transactionId === '') {
            return JsonResponse::error('결제 정보가 올바르지 않습니다.');
        }

        if ($user === null) {
            // 비회원 주문: 같은 세션에서 생성한 주문번호인지 검증
            if (!$this->isGuestOrderOwner($orderNo)) {
                return JsonResponse::error('로그인이 필요합니다.', null, 401);
            }
            $memberId = 0;
        } else {
            $memberId = $this->authService->id() ?? 0;
        }

        // PG 결제 검증 + 상태 전이 (소유권/이중결제/금액 검증 + ATTEMPTING→PAID 활성화 포함)
        $verifyResult = $this->paymentService->verifyPayment(
            $paymentGateway, $transactionId, $orderNo, $memberId, $domainId
        );

        if ($verifyResult->isFailure()) {
            return JsonResponse::error($verifyResult->getMessage());
        }

        $verifyData = $verifyResult->getData();

        if (empty($verifyData['success'])) {
            return JsonResponse::error('결제 검증에 실패했습니다.');
        }

        // 장바구니 상태 변경 (PENDING → ORDERED) — 해당 주문 아이템만
        $cartSessionId = $this->getCartSession($context);
        $orderCartItemIds = $this->cartCheckoutService->getOrderCartItems($orderNo);
        $this->cartCheckoutService->markOrdered($cartSessionId, $orderCartItemIds);

        return JsonResponse::success([
            'order_no' => $orderNo,
            'redirect' => '/shop/order/' . $orderNo . '/complete',
        ], '결제가 완료되었습니다.');
    }

    /**
     * 주문 추가 필드 파일 업로드 (AJAX)
     *
     * POST /shop/checkout/upload-file
     */
    public function uploadFieldFile(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $fieldId = (int) ($request->post('field_id') ?? 0);

        $field = $this->orderFieldService->getField($domainId, $fieldId, true);
        if (!$field || $field['field_type'] !== 'file') {
            return JsonResponse::error('유효하지 않은 필드입니다.');
        }

        $file = UploadedFile::fromGlobal('file');
        if (!$file || !$file->isValid()) {
            return JsonResponse::error($file ? $file->getErrorMessage() : '파일이 업로드되지 않았습니다.');
        }

        $config = json_decode($field['field_config'] ?? '{}', true) ?: [];

        // OrderFieldService에 주입된 파일 관리 계약 사용
        $fileHandler = $this->getFileHandler();
        if (!$fileHandler) {
            return JsonResponse::error('파일 업로드 기능을 사용할 수 없습니다.');
        }

        $result = $fileHandler->uploadTemp($file, $domainId, $config);

        if (!$result->isSuccess()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success(
            $fileHandler->buildTempResponse($result),
            '파일이 업로드되었습니다.'
        );
    }

    /**
     * 비회원 주문 세션 키
     */
    private const GUEST_ORDER_SESSION_KEY = 'shop_guest_order_nos';

    /**
     * 비회원 주문 번호를 세션에 기록 (verify/complete 시 소유권 검증용)
     */
    private function rememberGuestOrder(string $orderNo): void
    {
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
     * 현재 세션이 해당 비회원 주문번호의 소유자인지 확인
     */
    private function isGuestOrderOwner(string $orderNo): bool
    {
        $list = $this->session->get(self::GUEST_ORDER_SESSION_KEY, []);
        return is_array($list) && in_array($orderNo, $list, true);
    }

    /**
     * 주문 필드 파일 관리 계약 접근 (OrderFieldService 내부에서 가져옴)
     */
    private function getFileHandler(): ?\Mublo\Contract\CustomField\CustomFieldFileManagerInterface
    {
        // OrderFieldService 가 이미 주입받은 인스턴스를 그대로 쓴다.
        // (과거에는 Application::getInstance()->getContainer() 로 꺼내려 했으나 두 메서드 모두
        //  존재하지 않아 catch(\Throwable) 에 삼켜졌고, 파일 업로드가 항상 비활성으로 보였다)
        return $this->orderFieldService->getFileHandler();
    }
}
