<?php
namespace Mublo\Enum\Member;

/**
 * 회원 레벨 타입 Enum
 *
 * 회원 등급의 유형을 나타냅니다.
 */
enum MemberLevelType: string
{
    case SUPER = 'SUPER';
    case STAFF = 'STAFF';
    case PARTNER = 'PARTNER';
    case SITE_MASTER = 'SITE_MASTER';
    case SUPPLIER = 'SUPPLIER';
    case BASIC = 'BASIC';

    /**
     * 타입 라벨 반환
     */
    public function label(): string
    {
        return match($this) {
            self::SUPER => '최고관리자',
            self::STAFF => '스태프/직원',
            self::PARTNER => '파트너',
            self::SITE_MASTER => '사이트 운영자',
            self::SUPPLIER => '공급처',
            self::BASIC => '일반회원',
        };
    }

    /**
     * 관리자 모드 접근 가능 여부
     */
    public function canAccessAdmin(): bool
    {
        return in_array($this, [self::SUPER, self::STAFF], true);
    }

    /**
     * 판매자 계열 여부
     *
     * SITE_MASTER(사이트 운영자)는 자기 도메인의 최고 권한 등급이지 판매자 계열이 아니므로 제외한다.
     */
    public function isSeller(): bool
    {
        return in_array($this, [self::PARTNER, self::SUPPLIER], true);
    }

    /**
     * 최고관리자 여부
     */
    public function isSuper(): bool
    {
        return $this === self::SUPER;
    }

    /**
     * 모든 타입 목록 반환 (라벨 포함)
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
