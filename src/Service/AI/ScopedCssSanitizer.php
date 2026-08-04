<?php
declare(strict_types=1);
namespace Mublo\Service\AI;

/**
 * AI 생성 CSS 스코프·정화기
 *
 * 평면 규칙(`selector { decl }`)과 화이트리스트 @media 블록만 허용한다.
 * (설계: storage/docs/Mublo_AI_Editing_Quality_Review.md §3.5)
 *
 * - 모든 셀렉터에 `.{scope}` 접두 (스코프 격리)
 * - 속성은 HtmlBlockAiPolicy::CSS_PROPERTIES 허용 목록만
 * - @media: 조건 화이트리스트(min/max-width 등, screen/all만) 전체 일치 시
 *   본문을 동일 규칙 검사기로 재귀 정화. 중첩 @는 해당 블록 폐기(깊이 1)
 * - 그 외 @규칙(@import/@font-face/@keyframes 등)이 남아 있으면 CSS 전체 폐기
 *   (평면 파서가 오파싱으로 우회당하지 않도록 — 기존 보수성 유지)
 */
class ScopedCssSanitizer
{
    private const MAX_MEDIA_BLOCKS = 12;

    /**
     * @media 조건 화이트리스트 — 피처: 값 패턴. 전체 일치만 통과.
     */
    private const MEDIA_FEATURES = [
        'min-width' => '/^\d+(?:\.\d+)?(?:px|em|rem)$/',
        'max-width' => '/^\d+(?:\.\d+)?(?:px|em|rem)$/',
        'min-height' => '/^\d+(?:\.\d+)?(?:px|em|rem)$/',
        'max-height' => '/^\d+(?:\.\d+)?(?:px|em|rem)$/',
        'orientation' => '/^(?:portrait|landscape)$/',
        'prefers-color-scheme' => '/^(?:dark|light)$/',
        'prefers-reduced-motion' => '/^(?:reduce|no-preference)$/',
        'hover' => '/^(?:none|hover)$/',
        'any-hover' => '/^(?:none|hover)$/',
        'pointer' => '/^(?:none|coarse|fine)$/',
        'any-pointer' => '/^(?:none|coarse|fine)$/',
    ];

    public function sanitize(string $css, string $scope): string
    {
        return $this->sanitizeWithReport($css, $scope)['css'];
    }

    /** @return array{css:string,warnings:string[]} */
    public function sanitizeWithReport(string $css, string $scope): array
    {
        $css = trim($css);
        if ($css === '') return ['css' => '', 'warnings' => []];
        $warnings = [];
        if (strlen($css) > 30000) {
            return ['css' => '', 'warnings' => ['생성된 CSS가 크기 제한을 초과해 제외되었습니다.']];
        }

        if (substr_count($css, '{') !== substr_count($css, '}')) {
            return ['css' => '', 'warnings' => ['지원하지 않는 CSS 구조는 제외하고 HTML만 적용했습니다.']];
        }

        // 1단계: 화이트리스트 @media 블록 추출 (본문은 재귀 정화)
        $mediaRules = [];
        $css = $this->extractMediaBlocks($css, $scope, $mediaRules, $warnings);

        // 2단계: @media 외 @규칙이 남아 있으면 전체 폐기 — 평면 파서 우회 방지
        if (str_contains($css, '@')) {
            return ['css' => '', 'warnings' => ['@import·@font-face 등 지원하지 않는 @규칙이 있어 CSS를 제외하고 HTML만 적용했습니다.']];
        }

        // 3단계: 평면 규칙 정화
        $stats = ['selectors' => 0, 'properties' => []];
        $rules = $this->sanitizeRules($css, $scope, $stats);

        if (!preg_match('/[^{}]+\{[^{}]*\}/s', $css) && !$mediaRules && trim($css) !== '') {
            return ['css' => '', 'warnings' => ['해석할 수 없는 CSS는 제외하고 HTML만 반영했습니다.']];
        }
        $unparsed = trim(preg_replace('/[^{}]+\{[^{}]*\}/s', '', $css) ?? '');
        if ($unparsed !== '') $warnings[] = 'CSS 규칙 밖의 해석할 수 없는 내용은 제외했습니다.';

        if ($stats['selectors'] > 0) $warnings[] = "허용되지 않는 CSS 선택자 {$stats['selectors']}개를 제외했습니다.";
        if ($stats['properties']) {
            $names = implode(', ', array_slice(array_keys($stats['properties']), 0, 5));
            $warnings[] = '허용되지 않는 CSS 속성을 제외했습니다: ' . $names;
        }

        $all = array_merge($rules, $mediaRules);
        if (!$all) $warnings[] = '안전하게 적용할 CSS가 없어 HTML만 반영했습니다.';

        return [
            'css' => $all ? "/* mublo-generated */\n" . implode("\n", $all) : '',
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * 평면 규칙 문자열을 정화해 스코프된 규칙 배열로 반환
     *
     * @param array{selectors: int, properties: array<string, true>} $stats 드롭 통계 (누적)
     * @return string[]
     */
    private function sanitizeRules(string $flatCss, string $scope, array &$stats): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $flatCss, $matches, PREG_SET_ORDER);
        if (!$matches) return [];

        $rules = [];
        foreach ($matches as $match) {
            $selectors = [];
            foreach (array_map('trim', explode(',', trim($match[1]))) as $selector) {
                if ($this->isSafeSelector($selector)) $selectors[] = '.' . $scope . ' ' . $selector;
                else $stats['selectors']++;
            }
            if (!$selectors) continue;

            $declarations = [];
            foreach (explode(';', $match[2]) as $declaration) {
                $declaration = trim($declaration);
                if ($declaration === '' || !str_contains($declaration, ':')) continue;
                [$property, $value] = array_map('trim', explode(':', $declaration, 2));
                $property = strtolower($property);
                if (!in_array($property, HtmlBlockAiPolicy::CSS_PROPERTIES, true) || !$this->isSafeValue($value)) {
                    $stats['properties'][$property ?: 'unknown'] = true;
                    continue;
                }
                $declarations[] = $property . ': ' . $value;
            }
            if ($declarations) $rules[] = implode(', ', $selectors) . " { " . implode('; ', $declarations) . '; }';
        }

        return $rules;
    }

    /**
     * 최상위 @media 블록을 균형 매칭으로 추출·정화하고, 남은 CSS를 반환
     *
     * @param string[] $mediaRules 정화된 @media 블록 출력 (누적)
     * @param string[] $warnings   경고 (누적)
     */
    private function extractMediaBlocks(string $css, string $scope, array &$mediaRules, array &$warnings): string
    {
        $out = '';
        $offset = 0;
        $blocks = 0;

        while (($pos = stripos($css, '@media', $offset)) !== false) {
            $out .= substr($css, $offset, $pos - $offset);

            $braceOpen = strpos($css, '{', $pos);
            if ($braceOpen === false) {
                // 여는 중괄호 없는 @media — 나머지를 그대로 남겨 2단계 전체 폐기로
                $out .= substr($css, $pos);
                return $out;
            }

            // 균형 매칭으로 닫는 중괄호 탐색
            $depth = 0;
            $braceClose = null;
            for ($i = $braceOpen, $len = strlen($css); $i < $len; $i++) {
                if ($css[$i] === '{') $depth++;
                elseif ($css[$i] === '}' && --$depth === 0) { $braceClose = $i; break; }
            }
            if ($braceClose === null) {
                $out .= substr($css, $pos);
                return $out;
            }

            $condition = trim(substr($css, $pos + 6, $braceOpen - $pos - 6));
            $body = substr($css, $braceOpen + 1, $braceClose - $braceOpen - 1);
            $offset = $braceClose + 1;

            if (++$blocks > self::MAX_MEDIA_BLOCKS) {
                $warnings[] = '@media 블록이 너무 많아 초과분을 제외했습니다.';
                continue;
            }
            if (str_contains($body, '@')) {
                $warnings[] = '중첩 @규칙이 있는 @media 블록을 제외했습니다.';
                continue;
            }
            if (!$this->isSafeMediaQuery($condition)) {
                $warnings[] = '지원하지 않는 @media 조건을 제외했습니다: ' . mb_substr($condition, 0, 60);
                continue;
            }

            $stats = ['selectors' => 0, 'properties' => []];
            $rules = $this->sanitizeRules($body, $scope, $stats);
            if ($stats['selectors'] > 0) $warnings[] = "허용되지 않는 CSS 선택자 {$stats['selectors']}개를 제외했습니다.";
            if ($stats['properties']) {
                $names = implode(', ', array_slice(array_keys($stats['properties']), 0, 5));
                $warnings[] = '허용되지 않는 CSS 속성을 제외했습니다: ' . $names;
            }
            if ($rules) {
                $mediaRules[] = '@media ' . $condition . " {\n" . implode("\n", $rules) . "\n}";
            }
        }

        $out .= substr($css, $offset);
        return $out;
    }

    /**
     * @media 조건 화이트리스트 검증 — 전체 일치만 통과
     *
     * 허용: (피처: 값) 괄호 그룹들 + and/쉼표 결합 + only/screen/all 타입.
     * print 불허(화면 은닉 벡터), 문자 집합 자체를 제한해 페이로드 진입 차단.
     */
    private function isSafeMediaQuery(string $query): bool
    {
        $query = strtolower(trim($query));
        if ($query === '' || strlen($query) > 200) return false;
        if (!preg_match('/^[a-z0-9 ():,.\-]+$/', $query)) return false;

        // 괄호 그룹(피처)을 검증하며 소거 — 마커 문자 '_'는 허용 문자 집합 밖이라
        // 원본 조건 문자열과 절대 충돌하지 않는다
        $rest = preg_replace_callback(
            '/\(([^()]*)\)/',
            function (array $m): string {
                $feature = trim($m[1]);
                if (!str_contains($feature, ':')) return ' __invalid__ ';
                [$name, $value] = array_map('trim', explode(':', $feature, 2));
                $pattern = self::MEDIA_FEATURES[$name] ?? null;
                return ($pattern !== null && preg_match($pattern, $value) === 1) ? ' __ok__ ' : ' __invalid__ ';
            },
            $query
        );
        if ($rest === null || str_contains($rest, '__invalid__')) return false;
        if (!str_contains($rest, '__ok__')) return false; // 피처 없는 타입 단독은 불허

        // 남은 텍스트는 결합자·허용 타입만
        $tokens = preg_split('/[\s,]+/', trim($rest)) ?: [];
        foreach ($tokens as $token) {
            if ($token === '' || in_array($token, ['__ok__', 'and', 'only', 'screen', 'all'], true)) continue;
            return false;
        }

        return true;
    }

    private function isSafeSelector(string $selector): bool
    {
        return $selector !== '' && str_starts_with($selector, '.') && !str_contains($selector, '#')
            && !str_contains($selector, '::')
            && !preg_match('/(^|[\s>+~])(html|body|:root|\*)([\s>+~.#:\[]|$)/i', $selector);
    }

    private function isSafeValue(string $value): bool
    {
        // var(--...) 금지: AI 생성 CSS는 사이트 디자인 토큰을 쓰지 않는다 —
        // 컬러는 요청/AI 판단에 따른 리터럴 값이어야 한다는 제품 정책 (2026-07-14).
        // 이 새니타이저는 AI 경로 전용이라 운영자 수동 CSS에는 영향 없다.
        return $value !== '' && !preg_match(
            '/url\s*\(|expression\s*\(|javascript\s*:|behavior\s*:|-moz-binding|<\/?style|!important|var\s*\(|[{}]/i',
            $value
        );
    }
}
