<?php
namespace Mublo\Service\AI;

use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;

/**
 * 프레임 템플릿 계약 검증기 (개선 계획 §7)
 *
 * AI가 생성한 header/footer 템플릿이 프레임 기능을 훼손하지 않는지
 * 서버에서 검증한다. 프롬프트 지시는 권고일 뿐 — 계약은 여기서 강제된다.
 *
 * - 토큰 문맥: slot 토큰은 독립 텍스트 노드에서만, variable 토큰은 텍스트와
 *   제한된 텍스트 속성에서만. img src는 /storage/·로고 토큰만.
 * - 미등록 토큰은 조용히 소거하지 않고 오류로 보고한다.
 * - Header: 모바일 토글(button#mubloPanelToggle) + {{mobile_panel}} 한 쌍 필수.
 * - Footer: {{theme_switch}} 필수 (다크모드 전환 수단 보존).
 * - 계약 오류가 있는 결과는 에디터에 자동 반영하지 않는다 (이력에는 보존).
 */
class FrameTemplateContractValidator
{
    private const TOKEN_PATTERN = '/\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?)\s*\}\}/';

    /**
     * variable 토큰을 허용하는 텍스트 성격 속성
     */
    private const VARIABLE_TEXT_ATTRIBUTES = ['alt', 'title', 'aria-label', 'placeholder', 'href'];

    /**
     * img src에 허용되는 로고 토큰
     */
    private const LOGO_TOKENS = ['logo_url', 'logo_mobile_url'];

    /**
     * @param string $part 'header' | 'footer'
     * @param array<array{name: string, kind: string, label: string}> $allowedTokens
     */
    public function validate(string $part, string $html, array $allowedTokens, string $mode = 'create'): FrameTemplateValidationResult
    {
        $errors = [];
        $warnings = [];
        $usedTokens = [];

        $kinds = [];
        foreach ($allowedTokens as $token) {
            $kinds[$token['name']] = $token['kind'];
        }

        if (trim($html) === '') {
            return new FrameTemplateValidationResult(['생성된 HTML이 비어 있습니다.'], [], []);
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div data-mublo-root="1">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);

        $slotUseCount = [];

        // ---- 텍스트 노드의 토큰 (주석은 렌더러가 치환하지 않으므로 제외) ----
        foreach ($xpath->query('//text()') ?: [] as $textNode) {
            if (!$textNode instanceof DOMText) continue;
            $text = $textNode->nodeValue ?? '';
            if (!preg_match_all(self::TOKEN_PATTERN, $text, $m)) continue;

            $residue = trim((string) preg_replace(self::TOKEN_PATTERN, '', $text));

            foreach ($m[1] as $name) {
                $usedTokens[$name] = true;
                $kind = $kinds[$name] ?? null;

                if ($kind === null) {
                    $errors[] = "등록되지 않은 템플릿 토큰: {{{$name}}}";
                    continue;
                }
                if ($kind === 'slot') {
                    $slotUseCount[$name] = ($slotUseCount[$name] ?? 0) + 1;
                    if ($residue !== '') {
                        $errors[] = "슬롯 토큰 {{{$name}}}은 다른 텍스트와 섞이지 않은 독립 위치에 있어야 합니다.";
                    }
                }
                if (in_array($name, self::LOGO_TOKENS, true)) {
                    $warnings[] = "{{{$name}}}는 img src 속성용 토큰입니다 — 텍스트 위치에서는 URL 문자열이 그대로 노출됩니다.";
                }
            }
        }

        // ---- 속성의 토큰 ----
        foreach ($xpath->query('//@*') ?: [] as $attr) {
            $value = (string) $attr->nodeValue;
            if (!preg_match_all(self::TOKEN_PATTERN, $value, $m)) continue;

            $ownerTag = strtolower($attr->ownerElement?->tagName ?? '');
            $attrName = strtolower($attr->nodeName);

            foreach ($m[1] as $name) {
                $usedTokens[$name] = true;
                $kind = $kinds[$name] ?? null;

                if ($kind === null) {
                    $errors[] = "등록되지 않은 템플릿 토큰: {{{$name}}} ({$attrName} 속성)";
                    continue;
                }
                if ($kind === 'slot') {
                    $errors[] = "슬롯 토큰 {{{$name}}}은 HTML 속성({$attrName}) 안에서 사용할 수 없습니다 — 슬롯은 완성된 HTML을 삽입합니다.";
                    continue;
                }
                // variable 토큰의 속성 사용 규칙
                if ($ownerTag === 'img' && $attrName === 'src') {
                    if (!in_array($name, self::LOGO_TOKENS, true)) {
                        $errors[] = "img src에는 로고 토큰({{logo_url}}/{{logo_mobile_url}})만 사용할 수 있습니다: {{{$name}}}";
                    }
                    continue;
                }
                if (!in_array($attrName, self::VARIABLE_TEXT_ATTRIBUTES, true)) {
                    $errors[] = "변수 토큰 {{{$name}}}은 {$attrName} 속성에서 사용할 수 없습니다 (허용: " . implode(', ', self::VARIABLE_TEXT_ATTRIBUTES) . ', img src의 로고 토큰).';
                }
            }
        }

        // ---- img src 값 자체 검증 (토큰이 아닌 경우) ----
        foreach ($xpath->query('//img[@src]') ?: [] as $img) {
            if (!$img instanceof DOMElement) continue;
            $src = trim($img->getAttribute('src'));
            if ($src === '' || str_starts_with($src, '/storage/')) continue;
            if (preg_match('/^\{\{\s*(' . implode('|', self::LOGO_TOKENS) . ')\s*\}\}$/', $src)) continue;
            $errors[] = "img src는 /storage/ 상대 경로 또는 로고 토큰만 허용됩니다: " . mb_substr($src, 0, 80);
        }

        // ---- 슬롯 중복 ----
        foreach ($slotUseCount as $name => $count) {
            if ($count > 1) {
                $errors[] = "슬롯 토큰 {{{$name}}}이 {$count}회 사용됐습니다 — 컴포넌트 슬롯은 한 번만 배치합니다.";
            }
        }

        // ---- 파트별 필수 구조 계약 ----
        if ($part === 'header') {
            $hasToggle = ($xpath->query('//button[@id="mubloPanelToggle"]') ?: null)?->length > 0;
            $hasPanel = isset($usedTokens['mobile_panel']);
            if (!$hasToggle && !$hasPanel) {
                $errors[] = 'Header에 모바일 토글(button#mubloPanelToggle)과 {{mobile_panel}}이 없습니다 — 모바일 메뉴가 사라집니다.';
            } elseif (!$hasToggle) {
                $errors[] = 'Header에 모바일 토글 버튼(button#mubloPanelToggle)이 없습니다 — {{mobile_panel}}과 한 쌍이어야 합니다.';
            } elseif (!$hasPanel) {
                $errors[] = 'Header에 {{mobile_panel}} 토큰이 없습니다 — 토글 버튼과 한 쌍이어야 합니다.';
            }
            if (!($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " mublo-header ")]') ?: null)?->length) {
                $warnings[] = '.mublo-header 클래스가 없습니다 — 스킨의 sticky·모바일 브레이크포인트가 적용되지 않습니다.';
            }
        }

        if ($part === 'footer') {
            if (!isset($usedTokens['theme_switch'])) {
                $errors[] = 'Footer에 {{theme_switch}} 토큰이 없습니다 — 방문자의 다크모드 전환 수단이 사라집니다.';
            }
            if (!($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " mublo-footer ")]') ?: null)?->length) {
                $warnings[] = '.mublo-footer 클래스가 없습니다 — 스킨 푸터 스타일이 적용되지 않습니다.';
            }
        }

        return new FrameTemplateValidationResult(
            array_values(array_unique($errors)),
            array_values(array_unique($warnings)),
            array_keys($usedTokens)
        );
    }
}
