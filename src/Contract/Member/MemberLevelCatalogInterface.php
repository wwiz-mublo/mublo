<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

interface MemberLevelCatalogInterface
{
    /** @return list<MemberLevelDescriptor> */
    public function all(): array;

    public function findByValue(int $levelValue): ?MemberLevelDescriptor;
}
