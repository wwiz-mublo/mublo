<?php
declare(strict_types=1);
namespace Mublo\Contract\Member;

/**
 * 내부 Member Entity/Repository를 노출하지 않는 읽기 전용 회원 조회 Contract.
 */
interface MemberQueryInterface
{
    public function findProfile(int $memberId): ?MemberProfile;

    /** @param int[] $memberIds @return list<MemberProfile> */
    public function findProfiles(array $memberIds): array;

    public function findByDomainAndUserId(int $domainId, string $userId): ?MemberProfile;
}
