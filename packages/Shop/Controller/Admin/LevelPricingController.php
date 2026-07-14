<?php

namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Packages\Shop\Service\LevelPricingService;
use Mublo\Service\Member\MemberLevelService;

class LevelPricingController
{
    private LevelPricingService $levelPricingService;
    private MemberLevelService $memberLevelService;

    public function __construct(
        LevelPricingService $levelPricingService,
        MemberLevelService $memberLevelService
    ) {
        $this->levelPricingService = $levelPricingService;
        $this->memberLevelService = $memberLevelService;
    }

    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $policies = $this->levelPricingService->getByDomain($domainId);
        $levels = array_map(fn($l) => $l->toArray(), $this->memberLevelService->getAll());

        // 정책 맵 생성 (level_value => policy)
        $policyMap = [];
        foreach ($policies as $p) {
            $policyMap[(int) $p['level_value']] = $p;
        }

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/LevelPricing/Index')
            ->withData([
                'pageTitle' => '등급별 가격 정책',
                'levels' => $levels,
                'policyMap' => $policyMap,
            ]);
    }

    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $data = $request->json();

        $levelValue = (int) ($data['level_value'] ?? 0);
        if ($levelValue <= 0) {
            return JsonResponse::error('등급 정보가 없습니다.');
        }

        $result = $this->levelPricingService->savePolicy($domainId, $levelValue, $data);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $levelValue = (int) ($request->json('level_value') ?? 0);

        $result = $this->levelPricingService->deletePolicy($domainId, $levelValue);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }
}
