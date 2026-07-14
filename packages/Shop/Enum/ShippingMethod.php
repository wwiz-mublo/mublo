<?php

namespace Mublo\Packages\Shop\Enum;

enum ShippingMethod: string
{
    case FREE = 'FREE';
    case COND = 'COND';
    case PAID = 'PAID';
    case QUANTITY = 'QUANTITY';
    case AMOUNT = 'AMOUNT';

    public function label(): string
    {
        return match ($this) {
            self::FREE => '무료 배송',
            self::COND => '조건부 무료',
            self::PAID => '유료 배송',
            self::QUANTITY => '수량별 배송',
            self::AMOUNT => '금액별 배송',
        };
    }

    public function isFree(): bool
    {
        return $this === self::FREE;
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
