<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Packages\Shop\Service\LevelPricingService;
use Mublo\Contract\Member\MemberLevelCatalogInterface;

class LevelPricingController
{
    private LevelPricingService $levelPricingService;
    private MemberLevelCatalogInterface $memberLevelService;

    public function __construct(
        LevelPricingService $levelPricingService,
        MemberLevelCatalogInterface $memberLevelService
    ) {
        $this->levelPricingService = $levelPricingService;
        $this->memberLevelService = $memberLevelService;
    }

    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $policies = $this->levelPricingService->getByDomain($domainId);
        $levels = array_map(fn($level) => [
            'level_id' => $level->levelId,
            'level_value' => $level->levelValue,
            'level_name' => $level->name,
            'level_type' => $level->type,
            'is_super' => $level->super,
            'is_admin' => $level->admin,
            'can_operate_domain' => $level->canOperateDomain,
        ], $this->memberLevelService->all());

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
