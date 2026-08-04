<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Infrastructure\Database\DatabaseException;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\CartRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Entity\Order;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Enum\ClaimStatus;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\Event\OrderItemStatusChangedEvent;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Mublo\Infrastructure\Cache\CacheInterface;

/**
 * OrderService
 *
 * 주문 비즈니스 로직 + 이벤트 발행
 *
 * 책임:
 * - 주문 생성 (주문번호 생성, 주문+주문상세 저장, 장바구니 상태 변경)
 * - 주문 조회 (단일, 목록, 회원별)
 * - 주문 상태 전이 관리
 *
 * 금지:
 * - Request/Response 직접 처리 (Controller 담당)
 * - DB 직접 접근 (Repository 담당)
 */
class OrderService
{
    /**
     * 후기 작성 검증에 사용할 현재 도메인의 주문 상세/헤더를 함께 조회한다.
     *
     * @return array{item:array,order:array}|null
     */
    public function getReviewSource(int $domainId, int $orderDetailId): ?array
    {
        $item = $this->orderRepository->getItemInDomain($domainId, $orderDetailId);
        if ($item === null) {
            return null;
        }

        $order = $this->orderRepository->findByOrderNoInDomain(
            $domainId,
            (string) ($item['order_no'] ?? '')
        );
        if ($order === null) {
            return null;
        }

        return ['item' => $item, 'order' => $order];
    }
    private const ORDER_NO_MAX_ATTEMPTS = 5;

    /**
     * attempting 만료 스윕: 이 시간(분)보다 오래된 미결제만 대상.
     * PG 승인 지연·콜백 재시도(feedback FAIL→재전송)가 완료될 여유를 두어야
     * '결제됨/주문 삭제됨'을 피할 수 있다. 30분은 너무 공격적이라 3시간으로 둔다.
     * (SUCCESS 결제 트랜잭션이 있는 건은 어차피 삭제 대상에서 제외됨)
     */
    private const SWEEP_STALE_MINUTES = 180;
    /** attempting 만료 스윕: 접점 1회 호출당 최대 삭제 건수 */
    private const SWEEP_BATCH = 200;
    /** attempting 만료 스윕: 접점 스로틀(초) — 도메인당 이 주기로만 실제 실행 */
    private const SWEEP_THROTTLE_TTL = 900;
    /** 자동 구매확정 스윕: 접점 스로틀(초) — 도메인당 이 주기로만 실제 실행 (1시간) */
    private const AUTO_CONFIRM_THROTTLE_TTL = 3600;
    /** 자동 구매확정 스윕: 접점 1회 호출당 최대 처리 건수 */
    private const AUTO_CONFIRM_BATCH = 200;

    private OrderRepository $orderRepository;
    private CartRepository $cartRepository;
    private ProductRepository $productRepository;
    private ProductOptionRepository $productOptionRepository;
    private PriceCalculator $priceCalculator;
    private OrderStateResolver $stateResolver;
    private ?EventDispatcher $eventDispatcher;
    private ?SensitiveValueCodecInterface $encryptionService;
    private ?CacheInterface $cache;
    private ?ShopConfigService $shopConfigService;
    private ?CouponService $couponService;

    /** 암호화 대상 필드 (주문인 + 수령인 + 배송지) */
    private const ENCRYPTED_FIELDS = [
        'orderer_name',
        'orderer_phone',
        'orderer_email',
        'recipient_name',
        'recipient_phone',
        'shipping_zip',
        'shipping_address1',
        'shipping_address2',
    ];

    /** Blind Index 매핑 (검색 가능 필드 → 인덱스 컬럼명) */
    private const INDEXED_FIELDS = [
        'orderer_name'   => 'orderer_name_index',
        'orderer_phone'  => 'orderer_phone_index',
        'recipient_name' => 'recipient_name_index',
        'recipient_phone' => 'recipient_phone_index',
    ];

    public function __construct(
        OrderRepository $orderRepository,
        CartRepository $cartRepository,
        ProductRepository $productRepository,
        ProductOptionRepository $productOptionRepository,
        PriceCalculator $priceCalculator,
        OrderStateResolver $stateResolver,
        ?EventDispatcher $eventDispatcher = null,
        ?SensitiveValueCodecInterface $encryptionService = null,
        ?CacheInterface $cache = null,
        ?ShopConfigService $shopConfigService = null,
        ?CouponService $couponService = null
    ) {
        $this->orderRepository = $orderRepository;
        $this->cartRepository = $cartRepository;
        $this->productRepository = $productRepository;
        $this->productOptionRepository = $productOptionRepository;
        $this->priceCalculator = $priceCalculator;
        $this->stateResolver = $stateResolver;
        $this->eventDispatcher = $eventDispatcher;
        $this->encryptionService = $encryptionService;
        $this->cache = $cache;
        $this->shopConfigService = $shopConfigService;
        $this->couponService = $couponService;
    }

    /**
     * 이벤트 발행 헬퍼
     *
     * @template T of EventInterface
     * @param T $event
     * @return T
     */
    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }

    /**
     * 주문 생성
     *
     * 주문번호 생성 -> 주문 레코드 생성 -> 주문 상세 아이템 생성 -> 장바구니 상태 변경
     *
     * @param int $domainId 도메인 ID
     * @param int $memberId 회원 ID
     * @param array $orderData 주문 데이터 (배송지, 결제수단, 메모 등)
     * @param array $items 주문 상품 목록 (cart_item_id 기반 또는 상품 데이터)
     * @return Result 성공 시 order_no 포함
     */
    public function createOrder(int $domainId, int $memberId, array $orderData, array $items): Result
    {
        if (empty($items)) {
            return Result::failure('주문할 상품이 없습니다.');
        }

        // 주문 아이템 검증 및 금액 계산
        $itemTemplates = [];
        $totalPrice = 0;

        foreach ($items as $item) {
            $itemResult = $this->validateAndBuildOrderItem($item, '', $domainId);
            if ($itemResult->isFailure()) {
                return $itemResult;
            }
            $itemTemplates[] = $itemResult->get('order_item');
            $totalPrice += $itemResult->get('item_total', 0);
        }

        // 배송비 (주문 데이터에 포함된 경우 사용, 아니면 기본값)
        $shippingFee = (int) ($orderData['shipping_fee'] ?? 0);

        // 주문 데이터 구성
        $orderRecordTemplate = [
            'domain_id' => $domainId,
            'cart_session_id' => $orderData['cart_session_id'] ?? null,
            'member_id' => $memberId,
            'orderer_name' => $orderData['orderer_name'] ?? $orderData['recipient_name'] ?? '',
            'orderer_phone' => $orderData['orderer_phone'] ?? $orderData['recipient_phone'] ?? '',
            'orderer_email' => $orderData['orderer_email'] ?? null,
            'total_price' => $totalPrice,
            'extra_price' => (int) ($orderData['extra_price'] ?? 0),
            'point_used' => (int) ($orderData['point_used'] ?? 0),
            'coupon_discount' => (int) ($orderData['coupon_discount'] ?? 0),
            'coupon_id' => $orderData['coupon_id'] ?? null,
            'coupon_breakdown' => $orderData['coupon_breakdown'] ?? null,
            'shipping_fee' => $shippingFee,
            'shipping_breakdown' => $orderData['shipping_breakdown'] ?? null,
            'agreed_policies' => $orderData['agreed_policies'] ?? null,
            'tax_amount' => (int) ($orderData['tax_amount'] ?? 0),
            'shipping_zip' => $orderData['shipping_zip'] ?? null,
            'shipping_address1' => $orderData['shipping_address1'] ?? null,
            'shipping_address2' => $orderData['shipping_address2'] ?? null,
            'recipient_name' => $orderData['recipient_name'] ?? null,
            'recipient_phone' => $orderData['recipient_phone'] ?? null,
            'payment_gateway' => $orderData['payment_gateway'] ?? null,
            'payment_method' => $orderData['payment_method'] ?? 'BANK',
            'bank_account_info' => $orderData['bank_account_info'] ?? null,
            // 출생 상태: PG 결제는 결제창 호출용으로 먼저 생성되므로 내부 과도상태(ATTEMPTING)로
            // 시작하고 결제 성공(verify) 시 PAID로 활성화한다. 무통장입금은 추가 결제행동이
            // 없는 정상 접수이므로 곧장 RECEIVED(주문접수 — pre-paid 홈 상태). 결제 이탈 고아는
            // attempting으로 구분되어 RECEIVED(살아있는 주문)와 섞이지 않는다.
            'order_status' => empty($orderData['payment_gateway'])
                ? OrderAction::RECEIVED->value
                : OrderAction::ATTEMPTING->value,
            'order_memo' => $orderData['order_memo'] ?? null,
            'is_direct_order' => (int) (bool) ($orderData['is_direct_order'] ?? false),
            'campaign_key' => isset($orderData['campaign_key']) ? mb_substr($orderData['campaign_key'], 0, 100) : null,
        ];

        // 개인정보 암호화 (주문인/수령인/배송지)
        $this->encryptOrderFields($orderRecordTemplate);

        // DB 트랜잭션: 주문 + 주문상세 원자적 생성
        $db = $this->orderRepository->getDb();
        $orderNo = '';
        $orderRecord = [];
        for ($attempt = 1; $attempt <= self::ORDER_NO_MAX_ATTEMPTS; $attempt++) {
            $orderNo = $this->orderRepository->generateOrderNo();
            $orderRecord = $orderRecordTemplate;
            $orderRecord['order_no'] = $orderNo;
            $orderItems = array_map(static function (array $orderItem) use ($orderNo): array {
                $orderItem['order_no'] = $orderNo;
                return $orderItem;
            }, $itemTemplates);

            $db->beginTransaction();
            try {
                $createdOrderNo = $this->orderRepository->createOrder($orderRecord);
                if (!$createdOrderNo) {
                    $db->rollBack();
                    return Result::failure('주문 생성에 실패했습니다.');
                }

                // 주문 상세 아이템 생성
                foreach ($orderItems as $orderItem) {
                    $this->orderRepository->createOrderItem($orderItem);
                }

                $db->commit();
                break;
            } catch (\Throwable $e) {
                $db->rollBack();
                if ($this->isDuplicateOrderNoException($e) && $attempt < self::ORDER_NO_MAX_ATTEMPTS) {
                    continue;
                }
                return Result::failure('주문 생성 중 오류가 발생했습니다.');
            }
        }

        // 장바구니 상태 변경은 결제 확인(verify) 이후에 수행
        // → CartController::verify() 에서 markOrdered() 호출

        // 결제 금액 계산 (PG에 전달할 실제 청구 금액)
        $paymentAmount = $this->priceCalculator->calculatePaymentAmount($orderRecord);

        return Result::success('주문이 접수되었습니다.', [
            'order_no' => $orderNo,
            'cart_session_id' => $orderData['cart_session_id'] ?? null,
            'payment_amount' => $paymentAmount,
        ]);
    }

    /**
     * 주문 단건 조회
     *
     * 주문 기본 정보 + 주문 상세 아이템 함께 반환
     *
     * @param string $orderNo 주문번호
     * @return Result 성공 시 order, items 포함
     */
    public function getOrder(string $orderNo): Result
    {
        $order = $this->orderRepository->find($orderNo);
        if (!$order) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }

        $items = $this->orderRepository->getItems($orderNo);

        // 개인정보 복호화
        $orderArray = $order->toArray();
        $this->decryptOrderFields($orderArray);

        return Result::success('', [
            'order' => $orderArray,
            'items' => $items,
        ]);
    }

    public function isCustomerVisibleOrder(string $orderNo): bool
    {
        return $this->orderRepository->isCustomerVisible($orderNo);
    }

    /**
     * 구매확정 주문의 후기 미작성 상품 목록 (관리자 후기 등록 피커).
     *
     * 주문상세를 평면으로 내려주며, 각 항목에 주문번호·구매자(복호화)·상품 정보를 포함한다.
     * keyword는 주문번호/상품명으로 검색된다.
     */
    public function getReviewableItems(int $domainId, string $keyword = '', int $page = 1, int $perPage = 12): Result
    {
        $result = $this->orderRepository->getReviewableConfirmedItems($domainId, $keyword, $page, $perPage);

        $items = array_map(function (array $row) {
            $this->decryptOrderFields($row); // orderer_name 복호화
            $memberId = (int) ($row['member_id'] ?? 0);
            return [
                'order_detail_id' => (int) ($row['order_detail_id'] ?? 0),
                'order_no'        => (string) ($row['order_no'] ?? ''),
                'goods_id'        => (int) ($row['goods_id'] ?? 0),
                'goods_name'      => (string) ($row['goods_name'] ?? ''),
                'goods_image'     => (string) ($row['goods_image'] ?? ''),
                'option_name'     => (string) ($row['option_name'] ?? ''),
                'quantity'        => (int) ($row['quantity'] ?? 0),
                'member_id'       => $memberId,
                'is_member'       => $memberId > 0,
                'buyer_name'      => (string) ($row['orderer_name'] ?? ''),
                'created_at'      => substr((string) ($row['created_at'] ?? ''), 0, 10),
            ];
        }, $result['items'] ?? []);

        return Result::success('', [
            'items'      => $items,
            'pagination' => $result['pagination'] ?? [],
        ]);
    }

    /**
     * 주문 목록 조회 (관리자용)
     *
     * 도메인별 주문 목록을 필터 조건과 페이지네이션으로 조회
     *
     * @param int $domainId 도메인 ID
     * @param array $filters 검색 조건 (member_id, order_status, date_from, date_to, keyword)
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @return Result 성공 시 items, pagination 포함
     */
    public function getOrderList(int $domainId, array $filters, int $page, int $perPage = 20): Result
    {
        // 검색 키워드가 있으면 Blind Index 생성
        $filters = $this->prepareSearchFilters($filters);

        $result = $this->orderRepository->getList($domainId, $filters, $page, $perPage);

        // 주문별 아이템 수/대표상품 부착 (목록에서 단건/복수 식별용)
        $orderNos = array_map(fn($order) => $order->getOrderNo(), $result['items']);
        $itemsMap = $this->orderRepository->getItemsByOrderNos($orderNos);

        $orders = array_map(function ($order) use ($itemsMap) {
            $arr = $this->decryptOrderArray($order->toArray());
            $items = $itemsMap[$order->getOrderNo()] ?? [];
            $arr['item_count'] = count($items);
            $arr['first_item_name'] = $items[0]['goods_name'] ?? '';
            // 목록 '결제금액'은 상품합계가 아닌 최종 결제금액(배송비/포인트/쿠폰 반영)을 표시
            $arr['final_amount'] = $order->getFinalAmount();
            // 상품 조회 모달용 최소 필드 (이미지/상품명/링크)
            $arr['items'] = array_map(static fn($it) => [
                'goods_id'    => (int) ($it['goods_id'] ?? 0),
                'goods_name'  => $it['goods_name'] ?? '',
                'goods_image' => $it['goods_image'] ?? '',
                'option_name' => $it['option_name'] ?? '',
                'quantity'    => (int) ($it['quantity'] ?? 0),
            ], $items);
            return $arr;
        }, $result['items']);

        return Result::success('', [
            'items' => $orders,
            'pagination' => $result['pagination'],
        ]);
    }

    /**
     * 회원별 주문 목록 조회
     *
     * @param int $memberId 회원 ID
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @return Result 성공 시 items, pagination 포함
     */
    public function getMemberOrders(int $domainId, int $memberId, int $page, int $perPage = 10, string $keyword = ''): Result
    {
        $result = $this->orderRepository->getByMember($domainId, $memberId, $page, $perPage, $keyword);

        // 주문별 아이템 조회 (목록에서 대표 상품 표시용)
        $orderNos = array_map(fn($order) => $order->getOrderNo(), $result['items']);
        $itemsMap = $this->orderRepository->getItemsByOrderNos($orderNos);

        $orders = array_map(function ($order) use ($itemsMap) {
            $arr = $this->decryptOrderArray($order->toArray());
            $arr['items'] = $itemsMap[$order->getOrderNo()] ?? [];
            return $arr;
        }, $result['items']);

        return Result::success('', [
            'items' => $orders,
            'pagination' => $result['pagination'],
        ]);
    }

    /**
     * 비회원 주문 목록 조회 (세션이 보유한 주문번호 기준)
     *
     * 비회원은 로그인이 없으므로, 결제 통과 또는 주문조회 폼 통과로 세션에 기록된
     * 주문번호 집합만 조회한다. 표시 형태는 회원 getMemberOrders와 동일.
     *
     * @param string[] $orderNos 세션이 보유한 주문번호
     * @return Result items, pagination 포함
     */
    public function getGuestOrders(array $orderNos): Result
    {
        $orderNos = array_values(array_unique(array_filter(array_map('strval', $orderNos))));

        if (empty($orderNos)) {
            return Result::success('', [
                'items'      => [],
                'pagination' => ['totalItems' => 0, 'perPage' => 1, 'currentPage' => 1, 'totalPages' => 1],
            ]);
        }

        $entities = $this->orderRepository->findByOrderNos($orderNos, true);
        $itemsMap = $this->orderRepository->getItemsByOrderNos(
            array_map(static fn($o) => $o->getOrderNo(), $entities)
        );

        $orders = array_map(function ($order) use ($itemsMap) {
            $arr = $this->decryptOrderArray($order->toArray());
            $arr['items'] = $itemsMap[$order->getOrderNo()] ?? [];
            return $arr;
        }, $entities);

        $count = count($orders);
        return Result::success('', [
            'items'      => $orders,
            'pagination' => ['totalItems' => $count, 'perPage' => max(1, $count), 'currentPage' => 1, 'totalPages' => 1],
        ]);
    }

    /**
     * 비회원 주문 조회 (주문인명 + 연락처)
     *
     * 주문인명 Blind Index로 비회원 주문 후보를 찾고, 각 후보의 연락처를 복호화해
     * 숫자만 비교(하이픈/공백 무시)하여 일치하는 주문을 모두 반환한다.
     * → 같은 사람(이름+연락처)의 주문 전체가 조회된다.
     *
     * 전화번호는 저장 시 하이픈 포맷이 섞일 수 있어 Blind Index 직접 매칭이 불안정하므로,
     * 이름으로 후보를 좁힌 뒤 복호화하여 숫자만 비교한다.
     *
     * @param string $ordererName 주문인명
     * @param string $phone 연락처
     * @return Result 성공 시 orders(복호화 + 대표상품 부착) 포함
     */
    public function lookupGuestOrders(string $ordererName, string $phone): Result
    {
        $ordererName = trim($ordererName);
        $phoneDigits = preg_replace('/\D/', '', $phone);

        if ($ordererName === '' || $phoneDigits === '') {
            return Result::failure('주문인명과 연락처를 입력해주세요.');
        }

        if (!$this->encryptionService) {
            return Result::failure('주문 조회를 사용할 수 없습니다.');
        }

        $nameIndex = $this->encryptionService->createSearchIndex($ordererName);
        $candidates = $this->orderRepository->findGuestByNameIndex($nameIndex, 100, true);

        // 연락처 대조(복호화 후 숫자만 비교) → 같은 사람의 주문만 통과
        $matched = [];
        foreach ($candidates as $row) {
            $arr = $this->decryptOrderArray($row);
            $candPhone = preg_replace('/\D/', '', (string) ($arr['orderer_phone'] ?? ''));
            if ($candPhone !== '' && $candPhone === $phoneDigits) {
                $matched[] = $arr;
            }
        }

        if (empty($matched)) {
            return Result::failure('일치하는 주문이 없습니다. 주문인명과 연락처를 확인해주세요.');
        }

        // 대표 상품/아이템 수 부착 (목록 표시용)
        $orderNos = array_map(static fn($o) => (string) ($o['order_no'] ?? ''), $matched);
        $itemsMap = $this->orderRepository->getItemsByOrderNos($orderNos);

        $orders = array_map(function (array $arr) use ($itemsMap) {
            $items = $itemsMap[$arr['order_no'] ?? ''] ?? [];
            $arr['item_count'] = count($items);
            $arr['first_item_name'] = $items[0]['goods_name'] ?? '';
            return $arr;
        }, $matched);

        return Result::success('', ['orders' => $orders]);
    }

    /**
     * 주문 상태 변경 (FSM 기반)
     *
     * OrderStateResolver를 통해 전이 규칙을 검증하고,
     * 로그 기록 + 이벤트 발행을 수행한다.
     *
     * @param string $orderNo 주문번호
     * @param string $newStateId 변경할 상태 id (FSM state id)
     * @param int $domainId 도메인 ID
     * @param string $reason 변경 사유
     * @param string $changedBy 변경 주체 (SYSTEM|STAFF|CUSTOMER)
     * @return Result
     */
    public function updateStatus(
        string $orderNo,
        string $newStateId,
        int $domainId,
        string $reason = '',
        string $changedBy = 'STAFF'
    ): Result {
        $order = $this->orderRepository->find($orderNo);
        if (!$order) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }
        if ((int) ($order->toArray()['domain_id'] ?? 0) !== $domainId) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }
        if ($this->hasActiveExchange($orderNo)) {
            return Result::failure('교환이 진행 중인 주문은 교환 종결 후 상태를 변경해주세요.');
        }

        $currentId = $order->getOrderStatusRaw() ?? '';

        // 내부 과도상태(ATTEMPTING)는 관리자 FSM 그래프 밖이라 canTransition을 타지 않는다.
        // 시스템 활성화 전이만 허용: 결제 성공 → PAID, 확정적 이상(금액·주문번호 불일치 등) → CANCELLED.
        $isActivation = $currentId === OrderAction::ATTEMPTING->value
            && in_array($newStateId, [OrderAction::PAID->value, OrderAction::CANCELLED->value], true);

        // FSM 전이 검증 (활성화 전이는 그래프 검증 우회)
        if (!$isActivation && !$this->stateResolver->canTransition($domainId, $currentId, $newStateId)) {
            $currentLabel = $this->stateResolver->getLabel($domainId, $currentId);
            $newLabel = $this->stateResolver->getLabel($domainId, $newStateId);
            return Result::failure("'{$currentLabel}'에서 '{$newLabel}'(으)로 변경할 수 없습니다.");
        }

        // 라벨 스냅샷 + 로그 기록
        $prevLabel = $this->stateResolver->getLabel($domainId, $currentId);
        $newLabel = $this->stateResolver->getLabel($domainId, $newStateId);

        // 상태와 감사 로그는 하나의 원자적 변경이다. 외부 Action은 커밋 뒤 실행한다.
        $db = $this->orderRepository->getDb();
        try {
            $db->beginTransaction();
            $updated = $this->orderRepository->updateStatus($orderNo, $newStateId, $currentId);
            if (!$updated) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                return Result::failure('주문 상태 변경에 실패했습니다.');
            }

            $logId = $this->orderRepository->insertOrderLog([
                'order_no' => $orderNo,
                'prev_status' => $currentId,
                'prev_status_label' => $prevLabel,
                'new_status' => $newStateId,
                'new_status_label' => $newLabel,
                'change_type' => 'STATUS',
                'changed_by' => $changedBy,
                'reason' => $reason ?: null,
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return Result::failure('주문 상태 변경 기록에 실패했습니다.');
        }

        $this->dispatchStatusChanged($order, $currentId, $newStateId, $prevLabel, $newLabel, (int) $logId);

        return Result::success('주문 상태가 변경되었습니다.', [
            'order_no' => $orderNo,
            'status' => $newStateId,
            'status_label' => $newLabel,
        ]);
    }

    /**
     * 구매자 구매확정.
     *
     * 배송중/배송완료 상태에서 구매자가 구매확정하면, 주문 헤더를 FSM 정규 전이로
     * confirmed까지 진행한다(배송중→배송완료→구매확정 — 모두 defaultStates에 정의된
     * 합법 전이). 소상공인몰 특성상 판매자가 '배송완료'를 잡기 어려우므로 배송중부터
     * 허용하고, 그 경우 배송완료를 거쳐 확정까지 한 번에 walk 한다.
     *
     * @param string $orderNo  주문번호
     * @param int    $domainId 도메인 ID
     */
    public function confirmByBuyer(string $orderNo, int $domainId): Result
    {
        return $this->walkToConfirmed($orderNo, $domainId, '구매자 구매확정', 'CUSTOMER');
    }

    /**
     * 배송중/배송완료 주문을 FSM 정규 전이로 confirmed까지 진행한다.
     * (배송중→배송완료→구매확정 — 모두 defaultStates에 정의된 합법 전이)
     * 구매자 확정·자동 확정이 공유한다.
     *
     * @param string $reason    로그 사유
     * @param string $changedBy 변경 주체 (CUSTOMER | SYSTEM 등)
     */
    private function walkToConfirmed(string $orderNo, int $domainId, string $reason, string $changedBy): Result
    {
        $order = $this->orderRepository->find($orderNo);
        if (!$order) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }

        $current = $order->getOrderStatusRaw() ?? '';
        if ($current === OrderAction::CONFIRMED->value) {
            return Result::success('이미 구매확정된 주문입니다.', [
                'order_no' => $orderNo,
                'status'   => OrderAction::CONFIRMED->value,
            ]);
        }

        // 배송중/배송완료에서만 구매확정 가능 (그 이전·취소/반품 계열은 불가)
        $path = [
            OrderAction::SHIPPING->value  => OrderAction::DELIVERED->value,
            OrderAction::DELIVERED->value => OrderAction::CONFIRMED->value,
        ];
        if (!isset($path[$current])) {
            return Result::failure('배송이 시작된 후 구매확정할 수 있습니다.');
        }

        // FSM 정규 전이로 confirmed까지 walk
        $guard = 0;
        while (isset($path[$current]) && $guard++ < 5) {
            $next = $path[$current];
            $result = $this->updateStatus($orderNo, $next, $domainId, $reason, $changedBy);
            if (!$result->isSuccess()) {
                return $result;
            }
            $current = $next;
        }

        return Result::success('구매확정되었습니다.', [
            'order_no' => $orderNo,
            'status'   => OrderAction::CONFIRMED->value,
        ]);
    }

    /**
     * 접점 기반 자동 구매확정 스윕 (관리자 주문관리 / 사용자 주문내역 진입 시 호출).
     *
     * shop_config.auto_confirm_days(발송 후 N일)가 설정돼 있으면, 발송 시각이 그만큼
     * 지난 배송중/배송완료 주문을 구매확정한다. 크론 없이 자연 트래픽으로 굴리되,
     * 매 요청마다 돌지 않도록 도메인당 AUTO_CONFIRM_THROTTLE_TTL 주기로만 실제 실행한다.
     * (설정 0이거나 config 서비스 미주입 시 아무것도 하지 않음)
     */
    public function maybeAutoConfirm(int $domainId): void
    {
        $days = $this->autoConfirmDays($domainId);
        if ($days <= 0) {
            return;
        }

        if ($this->cache !== null) {
            $key = "shop:auto_confirm_sweep:{$domainId}";
            if ($this->cache->has($key)) {
                return; // 최근 스윕됨 → skip
            }
            $this->cache->set($key, 1, self::AUTO_CONFIRM_THROTTLE_TTL);
        }

        $this->autoConfirmDueOrders($domainId, $days);
    }

    /**
     * 발송 후 N일 지난 배송중/배송완료 주문을 자동 구매확정한다.
     * (스로틀 게이트 없이 즉시 실행 — 스케줄러/테스트에서 직접 호출용)
     */
    public function autoConfirmDueOrders(int $domainId, int $days): Result
    {
        if ($days <= 0) {
            return Result::success('자동 구매확정이 비활성화되어 있습니다.', ['confirmed_count' => 0]);
        }

        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $orderNos = $this->orderRepository->findAutoConfirmDue($domainId, $cutoff, self::AUTO_CONFIRM_BATCH);

        $count = 0;
        foreach ($orderNos as $orderNo) {
            if ($this->walkToConfirmed($orderNo, $domainId, '자동 구매확정', 'SYSTEM')->isSuccess()) {
                $count++;
            }
        }

        return Result::success("{$count}건을 자동 구매확정 처리했습니다.", ['confirmed_count' => $count]);
    }

    /**
     * 도메인의 자동 구매확정 기준일 조회 (설정 없으면 0=비활성).
     */
    private function autoConfirmDays(int $domainId): int
    {
        if ($this->shopConfigService === null) {
            return 0;
        }
        $config = $this->shopConfigService->getConfig($domainId)->get('config', []);
        return max(0, (int) ($config['auto_confirm_days'] ?? 0));
    }

    /**
     * 만료된 결제시도(attempting) 고아 주문 정리 (스케줄러에서 호출)
     *
     * 결제창만 띄우고 이탈한 미결제 주문을 일정 시간 경과 후 하드 삭제한다.
     * 성공 결제가 있는 건은 Repository에서 보호하므로 절대 지워지지 않는다.
     * (포인트 만료(processExpired)·쿠폰 만료(expireOverdueCoupons)와 동일하게
     *  메서드만 제공하고 실제 스케줄 연결은 운영 크론에서 호출한다.)
     *
     * @param int $domainId
     * @param int $olderThanMinutes 이 시간(분)보다 오래된 미결제 attempting만 대상 (기본 30분)
     */
    public function expireStaleAttemptingOrders(int $domainId, int $olderThanMinutes = self::SWEEP_STALE_MINUTES): Result
    {
        $cutoff = date('Y-m-d H:i:s', time() - max(1, $olderThanMinutes) * 60);

        // 하드 삭제 전에, 이 고아 주문들이 잡아둔 쿠폰을 복원한다.
        // PG 결제창만 띄우고 이탈한 주문도 생성 시점에 쿠폰을 claim(is_used=1)하므로,
        // 복원 없이 삭제하면 쿠폰이 dangling order_no 를 문 채 영구 USED 로 소실된다.
        // (couponService 미주입 환경 — 일부 테스트 등 — 에서는 이 단계를 건너뛴다.)
        //
        // 대상 조회는 '한 번만' 한다. 복원용/삭제용으로 findStaleAttemptingOrderNos 를 각각 돌리면
        // LIMIT·정렬 차이로 두 목록이 발산해 일부 삭제 주문이 복원을 건너뛸 수 있으므로,
        // 조회한 그 목록을 deleteByOrderNos 로 그대로 삭제한다.
        if ($this->couponService !== null) {
            $orderNos = $this->orderRepository->findStaleAttemptingOrderNos($domainId, $cutoff, self::SWEEP_BATCH);
            foreach ($orderNos as $orderNo) {
                try {
                    $this->couponService->restoreOrderCoupons($orderNo);
                } catch (\Throwable $e) {
                    error_log('[SHOP] stale attempting 쿠폰 복원 실패: order=' . $orderNo . ' ' . $e->getMessage());
                }
            }
            $count = $this->orderRepository->deleteByOrderNos($orderNos);
        } else {
            $count = $this->orderRepository->deleteStaleAttempting($domainId, $cutoff, self::SWEEP_BATCH);
        }

        return Result::success(
            "{$count}건의 미결제 결제시도 주문을 정리했습니다.",
            ['deleted_count' => $count]
        );
    }

    /**
     * 접점 기반 스로틀 스윕 (관리자 주문관리 / 사용자 주문내역 진입 시 호출)
     *
     * attempting 고아는 이미 목록에서 제외되므로 정리는 best-effort 위생 작업.
     * 매 요청마다 DELETE를 돌리지 않도록 도메인당 SWEEP_THROTTLE_TTL 주기로만 실제 실행한다.
     * (캐시 미주입 환경 — 테스트 등 — 에서는 게이트 없이 직접 1회 실행)
     */
    public function maybeExpireStaleAttempting(int $domainId): void
    {
        if ($this->cache !== null) {
            $key = "shop:attempting_sweep:{$domainId}";
            if ($this->cache->has($key)) {
                return; // 최근 스윕됨 → DB 건드리지 않고 skip
            }
            // 게이트 선점(경합 시 한 요청만 실제 스윕)
            $this->cache->set($key, 1, self::SWEEP_THROTTLE_TTL);
        }

        $this->expireStaleAttemptingOrders($domainId);
    }

    /**
     * 상태 전이 없이 주문 로그만 기록 (감사/추적용)
     *
     * 상태를 바꾸지 않는 사건(예: PG prepare 실패로 ATTEMPTING 유지)을 이력에 남길 때 사용한다.
     * prev/new 상태를 동일하게(현재 상태) 기록하므로 주문상세 이력에 "결제시도 → 결제시도 / 사유" 로 표시된다.
     *
     * @param string $orderNo 주문번호
     * @param int $domainId 도메인 ID
     * @param string $reason 기록 사유
     * @param string $changeType 로그 분류 (STATUS|PAYMENT|RETURN|SHIPPING|SYSTEM)
     * @param string $changedBy 변경 주체 (SYSTEM|STAFF|CUSTOMER)
     */
    public function logEvent(
        string $orderNo,
        int $domainId,
        string $reason,
        string $changeType = 'PAYMENT',
        string $changedBy = 'SYSTEM'
    ): void {
        $order = $this->orderRepository->find($orderNo);
        if (!$order) {
            return;
        }

        $currentId = $order->getOrderStatusRaw() ?? '';
        $label = $this->stateResolver->getLabel($domainId, $currentId);

        $this->orderRepository->insertOrderLog([
            'order_no' => $orderNo,
            'prev_status' => $currentId,
            'prev_status_label' => $label,
            'new_status' => $currentId,
            'new_status_label' => $label,
            'change_type' => $changeType,
            'changed_by' => $changedBy,
            'reason' => $reason ?: null,
        ]);
    }

    /**
     * 주문 로그 조회
     */
    public function getOrderLogs(string $orderNo): array
    {
        return $this->orderRepository->getOrderLogs($orderNo);
    }

    /**
     * 주문 반품 목록 조회
     */
    public function getOrderReturns(string $orderNo): array
    {
        return $this->orderRepository->getReturnsByOrderNo($orderNo);
    }

    // ===== 아이템 관리 =====

    /**
     * 아이템 상태 변경 (관리자)
     *
     * FSM 전이 검증 → 플래그 동기화 → 로그 기록 → 주문 자동 전이
     */
    public function updateItemStatus(
        string $orderNo,
        int $detailId,
        string $newStateId,
        int $domainId,
        string $reason = ''
    ): Result {
        $item = $this->orderRepository->getItemInDomain($domainId, $detailId);
        if (!$item || ($item['order_no'] ?? '') !== $orderNo) {
            return Result::failure('주문 상품을 찾을 수 없습니다.');
        }
        $claimStatus = ClaimStatus::tryFrom((string) ($item['return_status'] ?? ''));
        if (($item['return_type'] ?? '') === 'EXCHANGE' && $claimStatus?->isActive()) {
            return Result::failure('교환이 진행 중인 상품은 교환 관리에서 처리해주세요.');
        }

        $currentId = $item['status'] ?? '';

        // FSM 전이 검증
        if ($currentId !== '' && !$this->stateResolver->canTransition($domainId, $currentId, $newStateId)) {
            $currentLabel = $this->stateResolver->getLabel($domainId, $currentId);
            $newLabel = $this->stateResolver->getLabel($domainId, $newStateId);
            return Result::failure("'{$currentLabel}'에서 '{$newLabel}'(으)로 변경할 수 없습니다.");
        }

        $prevLabel = $this->stateResolver->getLabel($domainId, $currentId);
        $newLabel = $this->stateResolver->getLabel($domainId, $newStateId);

        // 상태·플래그·이력을 원자적으로 기록(중간 실패 시 부분 커밋 방지). 이벤트·주문 동기화는 커밋 후.
        $db = $this->orderRepository->getDb();
        $db->beginTransaction();
        try {
            $this->orderRepository->updateItemStatus($detailId, $newStateId);
            $this->syncItemFlags($detailId, $newStateId, $domainId);
            $this->orderRepository->insertOrderLog([
                'order_no' => $orderNo,
                'order_detail_id' => $detailId,
                'prev_status' => $currentId,
                'prev_status_label' => $prevLabel,
                'new_status' => $newStateId,
                'new_status_label' => $newLabel,
                'change_type' => 'STATUS',
                'changed_by' => 'STAFF',
                'reason' => $reason ?: null,
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return Result::failure('상품 상태 변경에 실패했습니다.');
        }

        $this->dispatchItemStatusChanged($item, $currentId, $newStateId, $prevLabel, $newLabel, $domainId);

        // 모든 아이템 동일 상태 시 주문 자동 전이
        $this->autoSyncOrderStatus($orderNo, $domainId);

        return Result::success('상품 상태가 변경되었습니다.', [
            'detail_id' => $detailId,
            'status' => $newStateId,
            'status_label' => $newLabel,
        ]);
    }

    private function hasActiveExchange(string $orderNo): bool
    {
        foreach ($this->orderRepository->getItems($orderNo) as $item) {
            if (($item['return_type'] ?? '') !== 'EXCHANGE') {
                continue;
            }
            if (ClaimStatus::tryFrom((string) ($item['return_status'] ?? ''))?->isActive()) {
                return true;
            }
        }
        return false;
    }

    /**
     * 아이템 취소
     *
     * received/paid 상태에서만 가능. shop_returns + status/return 컬럼 업데이트.
     */
    public function cancelOrderItem(string $orderNo, int $detailId, string $reason, int $domainId): Result
    {
        $item = $this->orderRepository->getItemInDomain($domainId, $detailId);
        if (!$item || ($item['order_no'] ?? '') !== $orderNo) {
            return Result::failure('주문 상품을 찾을 수 없습니다.');
        }

        $currentStatus = $item['status'] ?? '';
        $action = OrderAction::tryFrom($currentStatus);
        if ($action && !$action->isCancellable()) {
            return Result::failure('현재 상태에서는 취소할 수 없습니다.');
        }

        $prevLabel = $this->stateResolver->getLabel($domainId, $currentStatus);

        // 반품 레코드·아이템 상태·이력을 원자적으로 기록한다(중간 실패 시 부분 커밋 방지).
        // 이벤트 발행과 주문 상태 동기화(자체 트랜잭션)는 커밋 후로 분리한다.
        $db = $this->orderRepository->getDb();
        $db->beginTransaction();
        try {
            $this->orderRepository->createReturn([
                'domain_id' => $domainId,
                'order_no' => $orderNo,
                'order_detail_id' => $detailId,
                'member_id' => 0,
                'return_type' => 'CANCEL',
                'return_status' => 'COMPLETED',
                'reason_type' => 'OTHER',
                'reason_detail' => $reason,
                'quantity' => (int) ($item['quantity'] ?? 1),
                'refund_amount' => (int) ($item['total_price'] ?? 0),
            ]);

            $this->orderRepository->updateItemStatus($detailId, OrderAction::CANCELLED->value);
            $this->orderRepository->updateItemReturn($detailId, 'CANCEL', 'COMPLETED');

            $this->orderRepository->insertOrderLog([
                'order_no' => $orderNo,
                'order_detail_id' => $detailId,
                'prev_status' => $currentStatus,
                'prev_status_label' => $prevLabel,
                'new_status' => OrderAction::CANCELLED->value,
                'new_status_label' => '취소완료',
                'change_type' => 'STATUS',
                'changed_by' => 'STAFF',
                'reason' => $reason ?: null,
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return Result::failure('상품 취소 처리에 실패했습니다.');
        }

        $this->dispatchItemStatusChanged(
            $item,
            $currentStatus,
            OrderAction::CANCELLED->value,
            $prevLabel,
            '취소완료',
            $domainId,
        );

        // 모든 아이템 취소 시 주문도 취소
        $this->autoSyncOrderStatus($orderNo, $domainId);

        return Result::success('상품이 취소되었습니다.', [
            'detail_id' => $detailId,
            'refund_amount' => (int) ($item['total_price'] ?? 0),
        ]);
    }

    /** 반품 요청 접수. 교환은 대체 옵션·재출고 정보가 필요한 별도 흐름이므로 지원하지 않는다. */
    public function requestItemReturn(
        string $orderNo,
        int $detailId,
        string $returnType,
        string $reasonType,
        string $reasonDetail,
        int $domainId
    ): Result {
        $item = $this->orderRepository->getItemInDomain($domainId, $detailId);
        if (!$item || ($item['order_no'] ?? '') !== $orderNo) {
            return Result::failure('주문 상품을 찾을 수 없습니다.');
        }

        if ($returnType === 'EXCHANGE') {
            return Result::failure('교환은 아직 지원하지 않습니다. 반품 후 다시 주문해주세요.');
        }

        // 반품 가능 상태 확인
        $action = OrderAction::tryFrom($item['status'] ?? '');
        if (!$action || !$action->isShipped()) {
            return Result::failure('배송 완료 후에만 반품을 요청할 수 있습니다.');
        }

        if (($item['return_type'] ?? 'NONE') !== 'NONE') {
            return Result::failure('이미 반품이 진행 중입니다.');
        }

        if ($returnType !== 'RETURN') {
            return Result::failure('유효하지 않은 반품 유형입니다.');
        }

        $reasonType = strtoupper(trim($reasonType));
        if ($reasonType === '') {
            $reasonType = 'OTHER';
        }
        if (!in_array($reasonType, [
            'CHANGE_MIND', 'DEFECT', 'WRONG_PRODUCT', 'WRONG_OPTION', 'LATE_DELIVERY', 'OTHER',
        ], true)) {
            return Result::failure('유효하지 않은 반품 사유입니다.');
        }

        $order = $this->orderRepository->findByOrderNoInDomain($domainId, $orderNo);
        if ($order === null) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }

        $returnShippingFee = $this->resolveReturnShippingFee($order, $item, $reasonType);
        $refundAmount = max(0, (int) ($item['total_price'] ?? 0) - $returnShippingFee);

        $prevLabel = $this->stateResolver->getLabel($domainId, $item['status'] ?? '');

        // 반품 레코드·아이템 상태·이력을 원자적으로 기록(중간 실패 시 부분 커밋 방지). 이벤트는 커밋 후.
        $db = $this->orderRepository->getDb();
        $db->beginTransaction();
        try {
            $this->orderRepository->createReturn([
                'domain_id' => $domainId,
                'order_no' => $orderNo,
                'order_detail_id' => $detailId,
                'member_id' => (int) ($order['member_id'] ?? 0),
                'return_type' => $returnType,
                'return_status' => 'REQUESTED',
                'reason_type' => $reasonType,
                'reason_detail' => $reasonDetail,
                'quantity' => (int) ($item['quantity'] ?? 1),
                'refund_amount' => $refundAmount,
                'return_shipping_fee' => $returnShippingFee,
            ]);

            $this->orderRepository->updateItemStatus($detailId, OrderAction::RETURN_REQUESTED->value);
            $this->orderRepository->updateItemReturn($detailId, $returnType, 'REQUESTED');

            $this->orderRepository->insertOrderLog([
                'order_no' => $orderNo,
                'order_detail_id' => $detailId,
                'prev_status' => $item['status'] ?? '',
                'prev_status_label' => $prevLabel,
                'new_status' => OrderAction::RETURN_REQUESTED->value,
                'new_status_label' => '반품요청',
                'change_type' => 'RETURN',
                'changed_by' => 'STAFF',
                'reason' => $reasonDetail ?: null,
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return Result::failure('반품 요청 처리에 실패했습니다.');
        }

        $this->dispatchItemStatusChanged(
            $item,
            (string) ($item['status'] ?? ''),
            OrderAction::RETURN_REQUESTED->value,
            $prevLabel,
            '반품요청',
            $domainId,
        );

        return Result::success('반품 요청이 접수되었습니다.', [
            'detail_id' => $detailId,
            'refund_amount' => $refundAmount,
            'return_shipping_fee' => $returnShippingFee,
        ]);
    }

    /**
     * 반품 승인/거절
     */
    public function processItemReturn(
        string $orderNo,
        int $detailId,
        bool $accept,
        string $reason,
        int $domainId
    ): Result {
        $item = $this->orderRepository->getItemInDomain($domainId, $detailId);
        if (!$item || ($item['order_no'] ?? '') !== $orderNo) {
            return Result::failure('주문 상품을 찾을 수 없습니다.');
        }

        if (($item['return_status'] ?? '') !== 'REQUESTED') {
            return Result::failure('반품 요청 상태가 아닙니다.');
        }

        $returnRecord = $this->orderRepository->getReturnByDetailId($detailId);
        if ($returnRecord === null) {
            return Result::failure('반품 요청 기록을 찾을 수 없습니다.');
        }
        if (($returnRecord['return_type'] ?? $item['return_type'] ?? '') === 'EXCHANGE') {
            return Result::failure('교환 요청은 교환 관리에서 처리해주세요.');
        }

        if ($accept) {
            // 승인: return_status=COMPLETED, status=returned
            $refundAmount = (int) ($returnRecord['refund_amount'] ?? $item['total_price'] ?? 0);

            // 반품 승인: 레코드·아이템 상태·이력을 원자적으로. 이벤트·주문 동기화는 커밋 후.
            $db = $this->orderRepository->getDb();
            $db->beginTransaction();
            try {
                $this->orderRepository->updateReturn($returnRecord['return_id'], [
                    'return_status' => 'COMPLETED',
                    'refund_amount' => $refundAmount,
                    'staff_memo' => $reason ?: null,
                    'completed_at' => date('Y-m-d H:i:s'),
                ]);

                $this->orderRepository->updateItemStatus($detailId, OrderAction::RETURNED->value);
                $this->orderRepository->updateItemReturn($detailId, $item['return_type'] ?? 'RETURN', 'COMPLETED');

                $this->orderRepository->insertOrderLog([
                    'order_no' => $orderNo,
                    'order_detail_id' => $detailId,
                    'prev_status' => $item['status'] ?? '',
                    'prev_status_label' => '반품요청',
                    'new_status' => OrderAction::RETURNED->value,
                    'new_status_label' => '반품완료',
                    'change_type' => 'RETURN',
                    'changed_by' => 'STAFF',
                    'reason' => $reason ?: null,
                ]);

                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                return Result::failure('반품 승인 처리에 실패했습니다.');
            }

            $this->dispatchItemStatusChanged(
                $item,
                (string) ($item['status'] ?? ''),
                OrderAction::RETURNED->value,
                '반품요청',
                '반품완료',
                $domainId,
            );

            $this->autoSyncOrderStatus($orderNo, $domainId);

            return Result::success('반품이 승인되었습니다.', [
                'detail_id' => $detailId,
                'refund_amount' => $refundAmount,
            ]);
        } else {
            // 거절: return_status=REFUSED, 상태 원복.
            // 복원 대상 상태는 트랜잭션 전에 로그에서 읽어 결정한다(읽기).
            $logs = $this->orderRepository->getOrderLogs($orderNo);
            $prevStatus = '';
            foreach ($logs as $log) {
                if ((int) ($log['order_detail_id'] ?? 0) === $detailId
                    && ($log['new_status'] ?? '') === OrderAction::RETURN_REQUESTED->value
                ) {
                    $prevStatus = $log['prev_status'] ?? '';
                    break;
                }
            }
            $restoreStatus = $prevStatus ?: OrderAction::DELIVERED->value;

            // 레코드·아이템 상태·이력을 원자적으로. 이벤트는 커밋 후.
            $db = $this->orderRepository->getDb();
            $db->beginTransaction();
            try {
                $this->orderRepository->updateReturn($returnRecord['return_id'], [
                    'return_status' => 'REFUSED',
                    'refused_reason' => $reason,
                ]);

                $this->orderRepository->updateItemStatus($detailId, $restoreStatus);
                $this->orderRepository->updateItemReturn($detailId, 'NONE', 'NONE');

                $this->orderRepository->insertOrderLog([
                    'order_no' => $orderNo,
                    'order_detail_id' => $detailId,
                    'prev_status' => OrderAction::RETURN_REQUESTED->value,
                    'prev_status_label' => '반품요청',
                    'new_status' => $restoreStatus,
                    'new_status_label' => $this->stateResolver->getLabel($domainId, $restoreStatus),
                    'change_type' => 'RETURN',
                    'changed_by' => 'STAFF',
                    'reason' => '반품 거절: ' . $reason,
                ]);

                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                return Result::failure('반품 거절 처리에 실패했습니다.');
            }

            $this->dispatchItemStatusChanged(
                $item,
                OrderAction::RETURN_REQUESTED->value,
                $restoreStatus,
                '반품요청',
                $this->stateResolver->getLabel($domainId, $restoreStatus),
                $domainId,
            );

            return Result::success('반품이 거절되었습니다.', ['detail_id' => $detailId]);
        }
    }

    /**
     * 주문 시점 배송 정책 스냅샷에서 반품비를 찾는다.
     * 단순 변심만 고객 귀책으로 자동 공제하고, 판매자 귀책/기타는 운영자가 후속
     * 환불 과정에서 판단할 수 있도록 0원으로 둔다. 오래된 주문에 스냅샷이 없으면
     * 현재 템플릿을 다시 읽지 않고 0원으로 처리해 과거 계약 조건을 바꾸지 않는다.
     */
    private function resolveReturnShippingFee(array $order, array $item, string $reasonType): int
    {
        if ($reasonType !== 'CHANGE_MIND') {
            return 0;
        }

        $breakdown = $order['shipping_breakdown'] ?? [];
        if (is_string($breakdown)) {
            $decoded = json_decode($breakdown, true);
            $breakdown = is_array($decoded) ? $decoded : [];
        }

        $goodsId = (int) ($item['goods_id'] ?? 0);
        foreach ((array) $breakdown as $group) {
            $goodsIds = array_map('intval', (array) ($group['goods_ids'] ?? []));
            if ($goodsId > 0 && in_array($goodsId, $goodsIds, true)) {
                return max(0, (int) ($group['return_cost'] ?? 0));
            }
        }

        return 0;
    }

    // ===== 아이템 관리 헬퍼 =====

    /**
     * FSM action 기반 플래그 동기화
     */
    private function syncItemFlags(int $detailId, string $stateId, int $domainId): void
    {
        $stateDef = $this->stateResolver->getState($domainId, $stateId);
        $action = $stateDef ? $this->stateResolver->getAction($stateId, $stateDef) : null;

        $flags = [
            'is_paid' => (int) ($action && in_array($action, [
                OrderAction::PAID, OrderAction::PREPARING,
                OrderAction::SHIPPING, OrderAction::DELIVERED, OrderAction::CONFIRMED,
            ], true)),
            'is_preparing' => (int) ($action === OrderAction::PREPARING),
            'is_shipped' => (int) ($action && in_array($action, [
                OrderAction::SHIPPING, OrderAction::DELIVERED,
            ], true)),
            'is_completed' => (int) ($action && in_array($action, [
                OrderAction::DELIVERED, OrderAction::CONFIRMED,
            ], true)),
        ];

        $this->orderRepository->updateItemFlags($detailId, $flags);
    }

    /**
     * 모든 아이템 동일 상태 시 주문 자동 전이
     */
    private function autoSyncOrderStatus(string $orderNo, int $domainId): void
    {
        $items = $this->orderRepository->getItems($orderNo);
        if (empty($items)) {
            return;
        }

        $statuses = array_unique(array_column($items, 'status'));
        if (count($statuses) !== 1) {
            return;
        }

        $unanimousState = $statuses[0];
        $order = $this->orderRepository->find($orderNo);
        if (!$order || (int) ($order->toArray()['domain_id'] ?? 0) !== $domainId
            || $order->getOrderStatusRaw() === $unanimousState
        ) {
            return;
        }

        // 자동 전이 (아이템 상태 기반이므로 FSM 검증은 스킵하되 CAS는 유지)
        $previousState = $order->getOrderStatusRaw() ?? '';
        $prevLabel = $this->stateResolver->getLabel($domainId, $previousState);
        $newLabel = $this->stateResolver->getLabel($domainId, $unanimousState);

        $db = $this->orderRepository->getDb();
        try {
            $db->beginTransaction();
            if (!$this->orderRepository->updateStatus($orderNo, $unanimousState, $previousState)) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                return;
            }

            $logId = $this->orderRepository->insertOrderLog([
                'order_no' => $orderNo,
                'prev_status' => $previousState,
                'prev_status_label' => $prevLabel,
                'new_status' => $unanimousState,
                'new_status_label' => $newLabel,
                'change_type' => 'STATUS',
                'changed_by' => 'SYSTEM',
                'reason' => '전 상품 동일 상태 자동 전이',
            ]);
            $db->commit();
        } catch (\Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return;
        }

        $this->dispatchStatusChanged($order, $previousState, $unanimousState, $prevLabel, $newLabel, (int) $logId);
    }

    /**
     * 주문 상태 이벤트의 단일 생성 경로.
     *
     * 일반 FSM 전이와 주문상품 기반 자동 전이가 같은 payload/복호화 규칙을 사용하도록 한다.
     */
    private function dispatchStatusChanged(
        Order $order,
        string $previousState,
        string $newState,
        string $previousLabel,
        string $newLabel,
        int $logId = 0,
    ): void {
        // 액션 핸들러(알림 수신자, 치환 변수)가 평문을 전제한다. 복호화 실패 시
        // 원문을 유지하므로 평문 레거시 데이터·암호화 미사용 환경에서도 무해하다.
        $orderArray = $order->toArray();
        $this->decryptOrderFields($orderArray);

        $domainId = (int) ($orderArray['domain_id'] ?? 0);
        $previousDef = $this->stateResolver->getState($domainId, $previousState);
        $newDef = $this->stateResolver->getState($domainId, $newState);

        $this->dispatch(new OrderStatusChangedEvent(
            $order->getOrderNo(),
            $previousState,
            $newState,
            $previousLabel,
            $newLabel,
            $previousDef ? $this->stateResolver->getAction($previousState, $previousDef) : OrderAction::tryFrom($previousState),
            $newDef ? $this->stateResolver->getAction($newState, $newDef) : OrderAction::tryFrom($newState),
            $orderArray,
            $logId > 0 ? "order_status_log:{$logId}" : null,
        ));
    }

    /**
     * 주문상품 상태 이벤트의 단일 생성 경로.
     */
    private function dispatchItemStatusChanged(
        array $item,
        string $previousState,
        string $newState,
        string $previousLabel,
        string $newLabel,
        int $domainId,
    ): void {
        $orderNo = (string) ($item['order_no'] ?? '');
        $detailId = (int) ($item['order_detail_id'] ?? 0);
        if ($orderNo === '' || $detailId <= 0) {
            return;
        }

        $order = $this->orderRepository->find($orderNo);
        if (!$order) {
            return;
        }

        $orderArray = $order->toArray();
        $this->decryptOrderFields($orderArray);
        $item['status'] = $newState;

        $previousDef = $this->stateResolver->getState($domainId, $previousState);
        $newDef = $this->stateResolver->getState($domainId, $newState);

        $this->dispatch(new OrderItemStatusChangedEvent(
            $orderNo,
            $detailId,
            $previousState,
            $newState,
            $previousLabel,
            $newLabel,
            $previousDef ? $this->stateResolver->getAction($previousState, $previousDef) : OrderAction::tryFrom($previousState),
            $newDef ? $this->stateResolver->getAction($newState, $newDef) : OrderAction::tryFrom($newState),
            $orderArray,
            $item,
        ));
    }

    private function isDuplicateOrderNoException(\Throwable $e): bool
    {
        if (!$e instanceof DatabaseException) {
            return false;
        }

        $message = $e->getMessage();
        $previous = $e->getPrevious();
        $code = $previous instanceof \PDOException ? (string) $previous->getCode() : '';

        return $code === '23000'
            && (str_contains($message, 'Duplicate entry') || str_contains($message, '1062'));
    }

    // ===== 주문 아이템 검증/빌드 =====

    /**
     * 단일 주문 아이템 검증 및 DB 행 빌드
     *
     * 상품 존재/활성/재고 검증 → 옵션 검증 → 금액 계산 → 주문 아이템 배열 반환
     *
     * @param array $item 프론트에서 전달된 아이템 데이터
     * @param string $orderNo 주문번호
     * @return Result 성공 시 order_item, item_total 포함
     */
    private function validateAndBuildOrderItem(array $item, string $orderNo, int $domainId): Result
    {
        $goodsId = (int) ($item['goods_id'] ?? 0);
        $quantity = (int) ($item['quantity'] ?? 1);

        // 상품 유효성 검증
        $product = $this->productRepository->findInDomain($domainId, $goodsId);
        if (!$product) {
            return Result::failure('존재하지 않는 상품이 포함되어 있습니다. (goods_id: ' . $goodsId . ')');
        }

        if (!$product->isActive()) {
            return Result::failure('판매 중지된 상품이 포함되어 있습니다: ' . $product->getGoodsName());
        }

        // 상품레벨 재고는 옵션 없는(NONE) 상품에만 적용. 옵션상품은 아래 옵션/조합 재고로 검증.
        if (($item['option_mode'] ?? 'NONE') === 'NONE'
            && $product->getStockQuantity() !== null && $product->getStockQuantity() < $quantity) {
            return Result::failure(
                '재고가 부족한 상품이 있습니다: ' . $product->getGoodsName()
                . ' (재고: ' . $product->getStockQuantity() . '개)'
            );
        }

        // 옵션 가격: DB에서 실제 가격을 조회하여 검증
        $clientOptionPrice = (int) ($item['option_price'] ?? 0);
        $optionId = (int) ($item['option_id'] ?? 0);
        $optionMode = $item['option_mode'] ?? 'NONE';
        $optionCode = $item['option_code'] ?? null;
        $optionPrice = 0;
        // 옵션명 스냅샷: DB의 조합/값에서 직접 채운다(체크아웃 데이터엔 라벨이 없어 NULL로 저장되던 문제 해결)
        $optionName = null;

        if ($optionMode === 'COMBINATION' && $optionId > 0) {
            // 조합형: combo_id로 조합 레코드 조회
            $combo = $this->productOptionRepository->findCombo($optionId);
            if (!$combo || (int) ($combo['goods_id'] ?? 0) !== $goodsId) {
                return Result::failure('존재하지 않는 옵션 조합이 포함되어 있습니다.');
            }
            $comboStock = $combo['stock_quantity'] ?? null;
            if ($comboStock !== null && $comboStock !== '' && (int) $comboStock < $quantity) {
                return Result::failure('재고가 부족한 옵션이 있습니다: ' . $product->getGoodsName());
            }
            $optionPrice = (int) ($combo['extra_price'] ?? 0);
            $optionName = $combo['combination_key'] ?? null;
        } elseif ($optionMode === 'SINGLE' && $optionId > 0 && $optionCode) {
            // 단독형: option_code(opt-{optionId}-{valueId})에서 value_id 추출
            if (preg_match('/^opt-\d+-(\d+)$/', $optionCode, $m)) {
                $valueId = (int) $m[1];
                $value = $this->productOptionRepository->findValue($valueId);
                if (!$value || (int) ($value['option_id'] ?? 0) !== $optionId) {
                    return Result::failure('존재하지 않는 옵션 값이 포함되어 있습니다.');
                }
                $valueStock = $value['stock_quantity'] ?? null;
                if ($valueStock !== null && $valueStock !== '' && (int) $valueStock < $quantity) {
                    return Result::failure('재고가 부족한 옵션이 있습니다: ' . $product->getGoodsName());
                }
                $optionPrice = (int) ($value['extra_price'] ?? 0);
                $optionName = $value['value_name'] ?? null;
            } else {
                return Result::failure('잘못된 옵션 코드 형식입니다.');
            }
        } elseif ($optionId > 0) {
            // 기타 옵션 모드에서 option_id가 있는 경우 존재 확인
            $option = $this->productOptionRepository->find($optionId);
            if (!$option) {
                return Result::failure('존재하지 않는 옵션이 포함되어 있습니다.');
            }
        }

        // 클라이언트 전달 가격과 서버 조회 가격 불일치 검증
        if ($clientOptionPrice !== $optionPrice) {
            return Result::failure(
                '옵션 가격 정보가 변경되었습니다. 새로고침 후 다시 주문해 주세요. (상품: ' . $product->getGoodsName() . ')'
            );
        }

        // 할인 적용 판매가 계산
        $priceResult = $this->priceCalculator->calculateSalesPrice(
            $product->getDisplayPrice(),
            $product->getDiscountType(),
            $product->getDiscountValue()
        );
        $goodsPrice = $priceResult['sales_price'];
        $itemTotal = ($goodsPrice + $optionPrice) * $quantity;

        // 적립 포인트 계산
        $rewardResult = $this->priceCalculator->calculateRewardPoints(
            $goodsPrice,
            $product->getRewardType(),
            $product->getRewardValue()
        );

        return Result::success('', [
            'order_item' => [
                'order_no' => $orderNo,
                'cart_item_id' => (int) ($item['cart_item_id'] ?? 0) ?: null,
                'goods_id' => $goodsId,
                'goods_name' => $product->getGoodsName(),
                'goods_image' => $item['product_image']['image_url'] ?? null,
                'option_mode' => $item['option_mode'] ?? 'NONE',
                'option_id' => $optionId,
                'option_code' => $item['option_code'] ?? null,
                'option_name' => $optionName ?? ($item['option_label'] ?? null),
                'option_type' => $item['option_type'] ?? 'BASIC',
                'goods_price' => $goodsPrice,
                'option_price' => $optionPrice,
                'support_price' => (int) ($item['support_price'] ?? 0),
                'total_price' => $itemTotal,
                'quantity' => $quantity,
                'point_amount' => $rewardResult['point_amount'] * $quantity,
                'coupon_discount' => 0,
                'coupon_id' => null,
                'status' => OrderAction::RECEIVED->value,
            ],
            'item_total' => $itemTotal,
        ]);
    }

    // ===== 개인정보 암호화 (AES-256-GCM) =====

    /**
     * 주문 레코드 암호화 (저장 전)
     *
     * 주문인/수령인/배송지 필드를 AES-256-GCM으로 암호화하고
     * 검색 가능 필드에 Blind Index를 생성한다.
     */
    private function encryptOrderFields(array &$data): void
    {
        if (!$this->encryptionService) {
            return;
        }

        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (!empty($data[$field])) {
                $plainValue = $data[$field];
                $data[$field] = $this->encryptionService->encrypt($plainValue);

                // Blind Index 생성 (검색용)
                if (isset(self::INDEXED_FIELDS[$field])) {
                    $data[self::INDEXED_FIELDS[$field]] = $this->encryptionService->createSearchIndex($plainValue);
                }
            }
        }
    }

    /**
     * 주문 데이터 복호화 (조회 후)
     *
     * 복호화 실패 시 원본 값 유지 (기존 평문 데이터 하위 호환)
     */
    private function decryptOrderFields(array &$data): void
    {
        if (!$this->encryptionService) {
            return;
        }

        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (!empty($data[$field])) {
                $decrypted = $this->encryptionService->decrypt($data[$field]);
                if ($decrypted !== null) {
                    $data[$field] = $decrypted;
                }
                // null → 복호화 실패 = 기존 평문 데이터 → 그대로 유지
            }
        }
    }

    /**
     * 검색 필터에 Blind Index 추가
     *
     * keyword가 있으면 해당 값의 Blind Index를 생성하여 필터에 추가
     */
    private function prepareSearchFilters(array $filters): array
    {
        if (!empty($filters['keyword']) && $this->encryptionService) {
            $filters['keyword_index'] = $this->encryptionService->createSearchIndex($filters['keyword']);
        }

        return $filters;
    }

    /**
     * 주문 배열 복호화 헬퍼 (목록용)
     */
    private function decryptOrderArray(array $orderArray): array
    {
        $this->decryptOrderFields($orderArray);
        return $orderArray;
    }
}
