<?php
namespace Mublo\Service\AI;

use Mublo\Core\AiConfig;
use Mublo\Repository\AI\AiUsageRepository;
use Mublo\Repository\AI\AiGenerationRecordRepository;
use Mublo\Service\AI\Provider\AiProviderFactory;

class HtmlBlockAiService
{
    private const LAYOUT_CONTAINER_MARKER = '/* mublo-layout-container */';

    public function __construct(
        private DomainAiConfigService $configService,
        private AiUsageRepository $usage,
        private AiProviderFactory $providers,
        private ScopedCssSanitizer $cssSanitizer,
        private HtmlBlockPromptBuilder $promptBuilder,
        private AiHtmlSanitizer $htmlSanitizer,
        private TrustedBlockBehaviorBuilder $behaviorBuilder,
        private ResponsiveCssAuditor $responsiveAuditor,
        private HtmlBlockVisibleCopyFilter $visibleCopyFilter,
        private ?AiAssetService $assetService = null,
        private ?AiGenerationRecordRepository $records = null,
    ) {}

    /**
     * 최근 AI 생성 이력 목록 (AI 자료실용)
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentRecords(int $domainId, int $limit = 20): array
    {
        return $this->records?->recent($domainId, $limit) ?? [];
    }

    /**
     * 특정 모드들의 최근 AI 생성 이력 (프레임 파트 복원용)
     *
     * @param string[] $modes
     * @return array<int, array<string, mixed>>
     */
    public function recentRecordsByModes(int $domainId, array $modes, int $limit = 10): array
    {
        return $this->records?->recentByModes($domainId, $modes, $limit) ?? [];
    }

    /**
     * AI 생성 이력 단건 조회 (결과물 포함) — "이 결과에서 이어서" 복원용
     *
     * @return array<string, mixed>|null
     */
    public function findRecord(int $domainId, int $recordId): ?array
    {
        return $this->records?->find($domainId, $recordId);
    }

    public function generate(int $domainId, int $rowId, int $columnIndex, array $input): array
    {
        $config = AiConfig::load();
        $prompt = trim((string) ($input['prompt'] ?? ''));
        $mode = ($input['mode'] ?? '') === 'modify' ? 'modify' : 'create';
        $currentHtml = $mode === 'modify' ? (string) ($input['current_html'] ?? '') : '';
        $currentCss = $mode === 'modify' ? (string) ($input['current_css'] ?? '') : '';
        $currentJs = (string) ($input['current_js'] ?? '');
        $assetIds = is_array($input['asset_ids'] ?? null) ? $input['asset_ids'] : [];
        $hash = substr(hash('sha256', "{$domainId}:{$rowId}:{$columnIndex}"), 0, 12);
        $scope = 'mublo-block-' . $hash;
        $legacyScope = 'mublo-ai-' . $hash;
        $hasManagedCss = str_contains($currentCss, '/* mublo-generated */')
            || str_contains($currentCss, '/* mublo-ai-managed */');
        if ($mode === 'modify') {
            $currentHtml = preg_replace(
                '/^\s*<div class="(?:' . preg_quote($scope, '/') . '|' . preg_quote($legacyScope, '/') . ')">(.*)<\/div>\s*$/s',
                '$1',
                $currentHtml
            ) ?? $currentHtml;
            if ($hasManagedCss) {
                $currentCss = preg_replace(
                    '/\/\*\s*mublo-layout-container\s*\*\/\s*\.' . preg_quote($scope, '/')
                    . '\s*\{\s*container-type\s*:\s*inline-size\s*;?\s*\}\s*/i',
                    '',
                    $currentCss
                ) ?? $currentCss;
                $currentCss = str_replace(
                    ['/* mublo-generated */', '/* mublo-ai-managed */', '.' . $scope . ' ', '.' . $legacyScope . ' '],
                    '',
                    $currentCss
                );
            }
            [$currentHtml, $currentCss] = $this->normalizeGeneratedClassNames($currentHtml, $currentCss);
        }
        if ($prompt === '' || mb_strlen($prompt) > (int) $config['max_prompt_chars']) {
            throw new \InvalidArgumentException('요청 내용을 1자 이상 4,000자 이하로 입력해주세요.');
        }
        if (mb_strlen($currentHtml . $currentCss . $currentJs) > (int) $config['max_existing_content_chars']) {
            throw new \InvalidArgumentException('현재 HTML/CSS가 AI 수정 허용 크기를 초과했습니다.');
        }

        $runtime = $this->configService->runtimeConfig($domainId);
        $attachments = $this->assetService?->resolveForPrompt($domainId, $assetIds) ?? [];
        $inputChars = mb_strlen($prompt . $currentHtml . $currentCss . $currentJs)
            + array_sum(array_map(fn (array $asset): int => mb_strlen((string) ($asset['text'] ?? '')), $attachments));
        if (!$this->usage->consume($domainId, $runtime['daily_request_limit'], $inputChars)) {
            throw new \RuntimeException('이 도메인의 오늘 AI 요청 한도를 모두 사용했습니다.');
        }

        $site = is_array($input['site'] ?? null) ? $input['site'] : [];
        $prompts = $this->promptBuilder->build($mode, $prompt, $currentHtml, $currentCss, $site);

        try {
            $result = $this->providers->make($runtime['provider'])->generate(
                $runtime['api_key'], $runtime['model'], $prompts['system'], $prompts['user'], $attachments
            );
        } catch (\Throwable $e) {
            $this->record($domainId, $rowId, $columnIndex, $mode, $runtime, $prompt, $assetIds, [
                'status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 500),
                'member_id' => $input['member_id'] ?? null,
            ]);
            throw $e;
        }
        [$resultHtml, $resultCss] = $this->normalizeGeneratedClassNames($result['html'], $result['css']);
        $html = $this->htmlSanitizer->sanitize($resultHtml);
        $copyResult = $this->visibleCopyFilter->filter($html);
        $html = $copyResult['html'];
        $behaviorWarnings = [];
        $behavior = HtmlBlockAiPolicy::enforceRequestBehavior($result['behavior'], $prompt);
        try {
            $behaviorAssets = $this->behaviorBuilder->build($behavior, $scope, $html);
            $behaviorWarnings = $behaviorAssets['warnings'];
        } catch (\RuntimeException $e) {
            $behaviorAssets = ['css' => '', 'js' => '', 'warnings' => []];
            $behaviorWarnings[] = $e->getMessage() . ' HTML/CSS 결과만 적용했습니다.';
        }
        $html = '<div class="' . $scope . '">' . $html . '</div>';
        $modelCssResult = $this->cssSanitizer->sanitizeWithReport($resultCss, $scope);
        $behaviorCssResult = $this->cssSanitizer->sanitizeWithReport($behaviorAssets['css'], $scope);
        // cqi 기반 타이포그래피가 브라우저 viewport가 아니라 실제 배치된 블록 폭을
        // 따르도록 신뢰된 스코프 래퍼를 inline-size query container로 만든다.
        // 공용 block.css나 수동 작성 블록에는 영향을 주지 않는다.
        $layoutCss = self::LAYOUT_CONTAINER_MARKER
            . "\n.{$scope} { container-type: inline-size; }";
        $cssBodies = array_filter(array_map(
            fn (string $value): string => trim(str_replace(
                ['/* mublo-generated */', '/* mublo-ai-managed */'],
                '',
                $value
            )),
            [$layoutCss, $modelCssResult['css'], $behaviorCssResult['css']]
        ));
        $css = $cssBodies ? "/* mublo-generated */\n" . implode("\n", $cssBodies) : '';
        if ($mode === 'modify' && !$hasManagedCss && trim((string) ($input['current_css'] ?? '')) !== '') {
            $css = rtrim((string) $input['current_css']) . "\n\n" . $css;
        }
        $js = $this->behaviorBuilder->mergeJs($currentJs, $behaviorAssets['js']);
        $this->usage->addOutput($domainId, mb_strlen($html . $css . $js));

        $notes = $result['notes'];
        $copyWarnings = $copyResult['removed']
            ? [sprintf('AI 결과에서 작업 과정 설명 %d개를 제거했습니다.', count($copyResult['removed']))]
            : [];
        $warnings = array_merge(
            $modelCssResult['warnings'],
            $behaviorCssResult['warnings'],
            $behaviorWarnings,
            $copyWarnings
        );
        if (trim($result['css']) === '' && trim($behaviorAssets['css']) === '') {
            $warnings[] = 'AI가 CSS를 생성하지 않았습니다 — 스타일이 필요하면 다시 시도하거나 요청에 스타일 지시를 추가하세요.';
        }

        // 반응형 정적 검사 (개선 계획 §8) — 게시를 막지 않고 품질 상태만 보고한다.
        // 상세 진단은 audit로 응답하고, 이력에는 요약만 남긴다.
        $audit = $this->responsiveAuditor->audit($html, $css, $scope);
        if ($audit['status'] !== 'pass') {
            $warnings[] = sprintf(
                '[반응형 검사] 오류 %d건, 경고 %d건 — 편집 화면의 품질 진단을 확인하세요.',
                count($audit['errors']),
                count($audit['warnings'])
            );
        }
        if ($warnings) $notes = trim($notes . ' ' . implode(' ', array_unique($warnings)));
        $this->record($domainId, $rowId, $columnIndex, $mode, $runtime, $prompt, $assetIds, [
            'status' => 'success', 'result_html' => $html, 'result_css' => $css, 'result_js' => $js,
            'notes' => $notes, 'member_id' => $input['member_id'] ?? null,
        ]);
        return ['html' => $html, 'css' => $css, 'js' => $js, 'notes' => $notes, 'audit' => $audit];
    }

    /** @return array{string,string} */
    private function normalizeGeneratedClassNames(string $html, string $css): array
    {
        $rename = static function (string $name): string {
            $legacy = [
                'ai-slider' => HtmlBlockAiPolicy::SLIDER_ROOT_CLASS,
                'ai-slides' => HtmlBlockAiPolicy::SLIDER_TRACK_CLASS,
                'ai-slide' => HtmlBlockAiPolicy::SLIDER_ITEM_CLASS,
            ];
            if (isset($legacy[strtolower($name)])) return $legacy[strtolower($name)];

            return preg_replace('/(^|[-_])ai(?=$|[-_])/i', '$1block', $name) ?? $name;
        };

        $html = preg_replace_callback(
            '/\bclass\s*=\s*(["\'])(.*?)\1/is',
            static function (array $match) use ($rename): string {
                $classes = preg_split('/\s+/', trim($match[2])) ?: [];
                $classes = array_values(array_unique(array_filter(array_map($rename, $classes))));
                return 'class=' . $match[1] . implode(' ', $classes) . $match[1];
            },
            $html
        ) ?? $html;
        $css = preg_replace_callback(
            '/\.([A-Za-z_][A-Za-z0-9_-]*)/',
            static fn (array $match): string => '.' . $rename($match[1]),
            $css
        ) ?? $css;

        return [$html, $css];
    }

    private function record(
        int $domainId, int $rowId, int $columnIndex, string $mode, array $runtime,
        string $prompt, array $assetIds, array $data
    ): void {
        if (!$this->records) return;
        try {
            $this->records->create($data + [
                'domain_id' => $domainId, 'row_id' => $rowId, 'column_index' => $columnIndex,
                'mode' => $mode, 'provider' => $runtime['provider'], 'model' => $runtime['model'],
                'prompt' => $prompt, 'asset_ids' => array_values(array_map('intval', $assetIds)),
            ]);
        } catch (\Throwable) {
            // 생성 결과 자체를 이력 저장 장애 때문에 폐기하지 않는다.
        }
    }
}
