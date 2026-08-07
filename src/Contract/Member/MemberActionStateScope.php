<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

use InvalidArgumentException;

/** 상태 해석기에 제공하는 최소 범위. */
final readonly class MemberActionStateScope
{
    public function __construct(
        public int $domainId,
        public ?int $viewerMemberId,
    ) {
        if ($domainId <= 0 || ($viewerMemberId !== null && $viewerMemberId <= 0)) {
            throw new InvalidArgumentException('Invalid member action state scope.');
        }
    }
}
