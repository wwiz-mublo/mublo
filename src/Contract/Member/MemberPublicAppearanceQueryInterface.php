<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

interface MemberPublicAppearanceQueryInterface
{
    /**
     * 같은 도메인에 속한 회원의 공개 표시 정보를 일괄 조회한다.
     *
     * @param list<int> $memberIds
     * @return array<int, MemberPublicAppearance> memberId => appearance
     */
    public function forMembers(int $domainId, array $memberIds): array;
}
