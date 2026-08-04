<?php
declare(strict_types=1);

namespace Mublo\Contract\Tracking;

/**
 * 전환 소스 타입 상수 — 오타·표기 불일치 방지.
 */
class ConversionSourceTypes
{
    const RENTAL_ORDER        = 'rental_order';
    const RENTAL_CONSULTATION = 'rental_consultation';
    const MEMBER_SIGNUP       = 'member_signup';
    // 향후 확장: AUTOFORM = 'autoform';

    /**
     * 관리자 화면 라벨 (한글 표시용)
     */
    public static function label(string $type): string
    {
        return match ($type) {
            self::RENTAL_ORDER        => '렌탈 주문',
            self::RENTAL_CONSULTATION => '상담 신청',
            self::MEMBER_SIGNUP       => '회원가입',
            default                   => $type,
        };
    }
}
