<?php
declare(strict_types=1);

namespace Mublo\Service\Member;

use Mublo\Contract\Member\MemberLevelCatalogInterface;
use Mublo\Contract\Member\MemberLevelDescriptor;
use Mublo\Entity\Member\MemberLevel;

final class MemberLevelCatalog implements MemberLevelCatalogInterface
{
    public function __construct(private MemberLevelService $levels)
    {
    }

    public function all(): array
    {
        return array_map(fn (MemberLevel $level): MemberLevelDescriptor => $this->describe($level), $this->levels->getAll());
    }

    public function findByValue(int $levelValue): ?MemberLevelDescriptor
    {
        $level = $this->levels->findByValue($levelValue);
        return $level === null ? null : $this->describe($level);
    }

    private function describe(MemberLevel $level): MemberLevelDescriptor
    {
        return new MemberLevelDescriptor(
            $level->getLevelId(),
            $level->getLevelValue(),
            $level->getLevelName(),
            $level->getLevelType(),
            $level->isSuper(),
            $level->canAccessAdmin(),
            $level->canOperateDomain()
        );
    }
}
