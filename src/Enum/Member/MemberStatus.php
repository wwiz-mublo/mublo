<?php
namespace Mublo\Enum\Member;

/**
 * 회원 상태 Enum
 *
 * 회원의 계정 상태를 나타냅니다.
 */
enum MemberStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case DORMANT = 'dormant';
    case BLOCKED = 'blocked';
    case PENDING = 'pending';
    case WITHDRAWN = 'withdrawn';

    /**
     * 상태 라벨 반환
     */
    public function label(): string
    {
        return match($this) {
            self::ACTIVE => '활성',
            self::INACTIVE => '비활성',
            self::DORMANT => '휴면',
            self::BLOCKED => '차단',
            self::PENDING => '승인 대기',
            self::WITHDRAWN => '탈퇴',
        };
    }

    /**
     * 접근 가능한 상태인지 확인
     */
    public function isAccessible(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * 로그인 불가 상태인지 확인
     */
    public function isRestricted(): bool
    {
        return in_array($this, [self::BLOCKED, self::DORMANT, self::WITHDRAWN], true);
    }

    /**
     * 모든 상태 목록 반환 (라벨 포함)
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }
        return $options;
    }
}
