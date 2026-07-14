<?php

namespace Mublo\Core\Report\Formatter;

/**
 * 공용 리포트 포매터 팩토리
 *
 * ColumnDefinition의 $formatter 파라미터에 전달할 callable을 생성.
 * 각 메서드는 callable(mixed $value, array $row): string 을 반환.
 *
 * 사용 예:
 *   new ColumnDefinition('actual_price', '실구매가', 'money', 'right', ReportFormatters::money('원'))
 *   new ColumnDefinition('created_at', '주문일', 'date', 'center', ReportFormatters::date('Y-m-d'))
 *   new ColumnDefinition('telecom', '통신사', 'string', 'center', ReportFormatters::map($telecomMap))
 */
final class ReportFormatters
{
    /**
     * 날짜 포맷
     *
     * DB 원본 문자열 → 지정 형식으로 변환.
     * 빈 값이나 파싱 불가 값은 빈 문자열 반환.
     */
    public static function date(string $format = 'Y-m-d'): callable
    {
        return function ($value) use ($format): string {
            if ($value === null || $value === '') {
                return '';
            }
            $ts = strtotime((string) $value);
            return $ts !== false ? date($format, $ts) : (string) $value;
        };
    }

    /**
     * 금액 포맷
     *
     * 숫자 → 천단위 콤마 + 선택적 접미사.
     * 예: 15000 → '15,000원'
     */
    public static function money(string $suffix = ''): callable
    {
        return function ($value) use ($suffix): string {
            if ($value === null || $value === '') {
                return '';
            }
            return number_format((int) $value) . $suffix;
        };
    }

    /**
     * 숫자 포맷
     *
     * 천단위 콤마 + 소수점 자릿수 지정.
     */
    public static function number(int $decimals = 0): callable
    {
        return function ($value) use ($decimals): string {
            if ($value === null || $value === '') {
                return '';
            }
            return number_format((float) $value, $decimals);
        };
    }

    /**
     * 이름 마스킹
     *
     * 2자: 김* / 3자: 김*수 / 4자+: 첫1자 + * + 마지막1자.
     * 영문·공백 포함 이름도 동일 규칙.
     */
    public static function maskName(): callable
    {
        return function ($value): string {
            if ($value === null || $value === '') {
                return '';
            }
            $name = (string) $value;
            $len = mb_strlen($name);
            if ($len <= 1) {
                return $name;
            }
            if ($len === 2) {
                return mb_substr($name, 0, 1) . '*';
            }
            return mb_substr($name, 0, 1) . str_repeat('*', $len - 2) . mb_substr($name, -1);
        };
    }

    /**
     * Boolean → 라벨 변환
     *
     * truthy → $true 문자열, falsy → $false 문자열.
     */
    public static function boolean(string $true = 'Y', string $false = 'N'): callable
    {
        return function ($value) use ($true, $false): string {
            return $value ? $true : $false;
        };
    }

    /**
     * 매핑 (코드 → 라벨)
     *
     * 배열 맵에서 값을 찾아 라벨 반환. 미매칭 시 $default.
     * Enum::options() 결과를 변환하여 사용 가능.
     */
    public static function map(array $map, string $default = ''): callable
    {
        return function ($value) use ($map, $default): string {
            return $map[$value] ?? $default;
        };
    }
}
