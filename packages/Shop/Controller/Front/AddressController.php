<?php

namespace Mublo\Packages\Shop\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Packages\Shop\Service\MemberAddressService;

/**
 * Front 배송지 주소록 컨트롤러
 *
 * /shop/address/* JSON API
 */
class AddressController
{
    private MemberAddressService $addressService;
    private AuthContextInterface $authService;

    public function __construct(
        MemberAddressService $addressService,
        AuthContextInterface $authService
    ) {
        $this->addressService = $addressService;
        $this->authService = $authService;
    }

    /**
     * 배송지 목록 조회
     */
    public function list(array $params, Context $context): JsonResponse
    {
        $memberId = $this->authService->id();
        if (!$memberId) {
            return JsonResponse::error('로그인이 필요합니다.', null, 401);
        }

        $domainId = $context->getDomainId() ?? 1;
        $result = $this->addressService->getList($memberId, $domainId);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 배송지 추가
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $memberId = $this->authService->id();
        if (!$memberId) {
            return JsonResponse::error('로그인이 필요합니다.', null, 401);
        }

        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;

        $result = $this->addressService->create($memberId, $domainId, [
            'address_name' => $request->json('address_name') ?? '',
            'recipient_name' => $request->json('recipient_name') ?? '',
            'recipient_phone' => $request->json('recipient_phone') ?? '',
            'zip_code' => $request->json('zip_code') ?? '',
            'address1' => $request->json('address1') ?? '',
            'address2' => $request->json('address2') ?? '',
            'is_default' => $request->json('is_default') ?? 0,
        ]);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 배송지 수정
     */
    public function update(array $params, Context $context): JsonResponse
    {
        $memberId = $this->authService->id();
        if (!$memberId) {
            return JsonResponse::error('로그인이 필요합니다.', null, 401);
        }

        $request = $context->getRequest();
        $addressId = (int) ($request->json('address_id') ?? 0);

        if ($addressId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $result = $this->addressService->update($memberId, $addressId, [
            'address_name' => $request->json('address_name') ?? '',
            'recipient_name' => $request->json('recipient_name') ?? '',
            'recipient_phone' => $request->json('recipient_phone') ?? '',
            'zip_code' => $request->json('zip_code') ?? '',
            'address1' => $request->json('address1') ?? '',
            'address2' => $request->json('address2') ?? '',
        ]);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 배송지 삭제
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $memberId = $this->authService->id();
        if (!$memberId) {
            return JsonResponse::error('로그인이 필요합니다.', null, 401);
        }

        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $addressId = (int) ($request->json('address_id') ?? 0);

        if ($addressId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $result = $this->addressService->delete($memberId, $domainId, $addressId);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 기본 배송지 설정
     */
    public function setDefault(array $params, Context $context): JsonResponse
    {
        $memberId = $this->authService->id();
        if (!$memberId) {
            return JsonResponse::error('로그인이 필요합니다.', null, 401);
        }

        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $addressId = (int) ($request->json('address_id') ?? 0);

        if ($addressId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $result = $this->addressService->setDefault($memberId, $domainId, $addressId);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }
}
