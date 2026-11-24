<?php
declare(strict_types=1);

namespace Mublo\Service\AI;

use Mublo\Contract\Block\HtmlBlockDraftInterface;
use Mublo\Service\Domain\DomainSettingsService;

/** {@see HtmlBlockDraftInterface} 구현 — 관리자 HTML 블록 AI와 같은 프롬프트·마무리 경로를 정적 블록으로 연다. */
final class HtmlBlockDraftGateway implements HtmlBlockDraftInterface
{
    private const STATIC_ONLY = "\n\nOUTPUT LIMIT\n- Build a static block: no sliders, tabs, accordions or any behavior that needs JavaScript. "
        . "Lay out every item visibly with HTML and CSS only.";

    public function __construct(
        private readonly HtmlBlockAiService $service,
        private readonly ?DomainSettingsService $settings = null,
    ) {
    }

    public function prompts(int $domainId, string $request, ?array $current = null): array
    {
        $request = trim($request);
        if ($request === '' || mb_strlen($request) > 4000) {
            throw new \InvalidArgumentException('HTML 블록 요청은 1자 이상 4,000자 이하여야 합니다.');
        }
        $existing = $current !== null ? $this->unwrap($current) : null;
        $prompts = $this->service->prompts(
            $existing !== null ? 'modify' : 'create',
            $request,
            $existing['html'] ?? '',
            $existing['css'] ?? '',
            ['site_name' => (string) ($this->settings?->getSiteConfig($domainId)['site_title'] ?? '')]
        );
        return [
            'system' => $prompts['system'],
            'user' => $prompts['user'] . self::STATIC_ONLY,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'html' => ['type' => 'string'],
                    'css' => ['type' => 'string'],
                    'notes' => ['type' => 'string'],
                ],
                'required' => ['html', 'css', 'notes'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function finalize(int $domainId, string $scopeKey, string $request, array $result, ?array $current = null): array
    {
        if (!is_string($result['html'] ?? null) || trim($result['html']) === '' || !is_string($result['css'] ?? null)) {
            throw new \InvalidArgumentException('HTML 블록 결과에 html과 css가 필요합니다.');
        }
        $existing = $current !== null ? $this->unwrap($current) : null;
        $final = $this->service->finalize(
            HtmlBlockAiService::scopeFor($scopeKey),
            $request,
            [
                'html' => $result['html'],
                'css' => $result['css'],
                'notes' => is_string($result['notes'] ?? null) ? mb_strimwidth($result['notes'], 0, 500, '…') : '',
                // 정적 블록만 만든다. 인터랙션 스크립트는 관리자 블록 에디터 경로에서만 쓴다.
                'behavior' => ['types' => [], 'autoplay_seconds' => 0, 'slider_preset' => 'none'],
            ],
            $existing !== null ? 'modify' : 'create',
            $existing['managed'] ?? false,
            $existing !== null && !$existing['managed'] ? (string) ($current['css'] ?? '') : '',
        );
        return [
            'html' => $final['html'],
            'css' => $final['css'],
            'notes' => $final['notes'],
            'audit' => $final['audit'],
            'warnings' => $final['warnings'],
        ];
    }

    /**
     * 생성 블록의 스코프 래퍼와 관리 CSS 표식을 벗겨 AI가 수정할 원래 내용으로 돌린다(관리자 생성 경로와 같은 규칙).
     *
     * @param array{html?:string,css?:string} $current
     * @return array{html:string,css:string,managed:bool}
     */
    private function unwrap(array $current): array
    {
        $html = (string) ($current['html'] ?? '');
        $css = (string) ($current['css'] ?? '');
        $scope = null;
        if (preg_match('/^\s*<div class="(mublo-(?:block|ai)-[0-9a-f]{12})">(.*)<\/div>\s*$/s', $html, $match) === 1) {
            $scope = $match[1];
            $html = $match[2];
        }
        $managed = str_contains($css, '/* mublo-generated */') || str_contains($css, '/* mublo-ai-managed */');
        if ($managed && $scope !== null) {
            $css = preg_replace(
                '/\/\*\s*mublo-layout-container\s*\*\/\s*\.' . preg_quote($scope, '/') . '\s*\{\s*container-type\s*:\s*inline-size\s*;?\s*\}\s*/i',
                '',
                $css
            ) ?? $css;
            $css = str_replace(['/* mublo-generated */', '/* mublo-ai-managed */', '.' . $scope . ' '], '', $css);
        }
        return ['html' => $html, 'css' => trim($css), 'managed' => $managed];
    }
}
