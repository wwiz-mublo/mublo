<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Enum;

enum OptionMode: string
{
    case NONE = 'NONE';
    case SINGLE = 'SINGLE';
    case COMBINATION = 'COMBINATION';

    public function label(): string
    {
        return match ($this) {
            self::NONE => '옵션 없음',
            self::SINGLE => '단독형',
            self::COMBINATION => '조합형',
        };
    }

    public function hasOptions(): bool
    {
        return $this !== self::NONE;
    }

    public static function options(): array
    {
        // 셀렉트 표시 순서: 옵션 없음 → 조합형 → 단독형
        $order = [self::NONE, self::COMBINATION, self::SINGLE];

        $options = [];
        foreach ($order as $case) {
            $options[$case->value] = $case->label();
        }
        return $options;
    }
}
