<?php

namespace Mublo\Packages\Shop\Enum;

enum RewardType: string
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
            self::NONE => '적립 없음',
            self::DEFAULT => '쇼핑몰 기본설정 적용',
            self::BASIC => '기본 적립',
            self::LEVEL => '등급별 적립',
            self::PERCENTAGE => '정률 적립',
            self::FIXED => '정액 적립',
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
