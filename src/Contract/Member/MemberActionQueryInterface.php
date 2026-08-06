<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

interface MemberActionQueryInterface
{
    /** @return list<MemberActionView> */
    public function forMember(MemberActionScope $scope, int $targetMemberId): array;

    /**
     * @param list<int> $targetMemberIds
     * @return array<int, list<MemberActionView>>
     */
    public function forMembers(MemberActionScope $scope, array $targetMemberIds): array;
}
