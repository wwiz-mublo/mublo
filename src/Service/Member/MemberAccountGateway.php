<?php
declare(strict_types=1);

namespace Mublo\Service\Member;

use Mublo\Contract\Member\MemberAccountGatewayInterface;
use Mublo\Contract\Member\MemberLevelCatalogInterface;
use Mublo\Contract\Member\MemberProfile;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Contract\Member\MemberRegistrationRequest;
use Mublo\Entity\Member\Member;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Result\Result;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Service\Member\Event\MemberUpdatedEvent;

final class MemberAccountGateway implements MemberAccountGatewayInterface
{
    public function __construct(
        private MemberRepository $members,
        private MemberService $memberService,
        private MemberQueryInterface $queries,
        private MemberLevelCatalogInterface $levels,
        private ?EventDispatcher $events = null
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

    public function create(MemberRegistrationRequest $request, ?callable $persistRelated = null): int
    {
        return $this->memberService->registerAccount($request, $persistRelated);
    }

    public function hasLocalPassword(int $memberId): bool
    {
        $member = $this->members->find($memberId);

        return $member instanceof Member && $member->getPassword() !== '';
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

    public function withdrawWithPassword(int $memberId, string $password, string $reason = ''): Result
    {
        return $this->memberService->withdrawWithPassword($memberId, $password, $reason);
    }

    public function changeLevel(int $memberId, int $toLevel, array $onlyFrom): bool
    {
        $onlyFrom = array_values(array_unique(array_map('intval', $onlyFrom)));
        $this->assertAssignableLevel($toLevel, true);
        foreach ($onlyFrom as $level) {
            $this->assertAssignableLevel($level, false);
        }

        $member = $this->members->find($memberId);
        if (!$member instanceof Member
            || $member->getLevelValue() === $toLevel
            || !in_array($member->getLevelValue(), $onlyFrom, true)
        ) {
            return false;
        }

        if ($this->members->updateLevelIfIn($memberId, $toLevel, $onlyFrom) < 1) {
            return false; // 그사이 다른 곳에서 등급이 바뀌었다
        }

        try {
            $this->events?->dispatch(new MemberUpdatedEvent($member, ['level_value'], MemberUpdatedEvent::SOURCE_API));
        } catch (\Throwable $e) {
            error_log('[MemberAccountGateway::changeLevel] post_commit_event_failed member_id=' . $memberId
                . ' ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }

        return true;
    }

    /** 확장이 다룰 수 있는 등급인지 — 관리 권한 등급은 안 된다. 없는 등급은 대상일 때만 막는다. */
    private function assertAssignableLevel(int $levelValue, bool $mustExist): void
    {
        $level = $this->levels->findByValue($levelValue);
        if ($level === null) {
            if ($mustExist) {
                throw new \InvalidArgumentException("존재하지 않는 회원 등급입니다: {$levelValue}");
            }
            return;
        }
        if ($level->super || $level->admin || $level->canOperateDomain) {
            throw new \InvalidArgumentException("관리 권한이 있는 등급은 확장이 바꿀 수 없습니다: {$levelValue}");
        }
    }
}
