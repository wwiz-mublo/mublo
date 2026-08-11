<?php
declare(strict_types=1);
namespace Mublo\Service\Member\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Entity\Member\Member;

/**
 * 회원 정보 수정 완료 이벤트 (부모 클래스)
 *
 * 회원 정보가 수정된 후 발행되는 이벤트의 기본 클래스
 *
 * 구독 방법:
 * - MemberUpdatedEvent::class 구독 → 모든 수정에 반응
 * - MemberUpdatedBySelfEvent::class 구독 → 본인 수정만
 * - MemberUpdatedByAdminEvent::class 구독 → 관리자 수정만
 *
 * 활용 예시:
 * - 로그 Plugin: 변경 이력 기록
 * - 알림 Plugin: 정보 변경 알림 발송
 */
class MemberUpdatedEvent extends AbstractEvent
{
    public const SOURCE_SELF = 'self';
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_API = 'api';

    protected Member $member;
    protected array $changes;
    protected string $source;

    /**
     * @param Member $member 수정 '전' 회원 엔티티 (getMember() 주의사항 참조)
     * @param array $changes 변경된 필드 목록 (필드명 배열)
     * @param string $source 수정 출처 (self|admin|api)
     */
    public function __construct(Member $member, array $changes = [], string $source = self::SOURCE_SELF)
    {
        $this->member = $member;
        $this->changes = $changes;
        $this->source = $source;
    }

    /**
     * 회원 엔티티 반환 — 수정 '전' 스냅샷이다
     *
     * 발행자는 수정 시작 시점에 읽어둔 엔티티를 그대로 실어 보내며, DB 갱신 후에도
     * 이 객체를 다시 읽지 않는다. 따라서 변경된 컬럼(등급·상태 등)은 옛 값을 담고 있다.
     *
     * @deprecated 다음 major 에서 제거한다. Member 는 코어 내부 엔티티이므로 확장이 여기에
     *             묶이면 코어 리팩터링이 확장을 깨뜨린다. 확장은 이 이벤트의 스칼라 게터
     *             (getMemberId()·getDomainId()·getPreviousLevelValue()) 를 쓰고, 그 밖의
     *             변경 '후' 값이 필요하면 Contract\Member\MemberQueryInterface 로 재조회할 것.
     */
    public function getMember(): Member
    {
        return $this->member;
    }

    /**
     * 회원 ID 반환 (편의 메서드)
     */
    public function getMemberId(): int
    {
        return $this->member->getMemberId();
    }

    /**
     * 도메인 ID 반환 (편의 메서드)
     */
    public function getDomainId(): int
    {
        return $this->member->getDomainId();
    }

    /**
     * 수정 '전' 등급값 반환
     *
     * 등급이 실제로 바뀌었는지는 isLevelChanged() 로 확인한다 — 바뀌지 않았다면 이 값이
     * 곧 현재 등급이다. 변경 '후' 등급은 이 이벤트에 없다. 커밋된 값이므로
     * Contract\Member\MemberQueryInterface 로 조회하는 것이 정확하다.
     */
    public function getPreviousLevelValue(): int
    {
        return $this->member->getLevelValue();
    }

    /**
     * 변경된 필드 목록 반환
     *
     * @return array ['password', 'level_value', 'fields', ...]
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * 특정 필드가 변경되었는지 확인
     */
    public function hasChanged(string $fieldName): bool
    {
        return in_array($fieldName, $this->changes, true);
    }

    /**
     * 비밀번호가 변경되었는지 확인
     */
    public function isPasswordChanged(): bool
    {
        return $this->hasChanged('password');
    }

    /**
     * 등급이 변경되었는지 확인
     */
    public function isLevelChanged(): bool
    {
        return $this->hasChanged('level_value');
    }

    /**
     * 상태가 변경되었는지 확인
     */
    public function isStatusChanged(): bool
    {
        return $this->hasChanged('status');
    }

    /**
     * 추가 필드가 변경되었는지 확인
     */
    public function isFieldsChanged(): bool
    {
        return $this->hasChanged('fields');
    }

    /**
     * 수정 출처 반환
     *
     * @return string 'self' | 'admin' | 'api'
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * 본인 수정인지 확인
     */
    public function isSelfUpdate(): bool
    {
        return $this->source === self::SOURCE_SELF;
    }

    /**
     * 관리자 수정인지 확인
     */
    public function isAdminUpdate(): bool
    {
        return $this->source === self::SOURCE_ADMIN;
    }
}
