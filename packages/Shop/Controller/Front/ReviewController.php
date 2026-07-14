<?php

namespace Mublo\Packages\Shop\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Session\SessionInterface;
use Mublo\Helper\Form\FormHelper;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Packages\Shop\Service\ReviewService;
use Mublo\Packages\Shop\Service\ShopConfigService;

class ReviewController
{
    /** 비회원 주문 소유 세션 키 (Front\OrderController와 동일 규약) */
    private const GUEST_ORDER_SESSION_KEY = 'shop_guest_order_nos';

    private ReviewService $reviewService;
    private OrderService $orderService;
    private OrderStateResolver $orderStateResolver;
    private AuthContextInterface $authService;
    private FileUploader $fileUploader;
    private ShopConfigService $shopConfigService;
    private SessionInterface $session;

    public function __construct(
        ReviewService $reviewService,
        OrderService $orderService,
        OrderStateResolver $orderStateResolver,
        AuthContextInterface $authService,
        FileUploader $fileUploader,
        ShopConfigService $shopConfigService,
        SessionInterface $session
    ) {
        $this->reviewService = $reviewService;
        $this->orderService = $orderService;
        $this->orderStateResolver = $orderStateResolver;
        $this->authService = $authService;
        $this->fileUploader = $fileUploader;
        $this->shopConfigService = $shopConfigService;
        $this->session = $session;
    }

    /**
     * 비회원 주문 소유권 (세션에 기록된 주문번호인지) — Front\OrderController와 동일 규약.
     */
    private function isGuestOrderOwner(string $orderNo): bool
    {
        $list = $this->session->get(self::GUEST_ORDER_SESSION_KEY, []);
        return $orderNo !== '' && is_array($list) && in_array($orderNo, $list, true);
    }

    /**
     * 주문상세 항목이 요청자의 "구매확정" 상품인지 검증 (회원=member_id / 비회원=세션 소유).
     * 통과 시 신뢰값(order_no, goods_id, member_id)을 반환. (비회원은 member_id=0)
     *
     * @return array{ok:bool, message?:string, order_no?:string, goods_id?:int, member_id?:int}
     */
    private function verifyOwnedReviewable(int $domainId, int $orderDetailId): array
    {
        if ($orderDetailId <= 0) {
            return ['ok' => false, 'message' => '주문 정보가 올바르지 않습니다.'];
        }

        $source = $this->orderService->getReviewSource($domainId, $orderDetailId);
        if ($source === null) {
            return ['ok' => false, 'message' => '주문 상품을 찾을 수 없습니다.'];
        }
        $item = $source['item'];
        $order = $source['order'];
        $orderNo = (string) ($item['order_no'] ?? '');

        // 소유권: 회원은 member_id 일치, 비회원은 세션 소유. (비회원 주문도 동일하게 후기 가능)
        if ($this->authService->check()) {
            $memberId = (int) ($this->authService->id() ?? 0);
            if ((int) ($order['member_id'] ?? 0) !== $memberId) {
                return ['ok' => false, 'message' => '본인이 구매한 상품만 후기를 작성할 수 있습니다.'];
            }
            $reviewMemberId = $memberId;
        } else {
            if (!$this->isGuestOrderOwner($orderNo)) {
                return ['ok' => false, 'message' => '본인이 구매한 상품만 후기를 작성할 수 있습니다.'];
            }
            $reviewMemberId = 0; // 비회원 후기 — 표시는 "비회원"(이름 저장 안 함)
        }

        // 주문 헤더의 구매확정을 우선 신호로 사용 (항목 status는 종종 초기값에 머무름).
        // 항목이 취소/반품 계열이면 제외.
        $orderAction = $this->resolveAction($domainId, (string) ($order['order_status'] ?? ''));
        $itemAction = $this->resolveAction($domainId, (string) ($item['status'] ?? ''));

        if (!$this->isReviewable($orderAction, $itemAction)) {
            return ['ok' => false, 'message' => '구매확정된 상품에만 후기를 작성할 수 있습니다.'];
        }

        return [
            'ok'        => true,
            'order_no'  => $orderNo,
            'goods_id'  => (int) $item['goods_id'],
            'member_id' => $reviewMemberId,
        ];
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
     * 후기 작성 가능 여부 (OrderController::isReviewable 와 동일 규칙).
     * 주문 구매확정 또는 항목 구매확정이면 허용하되, 항목이 취소/반품 계열이면 제외.
     */
    private function isReviewable(?OrderAction $orderAction, ?OrderAction $itemAction): bool
    {
        $excluded = [
            OrderAction::CANCEL_REQUESTED,
            OrderAction::CANCELLED,
            OrderAction::RETURN_REQUESTED,
            OrderAction::RETURNED,
        ];

        if ($itemAction === OrderAction::CONFIRMED) {
            return true;
        }
        if ($itemAction !== null && in_array($itemAction, $excluded, true)) {
            return false;
        }
        return $orderAction === OrderAction::CONFIRMED;
    }

    /**
     * 상품별 구매후기 목록 (공개)
     */
    public function list(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $goodsId = (int) ($request->get('goods_id') ?? 0);
        $page = max(1, (int) ($request->get('page') ?? 1));

        $result = $this->reviewService->getByGoodsId($domainId, $goodsId, $page);
        $avgRating = $this->reviewService->getAverageRating($domainId, $goodsId);

        $pagination = $result['pagination'];
        $pagination['pageNums'] = 5;

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Review/List'))
            ->withData([
                'items' => $result['items'],
                'pagination' => $pagination,
                'goodsId' => $goodsId,
                'avgRating' => $avgRating,
            ]);
    }

    /**
     * 상품 상세 페이지 탭용 구매후기 (JSON, 공개)
     */
    public function apiList(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $goodsId = (int) ($request->get('goods_id') ?? 0);
        $page = max(1, (int) ($request->get('page') ?? 1));

        if ($goodsId <= 0) {
            return JsonResponse::error('상품 정보가 올바르지 않습니다.');
        }

        $result = $this->reviewService->getByGoodsId($domainId, $goodsId, $page, 5);
        $summary = $this->reviewService->getSummary($domainId, $goodsId);

        $items = array_map(static function (array $row): array {
            $images = array_values(array_filter([
                $row['image1'] ?? null,
                $row['image2'] ?? null,
                $row['image3'] ?? null,
            ]));

            // 작성자: 닉네임 원본 > 아이디, 비회원(닉네임 없음)은 빈값
            $author = trim((string) ($row['nickname'] ?? ''));
            if ($author === '') {
                $author = trim((string) ($row['user_id'] ?? ''));
            }

            return [
                'review_id'      => (int) ($row['review_id'] ?? 0),
                'rating'         => (int) ($row['rating'] ?? 5),
                'content'        => (string) ($row['content'] ?? ''),
                'author'         => $author,
                'is_photo'       => ($row['review_type'] ?? 'TEXT') === 'PHOTO',
                'images'         => $images,
                'admin_reply'    => (string) ($row['admin_reply'] ?? ''),
                'admin_reply_at' => substr((string) ($row['admin_reply_at'] ?? ''), 0, 10),
                'created_at'     => substr((string) ($row['created_at'] ?? ''), 0, 10),
            ];
        }, $result['items']);

        return JsonResponse::success([
            'items'      => $items,
            'pagination' => $result['pagination'],
            'summary'    => $summary,
        ]);
    }

    /**
     * 내 구매후기 목록 (로그인 필수)
     */
    public function myReviews(array $params, Context $context): ViewResponse|RedirectResponse
    {
        if ($this->authService->guest()) {
            return RedirectResponse::to('/login');
        }

        $domainId = $context->getDomainId() ?? 1;
        $memberId = $this->authService->id() ?? 0;
        $request = $context->getRequest();
        $page = max(1, (int) ($request->get('page') ?? 1));

        $result = $this->reviewService->getList(
            $domainId,
            ['member_id' => $memberId],
            $page,
            10
        );
        $pagination = $result['pagination'];
        $pagination['pageNums'] = 10;

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Review/MyReviews'))
            ->withData([
                'items' => $result['items'],
                'pagination' => $pagination,
            ]);
    }

    /**
     * 구매후기 작성 폼 (로그인 필수, 구매확정 주문 검증)
     */
    public function form(array $params, Context $context): ViewResponse|RedirectResponse
    {
        // 회원/비회원 모두 폼 렌더 가능(실제 소유·상태 검증은 store에서). 비회원은 주문조회로 진입.
        $request = $context->getRequest();
        $orderDetailId = (int) ($request->get('order_detail_id') ?? 0);
        $domainId = $context->getDomainId() ?? 1;

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Review/Form'))
            ->withData([
                'orderDetailId' => $orderDetailId,
            ]);
    }

    /**
     * 구매후기 작성 (AJAX, 로그인 필수)
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        // 클라이언트가 신뢰값을 위조하지 못하도록 제거 — 소유권 검증에서 서버가 채운다.
        // (is_best/is_visible: 평판 조작 방지 → DB 기본값·관리자 경로에서만 지정)
        unset($data['is_best'], $data['is_visible'], $data['member_id']);

        // 주문 소유(회원 member_id / 비회원 세션) + 구매확정 검증 — goods_id/order_no/member_id는
        // 서버에서 신뢰값으로 산출. (비회원은 member_id=0 → 표시는 "비회원")
        $orderDetailId = (int) ($data['order_detail_id'] ?? 0);
        $verify = $this->verifyOwnedReviewable($domainId, $orderDetailId);
        if (!$verify['ok']) {
            return JsonResponse::error($verify['message']);
        }
        $data['goods_id'] = $verify['goods_id'];
        $data['order_no'] = $verify['order_no'];
        $data['member_id'] = $verify['member_id'];

        // 중복 작성 방지
        if ($this->reviewService->getByOrderDetailId($domainId, $orderDetailId)) {
            return JsonResponse::error('이미 작성된 후기가 있습니다.');
        }

        // 이미지 업로드 처리 (fileData[image1..3])
        for ($i = 1; $i <= 3; $i++) {
            $uploaded = UploadedFile::fromGlobalNested('fileData', "image{$i}");
            $file = $uploaded[0] ?? null;
            if (!$file || !$file->isValid()) {
                continue;
            }

            $uploadResult = $this->fileUploader->upload($file, $domainId, [
                'subdirectory'       => 'shop/review',
                'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
                'max_size'           => 5 * 1024 * 1024,
            ]);

            if ($uploadResult->isSuccess()) {
                $data["image{$i}"] = $this->fileUploader->getUrl(
                    $uploadResult->getRelativePath(),
                    $uploadResult->getStoredName()
                );
                $data['review_type'] = 'PHOTO';
            }
        }

        $result = $this->reviewService->createReview($domainId, $data);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 구매후기 삭제 (본인만)
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $reviewId = (int) ($request->json('review_id') ?? 0);

        $review = $this->reviewService->getDetail($domainId, $reviewId);
        if (!$review) {
            return JsonResponse::error('후기를 찾을 수 없습니다.');
        }

        // 소유권: 회원 후기는 member_id 일치, 비회원 후기(member_id=0)는 세션 주문 소유.
        $reviewMemberId = (int) ($review['member_id'] ?? 0);
        if ($this->authService->check()) {
            if ($reviewMemberId !== (int) ($this->authService->id() ?? 0)) {
                return JsonResponse::error('삭제 권한이 없습니다.', 403);
            }
        } else {
            if ($reviewMemberId !== 0 || !$this->isGuestOrderOwner((string) ($review['order_no'] ?? ''))) {
                return JsonResponse::error('삭제 권한이 없습니다.', 403);
            }
        }

        $result = $this->reviewService->deleteReview($domainId, $reviewId);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    private function getFormSchema(): array
    {
        return [
            'numeric' => ['goods_id', 'order_detail_id', 'rating'],
            'html' => ['content'],
        ];
    }
}
