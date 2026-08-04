<?php
declare(strict_types=1);
namespace Mublo\Controller\Api;

use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Service\Domain\DomainVerificationService;

/**
 * 도메인 도달 확인(프로브) 응답 컨트롤러
 *
 * 호스트명 변경 전, 서버가 자기 자신에게 "그 호스트명으로" 요청을 보내
 * 이 설치본에 도달하는지 확인한다. 이 엔드포인트가 그 요청에 답한다.
 *
 * 특수성: 검증 대상 호스트는 아직 domain_configs에 없다. 따라서 이 경로는
 * Application::isPublicApiRequest()에서 도메인 검증을 우회한다
 * (미등록 호스트로도 응답해야 프로브가 성립한다).
 *
 * 안전성:
 * - 발급된 pending nonce(1회성·30분)와 요청 Host가 모두 일치할 때만 200을 준다.
 * - nonce는 서버가 만든 64자 hex이므로 외부에서 추측할 수 없고,
 *   응답에는 요청에 실려온 값 외의 어떤 설치본 정보도 담지 않는다.
 */
class DomainVerifyController
{
    private DomainVerificationService $verificationService;

    public function __construct(DomainVerificationService $verificationService)
    {
        $this->verificationService = $verificationService;
    }

    /**
     * 프로브 응답
     *
     * GET /.well-known/mublo-domain-verify?nonce=...
     */
    public function verify(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();

        $nonce = (string) ($request->get('nonce') ?? '');
        $host = (string) ($request->getHost() ?? '');

        if (!$this->verificationService->acceptProbe($host, $nonce)) {
            // 유효하지 않은 요청에는 사유를 알려주지 않는다 (nonce 탐색 방지).
            return JsonResponse::error('Not found', [], 404);
        }

        return JsonResponse::success([
            'verified' => true,
            'nonce' => $nonce,
            'host' => $host,
        ], 'ok');
    }
}
