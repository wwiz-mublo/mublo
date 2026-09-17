<?php
declare(strict_types=1);

namespace Mublo\Service\Member;

use Mublo\Contract\Member\MemberAccountGatewayInterface;
use Mublo\Contract\Member\MemberProfile;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Contract\Member\MemberRegistrationRequest;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Result\Result;
use Mublo\Entity\Member\Member;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Service\Member\Event\MemberRegisteredByUserEvent;

final class MemberAccountGateway implements MemberAccountGatewayInterface
{
    public function __construct(
        private MemberRepository $members,
        private MemberService $memberService,
        private MemberQueryInterface $queries,
        private ?EventDispatcher $eventDispatcher = null
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

    public function create(MemberRegistrationRequest $request): ?int
    {
        $now = date('Y-m-d H:i:s');
        $memberId = $this->members->create([
            'domain_id' => $request->domainId,
            'origin_domain_id' => $request->originDomainId ?? $request->domainId,
            'domain_group' => $request->domainGroup,
            'user_id' => $request->userId,
            'password' => $request->passwordHash,
            'nickname' => $request->nickname,
            'level_value' => $request->levelValue,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $memberId ? (int) $memberId : null;
    }

    public function notifyRegistered(int $memberId): void
    {
        // MemberService::register 와 같은 사후 처리다 — 가입은 이미 커밋됐으므로
        // 리스너 하나가 실패해도 호출자에게 "가입 실패"로 돌려주지 않는다.
        try {
            $member = $this->members->find($memberId);
            if ($member instanceof Member) {
                $this->eventDispatcher?->dispatch(new MemberRegisteredByUserEvent($member));
            }
        } catch (\Throwable $e) {
            error_log('[MemberAccountGateway::notifyRegistered] post_commit_event_failed member_id=' . $memberId
                . ' ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
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
