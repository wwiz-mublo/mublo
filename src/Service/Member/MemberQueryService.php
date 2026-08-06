<?php
declare(strict_types=1);
namespace Mublo\Service\Member;

use Mublo\Contract\Member\MemberProfile;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Repository\Member\MemberRepository;

final class MemberQueryService implements MemberQueryInterface
{
    public function __construct(private MemberRepository $members)
    {
    }

    public function findProfile(int $memberId): ?MemberProfile
    {
        $member = $this->members->find($memberId);
        return $member instanceof \Mublo\Entity\Member\Member ? $this->profile($member) : null;
    }

    public function findProfiles(array $memberIds): array
    {
        return array_map(
            fn (\Mublo\Entity\Member\Member $member): MemberProfile => $this->profile($member),
            $this->members->findByIds($memberIds)
        );
    }

    public function findByDomainAndUserId(int $domainId, string $userId): ?MemberProfile
    {
        $member = $this->members->findByDomainAndUserId($domainId, $userId);
        return $member === null ? null : $this->profile($member);
    }

    public function findByPublicId(int $domainId, string $publicId): ?MemberProfile
    {
        $member = $this->members->findByPublicId($domainId, $publicId);
        return $member === null ? null : $this->profile($member);
    }

    public function searchActiveByNickname(int $domainId, string $nickname, int $limit = 10): array
    {
        return array_map(
            fn (\Mublo\Entity\Member\Member $member): MemberProfile => $this->profile($member),
            $this->members->searchActiveByNickname($domainId, $nickname, $limit)
        );
    }

    public function publicIdsFor(int $domainId, array $memberIds): array
    {
        return $this->members->publicIdsFor($domainId, $memberIds);
    }

    private function profile(\Mublo\Entity\Member\Member $member): MemberProfile
    {
        return new MemberProfile(
            memberId: $member->getMemberId(),
            domainId: $member->getDomainId(),
            userId: $member->getUserId(),
            nickname: $member->getNickname(),
            levelValue: $member->getLevelValue(),
            active: $member->isActive(),
            name: $member->getName(),
            phone: $member->getPhone(),
            email: $member->getEmail(),
            domainGroup: $member->getDomainGroup() ?? '',
            admin: $member->isAdmin(),
            levelType: $member->getLevelType(),
            publicId: $member->getPublicId(),
        );
    }
}
