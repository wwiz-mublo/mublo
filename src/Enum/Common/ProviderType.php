<?php
namespace Mublo\Enum\Common;

/**
 * 제공자 타입 Enum
 *
 * 콘텐츠/메뉴 등의 제공자를 나타냅니다.
 * (Core, Plugin, Package)
 */
enum ProviderType: string
{
    case CORE = 'core';
    case PLUGIN = 'plugin';
    case PACKAGE = 'package';

    /**
     * 타입 라벨 반환
     */
    public function label(): string
    {
        return match($this) {
            self::CORE => 'Core',
            self::PLUGIN => 'Plugin',
            self::PACKAGE => 'Package',
        };
    }

    /**
     * 코드 접두사 반환 (AdminMenu 등에서 사용)
     */
    public function prefix(): string
    {
        return match($this) {
            self::CORE => '',
            self::PLUGIN => 'P_',
            self::PACKAGE => 'K_',
        };
    }

    /**
     * 외부 확장인지 여부
     */
    public function isExternal(): bool
    {
        return in_array($this, [self::PLUGIN, self::PACKAGE], true);
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
