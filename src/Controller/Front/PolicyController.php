<?php

namespace Mublo\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Service\Member\PolicyService;

/**
 * Front PolicyController
 *
 * 프론트 약관/정책 열람 컨트롤러
 * - GET /policy/view/{slug}
 */
class PolicyController
{
    private PolicyService $policyService;

    public function __construct(PolicyService $policyService)
    {
        $this->policyService = $policyService;
    }

    /**
     * 약관/정책 상세 보기
     * GET /policy/view/{slug}
     */
    public function view(array $params, Request $request, Context $context): ViewResponse|RedirectResponse
    {
        $slug = $params['slug'] ?? '';

        if (empty($slug)) {
            return RedirectResponse::to('/');
        }

        $domainId = $context->getDomainId();
        $policy = $this->policyService->findBySlug($domainId, $slug);

        if (!$policy || !$policy->isActive()) {
            return RedirectResponse::to('/');
        }

        // 치환 변수 적용
        $domainConfig = $context->getDomainInfo()->toArray();
        $renderedContent = $this->policyService->replaceVariables(
            $policy->getPolicyContent(),
            $domainConfig,
            $policy
        );

        return ViewResponse::view('policy/view')
            ->withData([
                'policy' => $policy,
                'renderedContent' => $renderedContent,
            ]);
    }
}
