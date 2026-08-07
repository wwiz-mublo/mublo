<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

use InvalidArgumentException;

/** 소비 화면이 회원 액션 조회에 제공하는 요청 범위. */
final readonly class MemberActionScope
{
    /** @var list<string> */
    public array $placements;

    /** @param list<string> $placements 구체적인 위치부터 상위 위치 순서 */
    public function __construct(
        public int $domainId,
        public ?int $viewerMemberId,
        public bool $proxyLogin,
        public int $viewerLevel,
        array $placements,
    ) {
        if ($domainId <= 0) {
            throw new InvalidArgumentException('domainId must be greater than zero.');
        }
        if ($viewerMemberId !== null && $viewerMemberId <= 0) {
            throw new InvalidArgumentException('viewerMemberId must be null or greater than zero.');
        }
        if ($viewerLevel < 0) {
            throw new InvalidArgumentException('viewerLevel must not be negative.');
        }
        if ($viewerMemberId === null && ($viewerLevel !== 0 || $proxyLogin)) {
            throw new InvalidArgumentException('Guest scope cannot have a level or proxy login state.');
        }
        if ($placements === []) {
            throw new InvalidArgumentException('At least one placement is required.');
        }

        foreach ($placements as $placement) {
            if (!is_string($placement)
                || preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $placement) !== 1
            ) {
                throw new InvalidArgumentException('Invalid member action placement.');
            }
        }

        $this->placements = array_values(array_unique($placements));
    }
}
