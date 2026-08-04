<?php
declare(strict_types=1);
namespace Mublo\Contract\AI;

/**
 * Package와 Plugin이 도메인 AI 설정을 안전하게 사용하는 공개 계약.
 *
 * API 키 원문은 반환하지 않는다. Core가 공급자 호출, 사용량 제한,
 * 도메인 자산 해석을 대신 수행하고 구조화된 결과만 돌려준다.
 */
interface AiGatewayInterface
{
    public function isAvailable(int $domainId): bool;

    public function generate(int $domainId, AiRequest $request): AiResponse;
}
