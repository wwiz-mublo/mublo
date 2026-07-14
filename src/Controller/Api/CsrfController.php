<?php
namespace Mublo\Controller\Api;

use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Infrastructure\Security\CsrfManager;

/**
 * CSRF 토큰 API 컨트롤러
 *
 * MubloRequest.js에서 사용하는 CSRF 토큰 엔드포인트
 */
class CsrfController
{
    protected CsrfManager $csrfManager;

    public function __construct(CsrfManager $csrfManager)
    {
        $this->csrfManager = $csrfManager;
    }

    /**
     * CSRF 토큰 발급
     *
     * GET /csrf/token
     *
     * @param array $params 라우트 파라미터
     * @param Context $context 요청 컨텍스트
     * @return JsonResponse
     */
    public function token(array $params, Context $context): JsonResponse
    {
        $token = $this->csrfManager->getToken();

        return JsonResponse::success([
            'token' => $token
        ]);
    }

    /**
     * CSRF 토큰 재생성
     *
     * POST /csrf/regenerate
     *
     * @param array $params 라우트 파라미터
     * @param Context $context 요청 컨텍스트
     * @return JsonResponse
     */
    public function regenerate(array $params, Context $context): JsonResponse
    {
        $token = $this->csrfManager->regenerateToken();

        return JsonResponse::success([
            'token' => $token
        ]);
    }
}
