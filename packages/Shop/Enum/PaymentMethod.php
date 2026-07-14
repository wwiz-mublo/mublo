<?php

namespace Mublo\Packages\Shop\Enum;

enum PaymentMethod: string
{
    case CARD = 'CARD';
    case PHONE = 'PHONE';
    case VBANK = 'VBANK';
    case BANK = 'BANK';
    // 시스템 전용: 쿠폰/포인트로 결제금액이 0원이 되어 PG 승인 없이 확정된 주문.
    // 사용자가 선택하는 결제수단이 아니므로 options()(선택 목록)에서는 제외한다.
    case FREE = 'FREE';

    public function label(): string
    {
        return match ($this) {
            self::CARD => '신용카드',
            self::PHONE => '휴대폰 결제',
            self::VBANK => '가상계좌',
            self::BANK => '무통장입금',
            self::FREE => '0원 결제',
        };
    }

    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            // FREE 는 시스템 내부 결제수단 — 사용자 선택 목록에 노출하지 않는다.
            if ($case === self::FREE) {
                continue;
            }
            $options[$case->value] = $case->label();
        }
        return $options;
    }
}
