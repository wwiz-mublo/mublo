<?php

namespace Mublo\Core\Event\Member;

use Mublo\Core\Event\AbstractEvent;

/**
 * 회원 등급 목록 조회 이벤트 (Query Event)
 *
 * Plugin/Package에서 Core 회원 등급 데이터를 조회할 때 사용하는 이벤트.
 * 직접 MemberLevelRepository를 의존하지 않고, 이벤트를 통해 느슨하게 연결.
 *
 * 흐름:
 *   Plugin/Package → dispatch(MemberLevelListQueryEvent) → Core 구독자 처리 → getLevels()
 *
 * 사용 예시 (Plugin/Package에서):
 *   $event = new MemberLevelListQueryEvent();
 *   $event = $this->dispatch($event);
 *   $levels = $event->getLevels();          // [{level_value, level_name, ...}, ...]
 *   $options = $event->getOptionsForSelect(); // [level_value => level_name, ...]
 *
 * 지원 필터:
 *   - exclude_admin  : true 이면 is_admin=1 등급 제외
 *   - exclude_super  : true 이면 is_super=1 등급 제외
 *   - level_type     : 특정 level_type 만 조회 (SUPPLIER 등)
 */
class MemberLevelListQueryEvent extends AbstractEvent
{
    private array $filters;
    private array $levels = [];

    /**
     * @param array $filters 조회 조건
     *   - member_only   : bool — is_admin=0 AND is_super=0 인 일반 회원 레벨만 (권장)
     *   - exclude_admin : bool — member_only와 동일 효과 (exclude_super와 함께 쓸 때)
     *   - exclude_super : bool — member_only와 동일 효과 (exclude_admin과 함께 쓸 때)
     *   - level_type    : string|null — 특정 level_type 만 조회
     */
    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getFilter(string $key, mixed $default = null): mixed
    {
        return $this->filters[$key] ?? $default;
    }

    /**
     * Core 구독자가 조회 결과를 설정
     *
     * @param array $levels [{level_id, level_value, level_name, level_type, is_admin, is_super, ...}, ...]
     */
    public function setLevels(array $levels): void
    {
        $this->levels = $levels;
    }

    public function getLevels(): array
    {
        return $this->levels;
    }

    public function hasLevels(): bool
    {
        return !empty($this->levels);
    }

    /**
     * select box 용 옵션 반환
     *
     * @return array [level_value => level_name, ...]
     */
    public function getOptionsForSelect(): array
    {
        $options = [];
        foreach ($this->levels as $level) {
            $options[$level['level_value']] = $level['level_name'];
        }
        return $options;
    }
}
