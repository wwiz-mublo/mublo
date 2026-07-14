<?php

namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Helper\Form\FormHelper;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Packages\Shop\Service\InquiryService;

class InquiryController
{
    private AuthContextInterface $authService;
    private InquiryService $inquiryService;

    public function __construct(AuthContextInterface $authService, InquiryService $inquiryService)
    {
        $this->authService = $authService;
        $this->inquiryService = $inquiryService;
    }

    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $page = (int) ($request->query('page') ?? 1);
        $filters = array_filter([
            'inquiry_status' => $request->query('inquiry_status') ?? '',
            'keyword' => $request->query('keyword') ?? '',
        ], fn($v) => $v !== '');

        $result = $this->inquiryService->getList($domainId, $filters, $page);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Inquiry/List')
            ->withData([
                'pageTitle' => '상품문의 관리',
                'items' => $result['items'],
                'pagination' => $result['pagination'],
                'filters' => $filters,
            ]);
    }

    public function create(array $params, Context $context): ViewResponse
    {
        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Inquiry/Form')
            ->withData([
                'pageTitle' => '상품문의 등록',
                'inquiry' => null,
            ]);
    }

    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $id = (int) ($params['id'] ?? $params[0] ?? $request->query('id', 0));

        $inquiry = $this->inquiryService->getDetail($domainId, $id);
        if (!$inquiry) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withData(['message' => '문의를 찾을 수 없습니다.']);
        }

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Inquiry/Form')
            ->withData([
                'pageTitle' => '상품문의 수정',
                'inquiry' => $inquiry,
            ]);
    }

    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        $inquiryId = (int) ($data['inquiry_id'] ?? 0);
        unset($data['inquiry_id']);

        if ($inquiryId) {
            $result = $this->inquiryService->updateInquiry($domainId, $inquiryId, $data);
        } else {
            $result = $this->inquiryService->createInquiry($domainId, $data);
        }

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function answer(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $data = $request->json();
        $inquiryId = (int) ($data['inquiry_id'] ?? 0);
        $reply = trim($data['reply'] ?? '');

        if (!$inquiryId) {
            return JsonResponse::error('문의 ID가 필요합니다.');
        }

        $staffId = (int) ($this->authService->id() ?? 0);

        $result = $this->inquiryService->answer($domainId, $inquiryId, $reply, $staffId);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $inquiryId = (int) ($request->json('inquiry_id') ?? 0);

        $result = $this->inquiryService->deleteInquiry($domainId, $inquiryId);

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

        $result = $this->inquiryService->batchUpdate($domainId, $items);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function listDelete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $inquiryIds = $request->input('chk') ?? [];

        if (empty($inquiryIds)) {
            return JsonResponse::error('삭제할 항목이 없습니다.');
        }

        $result = $this->inquiryService->batchDelete($domainId, $inquiryIds);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    private function getFormSchema(): array
    {
        return [
            'numeric' => ['inquiry_id', 'goods_id', 'is_secret'],
        ];
    }
}
