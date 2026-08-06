<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

/** 확장이 등록하는 불변 회원 액션 정의. */
final readonly class MemberActionDefinition
{
    /**
     * @param list<string> $placements
     * @param array<string, MemberActionVariant> $variants
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $endpoint,
        public string $icon = '',
        public int $priority = 100,
        public array $placements = [],
        public bool $requiresLogin = true,
        public bool $allowSelf = false,
        public bool $allowProxyLogin = false,
        public int $minViewerLevel = 0,
        public MemberActionTargetTransport $targetTransport = MemberActionTargetTransport::PrivateBody,
        public bool $stateful = false,
        public array $variants = [],
        public string $onResolveFailure = MemberActionVariant::DEFAULT,
    ) {
    }
}
