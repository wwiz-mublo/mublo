<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Helper\Form\FormHelper;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Packages\Shop\Service\ReviewService;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Enum\OrderAction;

class ReviewController
{
    private ReviewService $reviewService;
    private FileUploader $fileUploader;
    private OrderService $orderService;

    public function __construct(
        ReviewService $reviewService,
        FileUploader $fileUploader,
        OrderService $orderService
    ) {
        $this->reviewService = $reviewService;
        $this->fileUploader = $fileUploader;
        $this->orderService = $orderService;
    }

    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $page = (int) ($request->query('page') ?? 1);
        $filters = array_filter([
            'is_visible' => $request->query('is_visible') ?? '',
            'keyword' => $request->query('keyword') ?? '',
        ], fn($v) => $v !== '');

        $result = $this->reviewService->getList($domainId, $filters, $page);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Review/List')
            ->withData([
                'pageTitle' => '구매후기 관리',
                'items' => $result['items'],
                'pagination' => $result['pagination'],
                'filters' => $filters,
            ]);
    }

    public function create(array $params, Context $context): ViewResponse
    {
        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Review/Form')
            ->withData([
                'pageTitle' => '구매후기 등록',
                'review' => null,
            ]);
    }

    /**
     * 후기 미작성 상품 목록 (관리자 후기 등록 모달).
     *
     * 구매확정 주문의 주문상세 중 아직 후기가 없는 항목만 평면으로 반환한다.
     * 관리자는 이 목록에서 상품 하나를 골라 바로 후기를 작성한다. (작성자는 해당 주문의 구매자)
     * keyword는 주문번호/상품명으로 검색된다.
     */
    public function reviewableItems(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $keyword = trim((string) ($request->query('keyword') ?? ''));
        $page = max(1, (int) ($request->query('page') ?? 1));

        $result = $this->orderService->getReviewableItems($domainId, $keyword, $page, 12);

        return JsonResponse::success([
            'items'      => $result->get('items', []),
            'pagination' => $result->get('pagination', []),
        ], '');
    }

    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $id = (int) ($params['id'] ?? $params[0] ?? $request->query('id', 0));

        $review = $this->reviewService->getDetail($domainId, $id);
        if (!$review) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withData(['message' => '후기를 찾을 수 없습니다.']);
        }

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Review/Form')
            ->withData([
                'pageTitle' => '구매후기 수정',
                'review' => $review,
            ]);
    }

    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        $reviewId = (int) ($data['review_id'] ?? 0);
        unset($data['review_id']);

        // 이미지 제거 처리 (사용자가 X 버튼을 누른 슬롯)
        $removeImages = $request->input('removeImages') ?? [];
        for ($i = 1; $i <= 3; $i++) {
            if (!empty($removeImages["image{$i}"])) {
                $data["image{$i}"] = null;
            }
        }

        // 이미지 업로드 처리 (fileData[image1..3]) — 업로드된 슬롯은 새 URL로 덮어씀
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
            }
        }

        // review_type 재계산: 이미지가 하나라도 남아있으면 PHOTO
        $existing = $reviewId > 0 ? ($this->reviewService->getDetail($domainId, $reviewId) ?? []) : [];
        $hasPhoto = false;
        for ($i = 1; $i <= 3; $i++) {
            $finalVal = array_key_exists("image{$i}", $data)
                ? $data["image{$i}"]
                : ($existing["image{$i}"] ?? null);
            if (!empty($finalVal)) {
                $hasPhoto = true;
                break;
            }
        }
        $data['review_type'] = $hasPhoto ? 'PHOTO' : 'TEXT';

        if ($reviewId) {
            $result = $this->reviewService->updateReview($domainId, $reviewId, $data);
        } else {
            // 관리자 등록: 주문번호 + 주문상세를 선택해 작성한다.
            // 상품(goods_id)·주문번호·구매자(member_id)는 클라이언트 값을 신뢰하지 않고
            // 주문에서 서버 파생한다.
            $orderNo = trim((string) ($data['order_no'] ?? ''));
            $orderDetailId = (int) ($data['order_detail_id'] ?? 0);

            if ($orderNo === '' || $orderDetailId <= 0) {
                return JsonResponse::error('주문번호와 후기를 작성할 상품을 선택해주세요.');
            }

            $orderResult = $this->orderService->getOrder($orderNo);
            if ($orderResult->isFailure()) {
                return JsonResponse::error('주문을 찾을 수 없습니다.');
            }
            $orderData = $orderResult->getData();
            $order = $orderData['order'] ?? [];
            if ((int) ($order['domain_id'] ?? 0) !== $domainId) {
                return JsonResponse::error('주문을 찾을 수 없습니다.');
            }

            // 후기는 구매확정된 주문에 대해서만 등록 가능
            if ((string) ($order['order_status'] ?? '') !== OrderAction::CONFIRMED->value) {
                return JsonResponse::error('구매확정된 주문만 후기를 등록할 수 있습니다.');
            }

            // 선택한 주문상세가 이 주문에 속하는지 확인하고 상품을 파생
            $matched = null;
            foreach (($orderData['items'] ?? []) as $it) {
                if ((int) ($it['order_detail_id'] ?? 0) === $orderDetailId) {
                    $matched = $it;
                    break;
                }
            }
            if ($matched === null) {
                return JsonResponse::error('선택한 상품이 주문에 존재하지 않습니다.');
            }

            // 주문상세당 후기 1개 제약 — 이미 작성된 상품이면 거부
            if ($this->reviewService->getByOrderDetailId($domainId, $orderDetailId) !== null) {
                return JsonResponse::error('이미 이 상품에 대한 후기가 작성되어 있습니다.');
            }

            $data['goods_id']        = (int) ($matched['goods_id'] ?? 0);
            $data['order_no']        = $orderNo;
            $data['order_detail_id'] = $orderDetailId;
            $data['member_id']       = (int) ($order['member_id'] ?? 0);

            $result = $this->reviewService->createReview($domainId, $data);
        }

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function reply(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $data = $request->json();
        $reviewId = (int) ($data['review_id'] ?? 0);
        $replyContent = trim($data['admin_reply'] ?? '');

        if (!$reviewId) {
            return JsonResponse::error('후기 ID가 필요합니다.');
        }

        $result = $this->reviewService->replyToReview($domainId, $reviewId, $replyContent);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function toggleVisibility(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $reviewId = (int) ($request->json('review_id') ?? 0);

        $result = $this->reviewService->toggleVisibility($domainId, $reviewId);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $reviewId = (int) ($request->json('review_id') ?? 0);

        $result = $this->reviewService->deleteReview($domainId, $reviewId);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function listModify(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $items = $request->json('items') ?? [];

        if (empty($items)) {
            return JsonResponse::error('수정할 항목이 없습니다.');
        }

        $result = $this->reviewService->batchUpdate($domainId, $items);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function listDelete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $reviewIds = $request->input('chk') ?? [];

        if (empty($reviewIds)) {
            return JsonResponse::error('삭제할 항목이 없습니다.');
        }

        $result = $this->reviewService->batchDelete($domainId, $reviewIds);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    private function getFormSchema(): array
    {
        return [
            'numeric' => ['review_id', 'goods_id', 'order_detail_id', 'member_id', 'rating', 'is_visible', 'is_best'],
        ];
    }
}
