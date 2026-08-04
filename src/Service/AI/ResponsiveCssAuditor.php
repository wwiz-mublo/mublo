<?php
declare(strict_types=1);
namespace Mublo\Service\AI;

/**
 * AI 생성 HTML/CSS 반응형 정적 검사기
 * (설계: storage/docs/Mublo_AI_HTML_Frame_Generation_Improvement_Plan.md §8)
 *
 * 정화가 끝난 게시본 CSS를 대상으로 보수적인 휴리스틱을 적용한다.
 * - error: 명백한 레이아웃 파손 가능성 (고정 다열 grid, 모바일 폭 초과 고정 너비,
 *   고정 높이 + overflow:hidden 텍스트 잘림)
 * - warning: 디자인 선택 또는 개선 권고
 *
 * 정적 검사일 뿐이므로 게시를 막지 않는다 — 결과는 품질 상태(통과/경고/수정 필요)
 * 표시와 게시 전 확인 경고에만 쓰인다 (§9.3). 실제 렌더 검사(§9.2)는 편집 화면의
 * 브라우저 진단이 보완한다.
 */
class ResponsiveCssAuditor
{
    /** 360px 모바일에서 확실히 넘치는 고정 너비 */
    private const WIDTH_ERROR_PX = 420;
    /** 좁은 화면에서 문제가 될 수 있는 고정 너비 */
    private const WIDTH_WARNING_PX = 300;
    private const MIN_WIDTH_WARNING_PX = 360;
    private const SPACING_WARNING_PX = 64;
    private const TRANSITION_WARNING_MS = 400;
    private const MAX_FINDINGS = 20;

    private const CHECKS = [
        '고정 너비·min-width',
        '고정 높이와 텍스트 잘림',
        'px 고정 font-size',
        'viewport 기준 font-size',
        '줄바꿈 없는 flex 행',
        '모바일 전환 없는 고정 다열 grid',
        'overflow:hidden 텍스트 위험',
        '이미지 폭 제한',
        '과대 고정 padding·gap',
        'transition과 reduced-motion',
    ];

    /**
     * @param string $html 게시본 HTML (스코프 래퍼 포함 무방)
     * @param string $css  정화가 끝난 게시본 CSS
     * @param string $scope 메시지에서 걷어낼 스코프 클래스 (예: mublo-block-xxxx)
     * @return array{status:string,errors:list<array{code:string,message:string}>,warnings:list<array{code:string,message:string}>,checks:list<string>}
     */
    public function audit(string $html, string $css, string $scope = ''): array
    {
        $errors = [];
        $warnings = [];

        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
        [$flatRules, $mediaBlocks] = $this->parse($css);

        // @media 안에서 재선언된 선택자는 flex/grid 검사에서 면제한다 —
        // 작은 화면 전환이 이미 있다는 뜻이므로.
        $mediaSelectors = [];
        $hasReducedMotionMedia = false;
        foreach ($mediaBlocks as $block) {
            if (str_contains($block['condition'], 'prefers-reduced-motion')) $hasReducedMotionMedia = true;
            foreach ($block['rules'] as $rule) {
                foreach ($rule['selectors'] as $selector) {
                    $mediaSelectors[$this->displaySelector($selector, $scope)] = true;
                }
            }
        }

        $allRules = $flatRules;
        foreach ($mediaBlocks as $block) {
            foreach ($block['rules'] as $rule) $allRules[] = $rule + ['media' => $block['condition']];
        }

        $hasImageWidthLimit = false;
        $maxTransitionMs = 0;

        foreach ($allRules as $rule) {
            $selector = $this->displaySelector($rule['selectors'][0] ?? '', $scope);
            $decls = $rule['declarations'];
            $inMedia = isset($rule['media']);

            // 1. 고정 너비 / min-width
            $width = $this->pxValue($decls['width'] ?? '');
            $hasFluidCap = isset($decls['max-width']) && str_contains($decls['max-width'], '%');
            if ($width !== null && !$hasFluidCap) {
                if ($width >= self::WIDTH_ERROR_PX && !$inMedia) {
                    $errors[] = $this->finding('fixed-width', "{$selector}의 고정 너비 {$width}px가 360px 모바일 화면을 초과합니다. %·min()·max-width로 바꾸세요.");
                } elseif ($width >= self::WIDTH_WARNING_PX && !$inMedia) {
                    $warnings[] = $this->finding('fixed-width', "{$selector}의 고정 너비 {$width}px는 좁은 화면에서 넘칠 수 있습니다. max-width와 %를 권장합니다.");
                }
            }
            $minWidth = $this->pxValue($decls['min-width'] ?? '');
            if ($minWidth !== null && $minWidth >= self::MIN_WIDTH_WARNING_PX && !$inMedia) {
                $warnings[] = $this->finding('min-width', "{$selector}의 min-width {$minWidth}px는 모바일에서 가로 스크롤을 만들 수 있습니다.");
            }

            // 2. 고정 높이 (+ overflow:hidden이면 텍스트 잘림 위험)
            $height = $this->pxValue($decls['height'] ?? '');
            $overflowHidden = ($decls['overflow'] ?? '') === 'hidden' || ($decls['overflow-y'] ?? '') === 'hidden';
            if ($height !== null && $height > 0) {
                if ($overflowHidden) {
                    $errors[] = $this->finding('clipped-text', "{$selector}가 고정 높이 {$height}px에 overflow:hidden이라 내용이 잘릴 수 있습니다. min-height를 사용하세요.");
                } else {
                    $warnings[] = $this->finding('fixed-height', "{$selector}의 고정 높이 {$height}px는 내용이 늘어나면 겹치거나 잘립니다. min-height를 권장합니다.");
                }
            } elseif ($overflowHidden) {
                $warnings[] = $this->finding('overflow-hidden', "{$selector}의 overflow:hidden은 텍스트 영역이라면 내용 잘림 위험이 있습니다.");
            }

            // 3. px 고정 font-size
            $fontPx = $this->pxValue($decls['font-size'] ?? '');
            if ($fontPx !== null) {
                $warnings[] = $this->finding('px-font-size', "{$selector}의 font-size {$fontPx}px는 rem/em(제목은 clamp())을 권장합니다.");
            }
            $fontSize = $decls['font-size'] ?? '';
            if (preg_match('/(?:^|[^a-z])(\d+(?:\.\d+)?)v(?:w|h|min|max)\b/i', $fontSize)) {
                $warnings[] = $this->finding(
                    'viewport-font-size',
                    "{$selector}의 font-size가 viewport 단위를 사용해 사이드바·다열 레이아웃의 실제 블록 폭을 반영하지 못합니다. rem 경계의 clamp() 안에서 cqi를 사용하세요."
                );
            }

            // 4. 줄바꿈 없는 flex 행 (미디어 재선언 시 면제)
            $display = $decls['display'] ?? '';
            if (in_array($display, ['flex', 'inline-flex'], true) && !$inMedia) {
                $direction = $decls['flex-direction'] ?? 'row';
                $wrap = $decls['flex-wrap'] ?? '';
                if (!str_starts_with($direction, 'column') && !str_contains($wrap, 'wrap')
                    && !isset($mediaSelectors[$selector])) {
                    $warnings[] = $this->finding('flex-no-wrap', "{$selector}의 flex 행이 줄바꿈(flex-wrap: wrap)도 모바일 전환(@media)도 없습니다. 좁은 화면에서 눌리거나 넘칠 수 있습니다.");
                }
            }

            // 5. 모바일 전환 없는 고정 다열 grid
            $gridColumns = $decls['grid-template-columns'] ?? '';
            if ($gridColumns !== '' && !$inMedia) {
                $tracks = $this->columnTrackCount($gridColumns);
                if ($tracks >= 2 && !isset($mediaSelectors[$selector])) {
                    $errors[] = $this->finding('fixed-grid', "{$selector}의 {$tracks}열 grid가 작은 화면 전환 없이 고정돼 있습니다. repeat(auto-fit, minmax(...)) 또는 @media 열 전환을 사용하세요.");
                }
            }

            // 7. 이미지 폭 제한 존재 여부 수집
            if ($this->limitsImageWidth($rule['selectors'], $decls)) $hasImageWidthLimit = true;

            // 8. 과대 고정 padding·gap
            foreach ($decls as $property => $value) {
                if (!str_starts_with($property, 'padding') && !in_array($property, ['gap', 'row-gap', 'column-gap'], true)) continue;
                foreach ($this->pxNumbers($value) as $px) {
                    if ($px >= self::SPACING_WARNING_PX) {
                        $warnings[] = $this->finding('large-spacing', "{$selector}의 {$property} {$px}px는 모바일에서 과대합니다. clamp()나 @media 축소를 권장합니다.");
                        break;
                    }
                }
            }

            // 9. transition 최대 시간 수집
            foreach (['transition', 'transition-duration'] as $property) {
                if (isset($decls[$property])) {
                    $maxTransitionMs = max($maxTransitionMs, $this->maxDurationMs($decls[$property]));
                }
            }
        }

        // 7. 이미지 폭 제한 누락 (HTML에 img가 있을 때만)
        if (stripos($html, '<img') !== false && !$hasImageWidthLimit) {
            $warnings[] = $this->finding('img-width', '이미지에 max-width: 100%(또는 width: 100%)와 height: auto·aspect-ratio 처리가 없습니다. 좁은 화면에서 이미지가 넘치거나 왜곡될 수 있습니다.');
        }

        // 9. 과도한 transition에 reduced-motion 대응 없음
        if ($maxTransitionMs >= self::TRANSITION_WARNING_MS && !$hasReducedMotionMedia) {
            $warnings[] = $this->finding('reduced-motion', 'transition이 ' . $maxTransitionMs . 'ms인데 prefers-reduced-motion 대응 @media가 없습니다.');
        }

        $errors = array_slice($this->dedupe($errors), 0, self::MAX_FINDINGS);
        $warnings = array_slice($this->dedupe($warnings), 0, self::MAX_FINDINGS);

        return [
            'status' => $errors ? 'needs_fix' : ($warnings ? 'warning' : 'pass'),
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => self::CHECKS,
        ];
    }

    /**
     * @return array{0: list<array{selectors: list<string>, declarations: array<string,string>}>,
     *               1: list<array{condition: string, rules: list<array{selectors: list<string>, declarations: array<string,string>}>}>}
     */
    private function parse(string $css): array
    {
        $mediaBlocks = [];
        $flat = '';
        $offset = 0;

        while (($pos = stripos($css, '@media', $offset)) !== false) {
            $flat .= substr($css, $offset, $pos - $offset);
            $braceOpen = strpos($css, '{', $pos);
            if ($braceOpen === false) { $offset = strlen($css); break; }

            $depth = 0;
            $braceClose = null;
            for ($i = $braceOpen, $len = strlen($css); $i < $len; $i++) {
                if ($css[$i] === '{') $depth++;
                elseif ($css[$i] === '}' && --$depth === 0) { $braceClose = $i; break; }
            }
            if ($braceClose === null) { $offset = strlen($css); break; }

            $mediaBlocks[] = [
                'condition' => strtolower(trim(substr($css, $pos + 6, $braceOpen - $pos - 6))),
                'rules' => $this->parseRules(substr($css, $braceOpen + 1, $braceClose - $braceOpen - 1)),
            ];
            $offset = $braceClose + 1;
        }
        $flat .= substr($css, $offset);

        return [$this->parseRules($flat), $mediaBlocks];
    }

    /** @return list<array{selectors: list<string>, declarations: array<string,string>}> */
    private function parseRules(string $flatCss): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $flatCss, $matches, PREG_SET_ORDER);
        $rules = [];
        foreach ($matches as $match) {
            $declarations = [];
            foreach (explode(';', $match[2]) as $declaration) {
                if (!str_contains($declaration, ':')) continue;
                [$property, $value] = array_map('trim', explode(':', $declaration, 2));
                if ($property !== '') $declarations[strtolower($property)] = strtolower($value);
            }
            if (!$declarations) continue;
            $rules[] = [
                'selectors' => array_values(array_filter(array_map('trim', explode(',', $match[1])))),
                'declarations' => $declarations,
            ];
        }
        return $rules;
    }

    private function displaySelector(string $selector, string $scope): string
    {
        if ($scope !== '') {
            $selector = preg_replace('/^\.' . preg_quote($scope, '/') . '\s+/', '', $selector) ?? $selector;
        }
        return trim(preg_replace('/\s+/', ' ', $selector) ?? $selector);
    }

    private function pxValue(string $value): ?float
    {
        return preg_match('/^(\d+(?:\.\d+)?)px$/', trim($value), $m) ? (float) $m[1] : null;
    }

    /** @return list<float> */
    private function pxNumbers(string $value): array
    {
        preg_match_all('/(\d+(?:\.\d+)?)px/', $value, $m);
        return array_map('floatval', $m[1]);
    }

    private function maxDurationMs(string $value): int
    {
        preg_match_all('/(\d+(?:\.\d+)?)(ms|s)\b/', $value, $m, PREG_SET_ORDER);
        $max = 0;
        foreach ($m as $match) {
            $ms = $match[2] === 's' ? (float) $match[1] * 1000 : (float) $match[1];
            $max = max($max, (int) round($ms));
        }
        return $max;
    }

    private function columnTrackCount(string $value): int
    {
        $value = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
        if ($value === '' || str_contains($value, 'auto-fit') || str_contains($value, 'auto-fill')) return 1;
        if (preg_match('/repeat\(\s*(\d+)\s*,/', $value, $m)) return max(1, (int) $m[1]);

        $depth = 0;
        $tracks = 1;
        foreach (str_split($value) as $char) {
            if ($char === '(') $depth++;
            elseif ($char === ')') $depth--;
            elseif ($char === ' ' && $depth === 0) $tracks++;
        }
        return $tracks;
    }

    /** @param list<string> $selectors @param array<string,string> $decls */
    private function limitsImageWidth(array $selectors, array $decls): bool
    {
        $maxWidth = $decls['max-width'] ?? '';
        $width = $decls['width'] ?? '';
        if ($maxWidth !== '100%' && $width !== '100%') return false;

        foreach ($selectors as $selector) {
            if (preg_match('/(^|[\s>+~])img$|\bimg\b|photo|image|img\b|thumb|logo/i', $selector)) return true;
        }
        // img 태그 선택자는 스코프 정책상 못 쓰므로, 클래스명으로 특정할 수 없어도
        // max-width:100% 규칙이 하나라도 있으면 폭 제한 의도로 간주한다 (보수적 휴리스틱)
        return true;
    }

    /** @return array{code:string,message:string} */
    private function finding(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    /** @param list<array{code:string,message:string}> $findings @return list<array{code:string,message:string}> */
    private function dedupe(array $findings): array
    {
        $seen = [];
        $result = [];
        foreach ($findings as $finding) {
            $key = $finding['code'] . '|' . $finding['message'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = $finding;
        }
        return $result;
    }
}
