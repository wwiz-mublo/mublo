<?php
declare(strict_types=1);

namespace Mublo\Helper\String;

/**
 * StringHelper
 *
 * 문자열 관련 순수 유틸리티 함수 모음
 * - 외부 의존성 없음 (DB, Session 등)
 * - 모든 메서드는 static
 *
 * 사용:
 * StringHelper::random(8);           // 랜덤 8자 문자열
 * StringHelper::slug('한글 제목');    // hangul-jemog
 * StringHelper::truncate($text, 50); // 50자 이후 ...
 */
class StringHelper
{
    /**
     * 기본 문자셋 (영문 대소문자 + 숫자, 혼동 문자 제외)
     * 제외: 0, O, o, 1, l, I (혼동 방지)
     */
    private const DEFAULT_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';

    /**
     * 랜덤 문자열 생성
     *
     * @param int $length 문자열 길이 (기본: 8)
     * @param string|null $chars 사용할 문자셋 (null이면 기본 문자셋)
     * @return string
     */
    public static function random(int $length = 8, ?string $chars = null): string
    {
        $chars = $chars ?? self::DEFAULT_CHARS;
        $charsLength = strlen($chars);
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $charsLength - 1)];
        }

        return $result;
    }

    /**
     * 숫자만 포함된 랜덤 문자열
     *
     * @param int $length 문자열 길이
     * @return string
     */
    public static function randomNumeric(int $length = 6): string
    {
        return self::random($length, '0123456789');
    }

    /**
     * 영문 대문자만 포함된 랜덤 문자열
     *
     * @param int $length 문자열 길이
     * @return string
     */
    public static function randomUppercase(int $length = 8): string
    {
        return self::random($length, 'ABCDEFGHJKLMNPQRSTUVWXYZ');
    }

    /**
     * URL 슬러그 생성
     *
     * @param string $text 원본 텍스트
     * @param string $separator 구분자 (기본: -)
     * @return string
     */
    public static function slug(string $text, string $separator = '-'): string
    {
        // 소문자 변환
        $text = mb_strtolower($text, 'UTF-8');

        // 영문, 숫자, 한글, 공백만 남기기
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);

        // 공백을 separator로 변환
        $text = preg_replace('/\s+/', $separator, trim($text));

        // 연속 separator 제거
        $text = preg_replace('/' . preg_quote($separator, '/') . '+/', $separator, $text);

        return $text;
    }

    /**
     * 문자열 자르기 (말줄임)
     *
     * @param string $text 원본 텍스트
     * @param int $length 최대 길이
     * @param string $suffix 말줄임 기호 (기본: ...)
     * @return string
     */
    public static function truncate(string $text, int $length, string $suffix = '...'): string
    {
        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length, 'UTF-8') . $suffix;
    }

    /**
     * 카멜케이스를 스네이크케이스로 변환
     *
     * @param string $text camelCase 문자열
     * @return string snake_case 문자열
     */
    public static function toSnakeCase(string $text): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $text));
    }

    /**
     * 스네이크케이스를 카멜케이스로 변환
     *
     * @param string $text snake_case 문자열
     * @param bool $capitalizeFirst 첫 글자 대문자 여부 (PascalCase)
     * @return string camelCase 또는 PascalCase 문자열
     */
    public static function toCamelCase(string $text, bool $capitalizeFirst = false): string
    {
        $result = str_replace('_', '', ucwords($text, '_'));

        if (!$capitalizeFirst) {
            $result = lcfirst($result);
        }

        return $result;
    }

    /**
     * 문자열 마스킹 (개인정보 보호용)
     *
     * @param string $text 원본 문자열
     * @param int $showStart 앞에서 보여줄 글자 수
     * @param int $showEnd 뒤에서 보여줄 글자 수
     * @param string $mask 마스킹 문자 (기본: *)
     * @return string
     */
    public static function mask(string $text, int $showStart = 2, int $showEnd = 2, string $mask = '*'): string
    {
        $length = mb_strlen($text, 'UTF-8');

        if ($length <= $showStart + $showEnd) {
            return $text;
        }

        $start = mb_substr($text, 0, $showStart, 'UTF-8');
        $end = $showEnd > 0 ? mb_substr($text, -$showEnd, null, 'UTF-8') : '';
        $middle = str_repeat($mask, $length - $showStart - $showEnd);

        return $start . $middle . $end;
    }

    /**
     * 이메일 마스킹
     *
     * @param string $email 이메일 주소
     * @return string 마스킹된 이메일 (ex: te***@example.com)
     */
    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email);

        if (count($parts) !== 2) {
            return $email;
        }

        $local = $parts[0];
        $domain = $parts[1];

        $maskedLocal = self::mask($local, 2, 0, '*');

        return $maskedLocal . '@' . $domain;
    }

    /**
     * 전화번호 마스킹
     *
     * @param string $phone 전화번호
     * @return string 마스킹된 전화번호 (ex: 010-****-5678)
     */
    public static function maskPhone(string $phone): string
    {
        // 숫자만 추출
        $numbers = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($numbers) === 11) {
            // 010-1234-5678 형식
            return substr($numbers, 0, 3) . '-****-' . substr($numbers, -4);
        } elseif (strlen($numbers) === 10) {
            // 02-1234-5678 형식
            return substr($numbers, 0, 2) . '-****-' . substr($numbers, -4);
        }

        return $phone;
    }

    /**
     * 바이트 크기를 사람이 읽기 쉬운 형식으로 변환
     *
     * @param int $bytes 바이트 크기
     * @param int $precision 소수점 자릿수
     * @return string
     */
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * 문자열에서 숫자만 추출
     *
     * @param string $value 입력 문자열
     * @return int|float|null 추출된 숫자 (빈 문자열이면 null)
     *
     * @example
     * StringHelper::pickNumber('123');      // 123 (int)
     * StringHelper::pickNumber('12.34');    // 12.34 (float)
     * StringHelper::pickNumber('$1,234');   // 1234 (int)
     * StringHelper::pickNumber('-99.5%');   // -99.5 (float)
     * StringHelper::pickNumber('');         // null
     */
    public static function pickNumber(string $value): int|float|null
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // 소수점 포함 여부 확인
        if (strpos($value, '.') !== false) {
            $cleaned = preg_replace('/[^0-9.\-]/', '', $value);
            return $cleaned !== '' ? (float) $cleaned : null;
        }

        $cleaned = preg_replace('/[^0-9\-]/', '', $value);
        return $cleaned !== '' ? (int) $cleaned : null;
    }

    /**
     * 입력값 정제 (XSS 방지)
     *
     * HTML 태그 제거 및 특수문자 이스케이프
     *
     * @param string $value 입력 문자열
     * @return string 정제된 문자열
     *
     * @example
     * StringHelper::sanitize('<script>alert(1)</script>');  // 'alert(1)'
     * StringHelper::sanitize('Hello & World');              // 'Hello & World'
     */
    public static function sanitize(string $value): string
    {
        return strip_tags($value);
    }

}
