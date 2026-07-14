<?php

namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Packages\Shop\Service\OrderFieldService;

/**
 * Admin OrderFieldController
 *
 * 주문 추가 필드 관리 API
 *
 * 라우팅:
 * - POST /admin/shop/order-fields/store        → store (생성/수정)
 * - POST /admin/shop/order-fields/delete        → delete (삭제)
 * - POST /admin/shop/order-fields/order-update  → orderUpdate (순서 변경)
 */
class OrderFieldController
{
    private OrderFieldService $orderFieldService;

    public function __construct(OrderFieldService $orderFieldService)
    {
        $this->orderFieldService = $orderFieldService;
    }

    /**
     * 필드 생성/수정
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? $request->json() ?? [];

        $result = $this->orderFieldService->saveField($domainId, $formData);

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 필드 삭제
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $fieldId = (int) ($request->input('field_id') ?? $request->json('field_id') ?? 0);

        if ($fieldId <= 0) {
            return JsonResponse::error('삭제할 필드를 선택해주세요.');
        }

        $result = $this->orderFieldService->deleteField($domainId, $fieldId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 순서 변경
     */
    public function orderUpdate(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $fieldIds = $request->input('field_ids') ?? $request->json('field_ids') ?? [];

        if (empty($fieldIds)) {
            return JsonResponse::error('순서 데이터가 없습니다.');
        }

        $result = $this->orderFieldService->updateOrder($domainId, $fieldIds);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }
}
