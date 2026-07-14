<?php

namespace Mublo\Core\Event\Domain;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Event\FailFastEventInterface;

/**
 * 도메인 소유자 검증 확장 이벤트
 *
 * 코어 기본 검증(회원 존재·운영 권한·도메인 그룹 범위) 통과 후,
 * 확장이 자체 정책을 추가로 검증할 수 있는 확장점.
 * DomainService::validateDomainOwner()에서 발행.
 *
 * 코어는 소유 사이트 수를 제한하지 않는다 — 운영 수 제한(예: 요금제별
 * 1사이트), 계약 상태 확인 같은 상용/임대 정책은 해당 패키지가
 * 이 이벤트를 구독해 얹는다.
 *
 * 설계 원칙 (MemberRegisterValidatingEvent와 동일):
 * - addError()는 에러만 쌓고, 나머지 구독자도 계속 실행됨
 * - 치명적 상황에서만 stopPropagation() 명시 호출
 *
 * 사용 예 (SaaS 패키지):
 * ```php
 * public function limitOwnedSites(DomainOwnerValidatingEvent $event): void
 * {
 *     if ($this->domainRepository->countByMemberId($event->getMemberId()) > 0) {
 *         $event->addError('해당 회원은 이미 사이트를 운영 중입니다.');
 *     }
 * }
 * ```
 */
class DomainOwnerValidatingEvent extends AbstractEvent implements FailFastEventInterface
{
    private int $memberId;
    private string $userId;
    private int $domainId;
    private string $adminDomainGroup;

    /** @var string[] */
    private array $errors = [];

    /**
     * @param int $memberId 소유자 후보 회원 ID (코어 검증 통과됨)
     * @param string $userId 소유자 후보 회원 아이디
     * @param int $domainId 검색 대상 도메인 ID
     * @param string $adminDomainGroup 등록자(관리자)의 domain_group
     */
    public function __construct(int $memberId, string $userId, int $domainId, string $adminDomainGroup)
    {
        $this->memberId = $memberId;
        $this->userId = $userId;
        $this->domainId = $domainId;
        $this->adminDomainGroup = $adminDomainGroup;
    }

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getAdminDomainGroup(): string
    {
        return $this->adminDomainGroup;
    }

    /**
     * 검증 에러 추가
     *
     * 전파는 중단되지 않음 — 다른 구독자의 검증도 계속 실행됨.
     */
    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
