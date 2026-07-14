<?php

namespace Mublo\Service\Block;

/**
 * 블록 CSS 스코퍼 — 블록 css 채널을 칸 컨테이너(#bc-{id}) 하위로 가둔다.
 *
 * 편집 자율성 정책(편집 신뢰 전원에게 css/js 허용)에서 "다른 블록/전역을
 * 실수로 덮는" 품질 문제를 막는 장치. 에디터 미리보기의 scopeCssForEditor
 * (block-html-editor/index.js)와 동일한 의미론을 서버 렌더에 적용해
 * 편집 중 보이는 결과와 프론트 결과를 일치시킨다(WYSIWYG).
 *
 * 규칙:
 * - 일반 규칙: 셀렉터 목록의 각 셀렉터 앞에 프리픽스 (`.a, .b` → `#bc-1 .a, #bc-1 .b`)
 * - @media/@supports/@container/@layer/@scope: 내부 재귀 스코핑
 * - @keyframes/@font-face/@page/@property 등: 원문 유지 (이름은 원래 전역)
 * - 블록 없는 at-문(@import/@charset/@namespace): 원문 유지
 * - 문자열("..." / '...')과 주석(/ * * /) 내부의 중괄호는 구조로 취급하지 않음
 * - 괄호 짝이 맞지 않는 등 파싱 불가 시: 안전 폴백으로 전체를 프리픽스 블록으로
 *   감싼 네이티브 CSS 네스팅 형태(`#bc-1 { ...원문... }`) 반환
 */
final class BlockCssScoper
{
    /** 내부 재귀 스코핑 대상 그룹 at-rule */
    private const RECURSIVE_AT_RULES = ['media', 'supports', 'container', 'layer', 'scope'];

    public static function scope(string $css, string $prefix): string
    {
        $css = trim($css);
        if ($css === '') {
            return '';
        }

        try {
            $scoped = self::scopeRules($css, $prefix);
            if ($scoped !== null) {
                return $scoped;
            }
        } catch (\Throwable) {
            // 파싱 실패 → 아래 폴백
        }

        // 폴백: 네이티브 CSS 네스팅 (모던 브라우저 지원) — 스코핑 의미는 유지
        return $prefix . " {\n" . $css . "\n}";
    }

    /** @return string|null 파싱 불가 시 null */
    private static function scopeRules(string $css, string $prefix): ?string
    {
        $out = [];
        $len = strlen($css);
        $pos = 0;

        while ($pos < $len) {
            // 공백 스킵
            while ($pos < $len && ctype_space($css[$pos])) {
                $pos++;
            }
            if ($pos >= $len) {
                break;
            }

            // 주석 통과 (원문 보존)
            if (substr($css, $pos, 2) === '/*') {
                $end = strpos($css, '*/', $pos + 2);
                if ($end === false) {
                    break; // 닫히지 않은 주석 — 남은 부분 버림
                }
                $out[] = substr($css, $pos, $end + 2 - $pos);
                $pos = $end + 2;
                continue;
            }

            // 헤더 읽기: 다음 구조 문자('{' 또는 ';')까지 — 문자열 인지
            $headerStart = $pos;
            $boundary = self::findStructuralChar($css, $pos);
            if ($boundary === null) {
                // 구조 문자 없음 — 잔여물은 버리지 않고 원문 유지
                $out[] = substr($css, $headerStart);
                break;
            }

            [$char, $pos] = $boundary;
            $header = trim(substr($css, $headerStart, $pos - $headerStart));

            if ($char === ';') {
                // 블록 없는 at-문 (@import 등) — 원문 유지
                $pos++;
                if ($header !== '') {
                    $out[] = $header . ';';
                }
                continue;
            }

            // '{' — 매칭되는 '}' 찾기
            $blockStart = $pos + 1;
            $blockEnd = self::findMatchingBrace($css, $pos);
            if ($blockEnd === null) {
                return null; // 짝 불일치 — 폴백
            }
            $body = substr($css, $blockStart, $blockEnd - $blockStart);
            $pos = $blockEnd + 1;

            if ($header === '') {
                continue;
            }

            if ($header[0] === '@') {
                $name = strtolower(preg_split('/[\s(]/', substr($header, 1), 2)[0] ?? '');
                if (in_array($name, self::RECURSIVE_AT_RULES, true)) {
                    $inner = self::scopeRules($body, $prefix);
                    if ($inner === null) {
                        return null;
                    }
                    $out[] = $header . " {\n" . $inner . "\n}";
                } else {
                    // @keyframes/@font-face 등 — 원문 유지
                    $out[] = $header . ' {' . $body . '}';
                }
                continue;
            }

            // 일반 스타일 규칙: 셀렉터 목록 프리픽스
            $selectors = array_map(
                static fn(string $sel) => $prefix . ' ' . trim($sel),
                self::splitSelectorList($header)
            );
            $out[] = implode(', ', $selectors) . ' {' . $body . '}';
        }

        return implode("\n", $out);
    }

    /**
     * 문자열 리터럴을 건너뛰며 다음 '{' 또는 ';' 탐색
     *
     * @return array{0: string, 1: int}|null [문자, 위치]
     */
    private static function findStructuralChar(string $css, int $pos): ?array
    {
        $len = strlen($css);
        while ($pos < $len) {
            $ch = $css[$pos];
            if ($ch === '"' || $ch === "'") {
                $pos = self::skipString($css, $pos);
                if ($pos === null) {
                    return null;
                }
                continue;
            }
            if ($ch === '{' || $ch === ';') {
                return [$ch, $pos];
            }
            $pos++;
        }
        return null;
    }

    /** '{' 위치에서 매칭되는 '}' 위치 반환 (문자열·주석 인지) */
    private static function findMatchingBrace(string $css, int $open): ?int
    {
        $len = strlen($css);
        $depth = 0;
        $pos = $open;

        while ($pos < $len) {
            $ch = $css[$pos];
            if ($ch === '"' || $ch === "'") {
                $next = self::skipString($css, $pos);
                if ($next === null) {
                    return null;
                }
                $pos = $next;
                continue;
            }
            if (substr($css, $pos, 2) === '/*') {
                $end = strpos($css, '*/', $pos + 2);
                if ($end === false) {
                    return null;
                }
                $pos = $end + 2;
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return $pos;
                }
            }
            $pos++;
        }

        return null;
    }

    /** 여는 따옴표 위치에서 닫는 따옴표 다음 위치 반환 (역슬래시 이스케이프 인지) */
    private static function skipString(string $css, int $pos): ?int
    {
        $quote = $css[$pos];
        $len = strlen($css);
        $pos++;
        while ($pos < $len) {
            if ($css[$pos] === '\\') {
                $pos += 2;
                continue;
            }
            if ($css[$pos] === $quote) {
                return $pos + 1;
            }
            $pos++;
        }
        return null;
    }

    /**
     * 최상위 콤마 기준 셀렉터 분리 — :is(a, b) 같은 괄호 내부 콤마는 분리하지 않음
     *
     * @return string[]
     */
    private static function splitSelectorList(string $selectorList): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        $len = strlen($selectorList);

        for ($i = 0; $i < $len; $i++) {
            $ch = $selectorList[$i];
            if ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts === [] ? [$selectorList] : $parts;
    }
}
