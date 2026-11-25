<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

/** 확장 뷰에 노출해도 되는 회원 표시 정보. 내부 회원 ID나 필드 저장 구조는 포함하지 않는다. */
final readonly class MemberPublicAppearance
{
    public function __construct(
        public string $publicId = '',
        public string $avatarUrl = '',
        public bool $super = false,
    ) {
    }
}
