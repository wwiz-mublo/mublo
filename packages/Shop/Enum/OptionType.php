<?php

namespace Mublo\Packages\Shop\Enum;

enum OptionType: string
{
    case BASIC = 'BASIC';
    case EXTRA = 'EXTRA';

    public function label(): string
    {
        return match ($this) {
            self::BASIC => '기본 옵션',
            self::EXTRA => '추가 옵션',
        };
    }

    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }
        return $options;
    }
}
