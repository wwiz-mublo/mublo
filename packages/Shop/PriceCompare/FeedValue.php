<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\PriceCompare;

/**
 * 채널이 공통으로 쓰는 값 변환
 *
 * 채널 클래스는 "어떤 컬럼에 무엇을 넣는가"의 표로 읽혀야 한다. 날짜 포맷이나
 * 통화 표기 같은 잔손질이 각 채널에 흩어지면 표가 코드에 묻히고, 한쪽만 고치는
 * 실수가 난다.
 */
final class FeedValue
{
    /** YYYYMMDD. 값을 믿을 수 없으면 빈 문자열. */
    public static function dateYmd(string $datetime): string
    {
        return self::date($datetime, 'Ymd');
    }

    /** RFC3339 계열(YYYY-MM-DD). 값을 믿을 수 없으면 빈 문자열. */
    public static function dateIso(string $datetime): string
    {
        return self::date($datetime, 'Y-m-d');
    }

    /** "19900 KRW" 처럼 통화를 붙인 금액 */
    public static function money(int $amount, string $currency = 'KRW'): string
    {
        return $amount . ' ' . $currency;
    }

    private static function date(string $datetime, string $format): string
    {
        if (trim($datetime) === '') {
            return '';
        }

        try {
            $date = new \DateTimeImmutable($datetime);
        } catch (\Throwable) {
            return '';
        }

        // MySQL 제로 날짜('0000-00-00 00:00:00')는 예외를 던지지 않고 기원전으로
        // 파싱된다. 그대로 내보내면 '-00011130' 같은 값이 피드에 실린다.
        if ((int) $date->format('Y') < 1970) {
            return '';
        }

        return $date->format($format);
    }
}
