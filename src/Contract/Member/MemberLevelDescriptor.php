<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

final readonly class MemberLevelDescriptor
{
    public function __construct(
        public int $levelId,
        public int $levelValue,
        public string $name,
        public string $type,
        public bool $super,
        public bool $admin,
        public bool $canOperateDomain
    ) {
    }
}
