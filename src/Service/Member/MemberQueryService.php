<?php
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
        if ($member === null) {
            return null;
        }

        return new MemberProfile(
            memberId: $member->getMemberId(),
            domainId: $member->getDomainId(),
            userId: $member->getUserId(),
            nickname: $member->getNickname(),
            levelValue: $member->getLevelValue(),
            active: $member->isActive(),
        );
    }
}
