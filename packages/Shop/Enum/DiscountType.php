<?php

namespace Mublo\Packages\Shop\Enum;

enum DiscountType: string
{
    case NONE = 'NONE';
    case DEFAULT = 'DEFAULT';
    case BASIC = 'BASIC';
    case LEVEL = 'LEVEL';
    case PERCENTAGE = 'PERCENTAGE';
    case FIXED = 'FIXED';

    public function label(): string
    {
        return match ($this) {
            self::NONE => '할인 없음',
            self::DEFAULT => '쇼핑몰 기본설정 적용',
            self::BASIC => '기본 할인',
            self::LEVEL => '등급별 할인',
            self::PERCENTAGE => '정률 할인',
            self::FIXED => '정액 할인',
        };
    }

    public function isApplicable(): bool
    {
        return $this !== self::NONE;
    }

    /**
     * 상품 등록 폼용 옵션 (DEFAULT 포함)
     */
    public static function productOptions(): array
    {
        return [
            self::DEFAULT->value => self::DEFAULT->label(),
            self::NONE->value => self::NONE->label(),
            self::LEVEL->value => self::LEVEL->label(),
            self::PERCENTAGE->value => self::PERCENTAGE->label(),
            self::FIXED->value => self::FIXED->label(),
        ];
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
