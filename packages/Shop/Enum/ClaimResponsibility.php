<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Enum;

enum ClaimResponsibility: string
{
    case CUSTOMER = 'CUSTOMER';
    case SELLER = 'SELLER';
    case MANUAL = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER => '고객 귀책',
            self::SELLER => '판매자 귀책',
            self::MANUAL => '관리자 판단',
        };
    }
}
