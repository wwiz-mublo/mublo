<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Helper\Form\FormHelper;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Packages\Shop\Service\InquiryService;
use Mublo\Packages\Shop\Service\ShopConfigService;

class InquiryController
{
    private InquiryService $inquiryService;
    private AuthContextInterface $authService;
    private ShopConfigService $shopConfigService;

    public function __construct(
        InquiryService $inquiryService,
        AuthContextInterface $authService,
        ShopConfigService $shopConfigService
    ) {
        $this->inquiryService = $inquiryService;
        $this->authService = $authService;
        $this->shopConfigService = $shopConfigService;
    }

    /**
     * 상품별 문의 목록 (공개, 비밀글 처리 포함)
     */
    public function list(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $goodsId = (int) ($request->get('goods_id') ?? 0);
        $page = max(1, (int) ($request->get('page') ?? 1));
        $memberId = $this->authService->id() ?? 0;

        $result = $this->inquiryService->getList(
            $domainId,
            ['goods_id' => $goodsId, 'is_visible' => 1],
            $page,
            10
        );

        $pagination = $result['pagination'];
        $pagination['pageNums'] = 5;

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Inquiry/List'))
            ->withData([
                'items' => $result['items'],
                'pagination' => $pagination,
                'goodsId' => $goodsId,
                'currentMemberId' => $memberId,
            ]);
    }

    /**
     * 상품 상세 페이지 탭용 상품문의 (JSON, 비밀글 서버측 마스킹)
     */
    public function apiList(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $goodsId = (int) ($request->get('goods_id') ?? 0);
        $page = max(1, (int) ($request->get('page') ?? 1));
        $memberId = $this->authService->id() ?? 0;
        $isAdmin = $this->authService->isAdmin();

        if ($goodsId <= 0) {
            return JsonResponse::error('상품 정보가 올바르지 않습니다.');
        }

        $result = $this->inquiryService->getList(
            $domainId,
            ['goods_id' => $goodsId, 'is_visible' => 1],
            $page,
            10
        );

        $typeLabels = [
            'PRODUCT'  => '상품문의',
            'STOCK'    => '재고문의',
            'DELIVERY' => '배송문의',
            'OTHER'    => '기타',
        ];

        $items = array_map(function (array $row) use ($memberId, $isAdmin, $typeLabels): array {
            $isSecret = (bool) ($row['is_secret'] ?? false);
            $ownerId = (int) ($row['member_id'] ?? 0);
            $isOwner = $memberId > 0 && $ownerId === $memberId;
            $canView = !$isSecret || $isOwner || $isAdmin;
            $status = (string) ($row['inquiry_status'] ?? 'WAITING');

            return [
                'inquiry_id'  => (int) ($row['inquiry_id'] ?? 0),
                'type_label'  => $typeLabels[$row['inquiry_type'] ?? 'PRODUCT'] ?? '문의',
                'title'       => $canView ? (string) ($row['title'] ?? '') : '비밀글입니다.',
                'content'     => $canView ? (string) ($row['content'] ?? '') : '',
                'reply'       => $canView ? (string) ($row['reply'] ?? '') : '',
                'is_secret'   => $isSecret,
                'can_view'    => $canView,
                'is_owner'    => $isOwner,
                'is_replied'  => $status === 'REPLIED',
                'author'      => $this->maskName((string) ($row['author_name'] ?? '')),
                'created_at'  => substr((string) ($row['created_at'] ?? ''), 0, 10),
            ];
        }, $result['items']);

        return JsonResponse::success([
            'items'       => $items,
            'pagination'  => $result['pagination'],
            'is_logged_in' => $memberId > 0,
        ]);
    }

    /**
     * 작성자명 마스킹 (홍길동 → 홍*동, ab → a*)
     */
    private function maskName(string $name): string
    {
        $name = trim($name);
        $len = mb_strlen($name);
        if ($len <= 1) {
            return $name === '' ? '익명' : $name;
        }
        if ($len === 2) {
            return mb_substr($name, 0, 1) . '*';
        }
        return mb_substr($name, 0, 1)
            . str_repeat('*', $len - 2)
            . mb_substr($name, -1);
    }

    /**
     * 내 문의 목록 (로그인 필수)
     */
    public function myInquiries(array $params, Context $context): ViewResponse|RedirectResponse
    {
        if ($this->authService->guest()) {
            return RedirectResponse::to('/login');
        }

        $domainId = $context->getDomainId() ?? 1;
        $memberId = $this->authService->id() ?? 0;
        $request = $context->getRequest();
        $page = max(1, (int) ($request->get('page') ?? 1));

        $result = $this->inquiryService->getList(
            $domainId,
            ['member_id' => $memberId],
            $page,
            10
        );

        $pagination = $result['pagination'];
        $pagination['pageNums'] = 10;

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Inquiry/MyInquiries'))
            ->withData([
                'items' => $result['items'],
                'pagination' => $pagination,
            ]);
    }

    /**
     * 문의 등록 (AJAX, 로그인 필수)
     */
    public function store(array $params, Context $context): JsonResponse
    {
        if ($this->authService->guest()) {
            return JsonResponse::error('로그인이 필요합니다.', null, 401);
        }

        $domainId = $context->getDomainId() ?? 1;
        $memberId = $this->authService->id() ?? 0;
        $user = $this->authService->currentUser();
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());
        $data['member_id'] = $memberId;
        $data['author_name'] = $user?->publicDisplayName() ?? '';

        $result = $this->inquiryService->createInquiry($domainId, $data);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 문의 삭제 (본인만, 답변 전)
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        if ($this->authService->guest()) {
            return JsonResponse::error('로그인이 필요합니다.', null, 401);
        }

        $memberId = $this->authService->id() ?? 0;
        $request = $context->getRequest();
        $inquiryId = (int) ($request->json('inquiry_id') ?? 0);

        $inquiry = $this->inquiryService->getDetail($domainId, $inquiryId);
        if (!$inquiry) {
            return JsonResponse::error('문의를 찾을 수 없습니다.');
        }

        if ((int) $inquiry['member_id'] !== $memberId) {
            return JsonResponse::error('삭제 권한이 없습니다.', 403);
        }

        if ($inquiry['inquiry_status'] === 'REPLIED') {
            return JsonResponse::error('답변이 완료된 문의는 삭제할 수 없습니다.');
        }

        $result = $this->inquiryService->deleteInquiry($domainId, $inquiryId);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    private function getFormSchema(): array
    {
        return [
            'numeric' => ['goods_id', 'is_secret'],
            'enum' => [
                'inquiry_type' => ['values' => ['PRODUCT', 'STOCK', 'DELIVERY', 'OTHER'], 'default' => 'PRODUCT'],
            ],
        ];
    }
}
