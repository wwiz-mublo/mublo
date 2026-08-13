<?php
declare(strict_types=1);

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

    /**
     * 쇼핑몰 설정 폼용 옵션
     *
     * DEFAULT("쇼핑몰 기본설정 적용")와 BASIC 은 쇼핑몰 설정 **자신**을 가리키므로
     * 여기서 고를 수 없다 — 자기참조라 어떤 할인으로도 풀리지 않는다.
     * (기존에 BASIC 으로 저장된 설정은 계산기가 하위호환으로 계속 처리한다.)
     */
    public static function configOptions(): array
    {
        return [
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
