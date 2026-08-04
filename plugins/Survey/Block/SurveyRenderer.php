<?php
declare(strict_types=1);
namespace Mublo\Plugin\Survey\Block;

use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Core\Block\Renderer\SkinRendererTrait;
use Mublo\Contract\Block\BlockColumnView;
use Mublo\Plugin\Survey\Entity\Survey;
use Mublo\Plugin\Survey\Service\SurveyResultService;
use Mublo\Plugin\Survey\Service\SurveyService;
use Mublo\Plugin\Survey\Service\SurveySubmitService;

class SurveyRenderer implements RendererInterface
{
    use SkinRendererTrait;

    public function __construct(
        private SurveyService       $surveyService,
        private SurveyResultService $resultService,
        private SurveySubmitService $submitService,
    ) {}

    protected function getSkinType(): string
    {
        return 'survey';
    }

    protected function getSkinBasePath(): string
    {
        return MUBLO_PLUGIN_PATH . '/Survey/views/Block/';
    }

    public function render(BlockColumnView $column): string
    {
        $skin  = $column->getContentSkin() ?: 'basic';
        $items = $column->getContentItems() ?? [];

        $first    = $items[0] ?? null;
        $surveyId = (int) (is_array($first) ? ($first['id'] ?? 0) : ($first ?? 0));
        if ($surveyId === 0) {
            // 설문 미선택 → 빈 상태(칸 유지)
            return $this->renderEmpty($column, $skin, '표시할 설문이 없습니다.');
        }

        $domainId = (int) ($column->getDomainId() ?? 1);
        $config   = $column->getContentConfig() ?? [];
        $mode     = $config['display_mode'] ?? 'form';

        if ($mode === 'result') {
            return $this->renderResult($column, $skin, $domainId, $surveyId, $config);
        }

        return $this->renderForm($column, $skin, $domainId, $surveyId, $config);
    }

    // -------------------------------------------------------------------------

    private function renderForm(BlockColumnView $column, string $skin, int $domainId, int $surveyId, array $config): string
    {
        $result = $this->surveyService->getDetail($domainId, $surveyId);
        if ($result->isFailure()) {
            return $this->renderEmpty($column, $skin, '표시할 설문이 없습니다.');
        }

        $surveyRow = $result->get('survey');
        $survey    = Survey::fromArray($surveyRow);

        // draft(초안)는 없는 것으로 처리(빈 상태, 칸 유지)
        if ($survey->getStatus()->value === 'draft') {
            return $this->renderEmpty($column, $skin, '표시할 설문이 없습니다.');
        }

        // 참여 가능 여부 판단 (closed이거나 기간 외)
        $canJoin     = $survey->isActive() && $survey->isWithinPeriod();
        $joinMessage = '';
        if (!$canJoin) {
            $joinMessage = $survey->isClosed() ? '종료된 설문입니다.' : '설문 참여 기간이 아닙니다.';
        }

        return $this->renderSkin($column, $skin, [
            'mode'        => 'form',
            'survey'      => $surveyRow,
            'questions'   => $result->get('questions', []),
            'config'      => $config,
            'canJoin'     => $canJoin,
            'joinMessage' => $joinMessage,
        ]);
    }

    private function renderResult(BlockColumnView $column, string $skin, int $domainId, int $surveyId, array $config): string
    {
        $result = $this->resultService->getStats($domainId, $surveyId);
        if ($result->isFailure()) {
            return $this->renderEmpty($column, $skin, '표시할 설문이 없습니다.');
        }

        return $this->renderSkin($column, $skin, [
            'mode'           => 'result',
            'survey'         => $result->get('survey'),
            'totalResponses' => $result->get('total_responses', 0),
            'questions'      => $result->get('questions', []),
            'config'         => $config,
        ]);
    }

    /**
     * 빈 상태 — 표시할 설문이 없을 때(미선택/미발견/draft/실패)도 칸을 유지하고
     * 점선 박스 안내를 렌더한다. 스타일은 플러그인 소유(스킨 style.css 의 .block-survey__empty).
     */
    private function renderEmpty(BlockColumnView $column, string $skin, string $message): string
    {
        if (!is_editor_preview()) {
            return '';
        }

        if ($this->assetManager) {
            $this->assetManager->addCss('/serve/plugin/Survey/views/Block/survey/' . $skin . '/style.css');
        }

        $root = htmlspecialchars($skin, ENT_QUOTES, 'UTF-8');
        $msg  = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        return '<div class="block-survey block-survey--' . $root . '">'
             . '<div class="block-survey__empty">'
             . '<i class="bi bi-inbox block-survey__empty-icon"></i>'
             . '<span>' . $msg . '</span>'
             . '</div></div>';
    }
}
