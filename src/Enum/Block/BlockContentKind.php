<?php
namespace Mublo\Enum\Block;

/**
 * 블록 콘텐츠 종류 Enum
 *
 * 블록 콘텐츠의 제공자를 나타냅니다.
 * (대문자 사용 - DB 저장값)
 */
enum BlockContentKind: string
{
    case CORE = 'CORE';
    case PLUGIN = 'PLUGIN';
    case PACKAGE = 'PACKAGE';

    /**
     * 종류 라벨 반환
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
     * 외부 확장인지 여부
     */
    public function isExternal(): bool
    {
        return in_array($this, [self::PLUGIN, self::PACKAGE], true);
    }

    /**
     * 모든 종류 목록 반환 (라벨 포함)
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
