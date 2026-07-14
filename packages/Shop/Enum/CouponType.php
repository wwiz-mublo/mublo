<?php

namespace Mublo\Packages\Shop\Enum;

enum CouponType: string
{
    case ADMIN = 'ADMIN';
    case AUTO = 'AUTO';
    case DOWNLOAD = 'DOWNLOAD';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => '관리자 발행',
            self::AUTO => '자동 발행',
            self::DOWNLOAD => '다운로드',
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
