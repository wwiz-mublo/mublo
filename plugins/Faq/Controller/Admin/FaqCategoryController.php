<?php
namespace Mublo\Plugin\Faq\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Plugin\Faq\Service\FaqService;

/**
 * FAQ 카테고리 관리 Controller (Admin)
 */
class FaqCategoryController
{
    private FaqService $faqService;

    public function __construct(FaqService $faqService)
    {
        $this->faqService = $faqService;
    }

    /**
     * 카테고리 생성
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $data = $request->json() ?: ($request->input('formData') ?? []);

        $result = $this->faqService->createCategory($domainId, $data);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 카테고리 수정
     */
    public function update(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $data = $request->json() ?: ($request->input('formData') ?? []);
        $categoryId = (int) ($data['category_id'] ?? 0);

        if ($categoryId <= 0) {
            return JsonResponse::error('카테고리를 선택해 주세요.');
        }

        $result = $this->faqService->updateCategory($domainId, $categoryId, $data);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 카테고리 삭제
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $data = $request->json() ?: ($request->input('formData') ?? []);
        $categoryId = (int) ($data['category_id'] ?? 0);

        if ($categoryId <= 0) {
            return JsonResponse::error('카테고리를 선택해 주세요.');
        }

        $result = $this->faqService->deleteCategory($domainId, $categoryId);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }
}
