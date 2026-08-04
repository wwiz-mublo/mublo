<?php
declare(strict_types=1);
namespace Mublo\Service\AI;

/**
 * 프레임 템플릿 계약 검증 결과 (개선 계획 §7.1)
 */
final class FrameTemplateValidationResult
{
    /**
     * @param string[] $errors    자동 반영을 막는 계약 위반
     * @param string[] $warnings  반영은 허용하되 notes로 알리는 권고
     * @param string[] $usedTokens 템플릿에서 실제 사용된 토큰 이름 (중복 제거)
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $warnings,
        public readonly array $usedTokens,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
