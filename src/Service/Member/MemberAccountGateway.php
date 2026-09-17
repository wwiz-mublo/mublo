<?php
declare(strict_types=1);

namespace Mublo\Service\Member;

use Mublo\Contract\Member\MemberAccountGatewayInterface;
use Mublo\Contract\Member\MemberProfile;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Contract\Member\MemberRegistrationRequest;
use Mublo\Core\Result\Result;
use Mublo\Repository\Member\MemberRepository;

final class MemberAccountGateway implements MemberAccountGatewayInterface
{
    public function __construct(
        private MemberRepository $members,
        private MemberService $memberService,
        private MemberQueryInterface $queries
    ) {
    }

    public function nicknameExists(
        int $domainId,
        string $nickname,
        bool $includeOriginDomain = false
    ): bool {
        return $this->members->existsByNickname($domainId, $nickname)
            || ($includeOriginDomain && $this->members->existsByOriginAndNickname($domainId, $nickname));
    }

    public function create(MemberRegistrationRequest $request, ?callable $persistRelated = null): ?int
    {
        return $this->memberService->registerAccount($request, $persistRelated);
    }

    public function verifyCredentials(int $domainId, string $userId, string $password): ?MemberProfile
    {
        $member = $this->members->findByDomainAndUserId($domainId, $userId);
        if ($member === null || !password_verify($password, $member->getPassword())) {
            return null;
        }

        return $this->queries->findProfile($member->getMemberId());
    }

    public function validateCustomFields(int $domainId, array $values): Result
    {
        return $this->memberService->validateFieldValues($domainId, $values);
    }

    public function saveCustomFields(int $memberId, int $domainId, array $values): void
    {
        $this->memberService->saveFieldValues($memberId, $values, $domainId);
    }

    public function customFieldDefinitions(int $domainId): array
    {
        return $this->memberService->getFieldDefinitions($domainId, true);
    }
}
