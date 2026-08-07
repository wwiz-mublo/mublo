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

    public function findByPublicId(int $domainId, string $publicId): ?MemberProfile;

    /**
     * 같은 도메인의 활성 회원을 닉네임으로만 검색한다.
     *
     * 로그인 아이디 등 비공개 식별자는 검색 조건으로 사용하지 않는다.
     *
     * @return list<MemberProfile>
     */
    public function searchActiveByNickname(int $domainId, string $nickname, int $limit = 10): array;

    /**
     * @param list<int> $memberIds
     * @return array<int, string> memberId => publicId
     */
    public function publicIdsFor(int $domainId, array $memberIds): array;
}
