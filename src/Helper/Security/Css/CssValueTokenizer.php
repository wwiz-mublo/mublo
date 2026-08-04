<?php
declare(strict_types=1);

namespace Mublo\Helper\Security\Css;

/**
 * CssValueTokenizer
 *
 * CSS 값을 "괄호 밖" 구분자 기준으로만 쪼갠다.
 *
 * rgba(37,99,235,0.65) 처럼 함수 인자에 콤마·공백이 들어가는 값이 흔해서,
 * 단순 explode/preg_split 로는 토큰이 깨진다(HTMLPurifier 의 CSS_Multiple 이
 * 정확히 이 이유로 다중 box-shadow 를 망가뜨린다).
 */
final class CssValueTokenizer
{
    /**
     * 괄호 깊이 0 에서 콤마로 분해. 괄호가 안 맞으면 null.
     *
     * @return string[]|null
     */
    public static function splitLayers(string $value): ?array
    {
        return self::split($value, ',');
    }

    /**
     * 괄호 깊이 0 에서 공백으로 분해. 괄호가 안 맞으면 null.
     *
     * @return string[]|null
     */
    public static function splitTokens(string $value): ?array
    {
        return self::split($value, ' ');
    }

    /**
     * @return string[]|null
     */
    private static function split(string $value, string $delimiter): ?array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth < 0) {
                    return null;
                }
            }

            $isDelimiter = $depth === 0 && (
                $delimiter === ' '
                    ? preg_match('/\s/', $char) === 1
                    : $char === $delimiter
            );

            if ($isDelimiter) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if ($depth !== 0) {
            return null;
        }

        $parts[] = $buffer;

        $parts = array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));

        return $parts === [] ? null : $parts;
    }
}
