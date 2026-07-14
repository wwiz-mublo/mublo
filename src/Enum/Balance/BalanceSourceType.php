<?php
namespace Mublo\Enum\Balance;

/**
 * 잔액 변경 출처 타입 Enum
 *
 * 포인트/잔액 변경의 출처를 나타냅니다.
 */
enum BalanceSourceType: string
{
    case CORE = 'core';
    case PLUGIN = 'plugin';
    case PACKAGE = 'package';
    case ADMIN = 'admin';
    case SYSTEM = 'system';

    /**
     * 출처 라벨 반환
     */
    public function label(): string
    {
        return match($this) {
            self::CORE => '코어',
            self::PLUGIN => '플러그인',
            self::PACKAGE => '패키지',
            self::ADMIN => '관리자',
            self::SYSTEM => '시스템',
        };
    }

    /**
     * 관리자/시스템 조정인지 확인
     */
    public function isAdjustment(): bool
    {
        return in_array($this, [self::ADMIN, self::SYSTEM], true);
    }

    /**
     * 외부 확장(Plugin/Package)에서 발생한 것인지 확인
     */
    public function isExternal(): bool
    {
        return in_array($this, [self::PLUGIN, self::PACKAGE], true);
    }

    /**
     * 코어에서 발생한 것인지 확인
     */
    public function isCore(): bool
    {
        return $this === self::CORE;
    }

    /**
     * 모든 출처 타입 목록 반환 (라벨 포함)
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
