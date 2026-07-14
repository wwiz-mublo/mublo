<?php

namespace Mublo\Core\Event\Member;

use Mublo\Core\Event\AbstractEvent;

/**
 * 회원 목록 조회 이벤트 (Query Event)
 *
 * Plugin/Package에서 Core 회원 데이터를 조회할 때 사용하는 이벤트.
 * 직접 MemberRepository를 의존하지 않고, 이벤트를 통해 느슨하게 연결.
 *
 * 흐름:
 *   Package → dispatch(MemberListQueryEvent) → Core 구독자 처리 → getMembers()
 *
 * 사용 예시 (Package/Plugin에서):
 *   $event = new MemberListQueryEvent($domainId, ['level_type' => 'SUPPLIER']);
 *   $event = $this->dispatch($event);
 *   $suppliers = $event->getMembers();
 *
 * 지원 조건:
 *   - level_type  : member_levels.level_type (SUPPLIER, PARTNER, SITE_MASTER 등)
 *   - level_value : members.level_value (정확한 레벨 값)
 *   - status      : members.status (active, inactive 등)
 *   - keyword     : members.userid 또는 nickname LIKE 검색
 */
class MemberListQueryEvent extends AbstractEvent
{
    private int $domainId;
    private array $criteria;
    private array $members = [];

    /**
     * @param int   $domainId 도메인 ID
     * @param array $criteria 조회 조건
     *   - level_type: string (SUPPLIER, PARTNER, SITE_MASTER, AGENCY, AGENT 등)
     *   - level_value: int (정확한 레벨 값)
     *   - status: string (active, inactive 등)
     *   - keyword: string (userid 또는 nickname 검색)
     *   - limit: int (최대 결과 수, 기본 1000)
     */
    public function __construct(int $domainId, array $criteria = [])
    {
        $this->domainId = $domainId;
        $this->criteria = $criteria;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getCriteria(): array
    {
        return $this->criteria;
    }

    /**
     * 특정 조건 값 반환
     */
    public function getCriterion(string $key, mixed $default = null): mixed
    {
        return $this->criteria[$key] ?? $default;
    }

    /**
     * Core 구독자가 조회 결과를 설정
     *
     * @param array $members [{member_id, userid, nickname, level_value, level_name, level_type, ...}, ...]
     */
    public function setMembers(array $members): void
    {
        $this->members = $members;
    }

    /**
     * 조회 결과 반환
     */
    public function getMembers(): array
    {
        return $this->members;
    }

    /**
     * 결과 존재 여부
     */
    public function hasMembers(): bool
    {
        return !empty($this->members);
    }
}
