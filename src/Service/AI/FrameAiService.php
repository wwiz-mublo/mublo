<?php
namespace Mublo\Service\AI;

use Mublo\Core\AiConfig;
use Mublo\Repository\AI\AiUsageRepository;
use Mublo\Repository\AI\AiGenerationRecordRepository;
use Mublo\Service\AI\Provider\AiProviderFactory;

/**
 * 도메인 프레임 편집(header/footer) AI 생성 서비스
 *
 * HtmlBlockAiService와 공통 골격(도메인 설정 → 사용량 한도 → 프로바이더 →
 * 새니타이즈 → 이력)을 공유하되 블록 좌표(rowId/columnIndex)에 결합하지
 * 않는다 (계획문서 §5).
 *
 * - HTML: FrameAiPolicy 새니타이저 (템플릿 토큰 보존, 목록 밖 토큰 소거)
 * - CSS: ScopedCssSanitizer로 .mublo-frame-{part} 스코핑
 * - JS: 생성하지 않는다 (신뢰 템플릿 규약 — 프레임은 behavior도 없음)
 * - 이력: ai_generation_records.mode = 'frame_header'|'frame_footer'
 *   (row_id/column_index는 0 — 블록 좌표 아님)
 * - 결과는 draft로도 저장하지 않는다 — 에디터에 반영만 하고, 저장·게시는
 *   운영자가 명시적으로 수행한다 (§3.8 게시 절차)
 */
class FrameAiService
{
    private AiHtmlSanitizer $htmlSanitizer;

    public function __construct(
        private DomainAiConfigService $configService,
        private AiUsageRepository $usage,
        private AiProviderFactory $providers,
        private ScopedCssSanitizer $cssSanitizer,
        private FrameAiPromptBuilder $promptBuilder,
        private FrameTemplateContractValidator $contractValidator,
        private ResponsiveCssAuditor $responsiveAuditor,
        private ?AiGenerationRecordRepository $records = null,
        ?AiHtmlSanitizer $htmlSanitizer = null,
    ) {
        $this->htmlSanitizer = $htmlSanitizer ?? FrameAiPolicy::sanitizer();
    }

    /**
     * @param string $part 'header' | 'footer'
     * @param array $input prompt, mode('create'|'modify'), current_html, current_css, member_id
     * @param array<array{name: string, kind: string, label: string}> $allowedTokens
     *        사용 가능한 템플릿 문자열 (코어 §3.1 + 활성 확장 §3.9) — 프롬프트 컨텍스트이자
     *        목록 밖 토큰 소거 기준
     * @param array{site_name?: string, primary_color?: string, skin?: string, seed_html?: string} $siteContext
     *        사이트 컨텍스트 (P1 — 브랜드·스킨 정합용)
     * @return array{html: string, css: string, notes: string, audit: array{status: string, errors: list<array{code: string, message: string}>, warnings: list<array{code: string, message: string}>, checks: list<string>}}
     */
    public function generate(int $domainId, string $part, array $input, array $allowedTokens, array $siteContext = []): array
    {
        if (!in_array($part, ['header', 'footer'], true)) {
            throw new \InvalidArgumentException('지원하지 않는 프레임 파트입니다.');
        }

        $config = AiConfig::load();
        $prompt = trim((string) ($input['prompt'] ?? ''));
        $mode = ($input['mode'] ?? '') === 'modify' ? 'modify' : 'create';
        $currentHtml = $mode === 'modify' ? (string) ($input['current_html'] ?? '') : '';
        $currentCss = $mode === 'modify' ? (string) ($input['current_css'] ?? '') : '';

        if ($prompt === '' || mb_strlen($prompt) > (int) $config['max_prompt_chars']) {
            throw new \InvalidArgumentException('요청 내용을 1자 이상 4,000자 이하로 입력해주세요.');
        }
        if (mb_strlen($currentHtml . $currentCss) > (int) $config['max_existing_content_chars']) {
            throw new \InvalidArgumentException('현재 HTML/CSS가 AI 수정 허용 크기를 초과했습니다.');
        }

        $runtime = $this->configService->runtimeConfig($domainId);
        $inputChars = mb_strlen($prompt . $currentHtml . $currentCss);
        if (!$this->usage->consume($domainId, $runtime['daily_request_limit'], $inputChars)) {
            throw new \RuntimeException('이 도메인의 오늘 AI 요청 한도를 모두 사용했습니다.');
        }

        $prompts = $this->promptBuilder->build($part, $mode, $prompt, $currentHtml, $currentCss, $allowedTokens, $siteContext);

        $recordMode = 'frame_' . $part;
        try {
            $result = $this->providers->make($runtime['provider'])->generate(
                $runtime['api_key'], $runtime['model'], $prompts['system'], $prompts['user'],
            );
        } catch (\Throwable $e) {
            $this->record($domainId, $recordMode, $runtime, $prompt, [
                'status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 500),
                'member_id' => $input['member_id'] ?? null,
            ]);
            throw $e;
        }

        $html = $this->htmlSanitizer->sanitize((string) $result['html']);

        $scope = 'mublo-frame-' . $part;
        $cssResult = $this->cssSanitizer->sanitizeWithReport((string) $result['css'], $scope);
        $css = trim($cssResult['css']);

        $this->usage->addOutput($domainId, mb_strlen($html . $css));

        // 프레임 계약 검증 (개선 계획 §7) — 미등록 토큰은 조용히 소거하지 않고
        // 오류로 보고하며, 계약 오류가 있는 결과는 에디터에 자동 반영하지 않는다.
        // 정화된 결과물은 이력에 보존해 운영자가 확인·수동 수정할 수 있게 한다.
        $validation = $this->contractValidator->validate($part, $html, $allowedTokens, $mode);
        if (!$validation->isValid()) {
            $errorSummary = implode(' / ', $validation->errors);
            $this->record($domainId, $recordMode, $runtime, $prompt, [
                'status' => 'invalid',
                'result_html' => $html,
                'result_css' => $css,
                'notes' => trim((string) $result['notes'] . ' [계약 검증 실패] ' . $errorSummary),
                'error_message' => mb_substr($errorSummary, 0, 500),
                'member_id' => $input['member_id'] ?? null,
            ]);
            throw new \RuntimeException(
                'AI 결과가 프레임 계약 검증에 실패해 반영하지 않았습니다: ' . $errorSummary
                . ' (생성 결과는 AI 이력에서 확인할 수 있습니다)'
            );
        }

        $notes = (string) $result['notes'];
        $warnings = array_merge($cssResult['warnings'], $validation->warnings);
        if ($html !== '' && $css === '' && trim((string) $result['css']) === '') {
            $warnings[] = 'AI가 CSS를 생성하지 않았습니다 — 스타일이 필요하면 다시 시도하거나 요청에 스타일 지시를 추가하세요.';
        }

        // 반응형 정적 검사 (개선 계획 §8) — 게시를 막지 않고 품질 상태만 보고한다.
        $audit = $this->responsiveAuditor->audit($html, $css, $scope);
        if ($audit['status'] !== 'pass') {
            $warnings[] = sprintf(
                '[반응형 검사] 오류 %d건, 경고 %d건 — 편집 화면의 품질 진단을 확인하세요.',
                count($audit['errors']),
                count($audit['warnings'])
            );
        }
        if ($warnings) $notes = trim($notes . ' ' . implode(' ', array_unique($warnings)));

        $this->record($domainId, $recordMode, $runtime, $prompt, [
            'status' => 'success', 'result_html' => $html, 'result_css' => $css,
            'notes' => $notes, 'member_id' => $input['member_id'] ?? null,
        ]);

        return ['html' => $html, 'css' => $css, 'notes' => $notes, 'audit' => $audit];
    }

    private function record(int $domainId, string $mode, array $runtime, string $prompt, array $data): void
    {
        if (!$this->records) return;
        try {
            $this->records->create($data + [
                'domain_id' => $domainId, 'row_id' => 0, 'column_index' => 0,
                'mode' => $mode, 'provider' => $runtime['provider'], 'model' => $runtime['model'],
                'prompt' => $prompt, 'asset_ids' => [],
            ]);
        } catch (\Throwable) {
            // 생성 결과 자체를 이력 저장 장애 때문에 폐기하지 않는다.
        }
    }
}
