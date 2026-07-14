<?php
namespace Mublo\Plugin\Survey\Block;

use Mublo\Core\Block\Form\ConfigFormInterface;

class SurveyConfigForm implements ConfigFormInterface
{
    public function render(array $currentConfig = []): string
    {
        $mode      = $currentConfig['display_mode'] ?? 'form';
        $showTitle = !empty($currentConfig['show_title']);
        $showDesc  = !empty($currentConfig['show_desc']);

        $html  = '<div class="mb-3">';
        $html .= '<label class="form-label fw-medium">표시 모드</label>';
        $html .= '<div class="d-flex gap-3">';
        $html .= '<div class="form-check">';
        $html .= '<input type="radio" class="form-check-input" id="survey-mode-form" name="content_config[display_mode]" value="form"' . ($mode === 'form' ? ' checked' : '') . '>';
        $html .= '<label class="form-check-label" for="survey-mode-form">설문 폼</label>';
        $html .= '</div>';
        $html .= '<div class="form-check">';
        $html .= '<input type="radio" class="form-check-input" id="survey-mode-result" name="content_config[display_mode]" value="result"' . ($mode === 'result' ? ' checked' : '') . '>';
        $html .= '<label class="form-check-label" for="survey-mode-result">결과 차트</label>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="mb-2">';
        $html .= '<input type="hidden" name="content_config[show_title]" value="0">';
        $html .= '<div class="form-check">';
        $html .= '<input type="checkbox" class="form-check-input" id="survey-show-title" name="content_config[show_title]" value="1"' . ($showTitle ? ' checked' : '') . '>';
        $html .= '<label class="form-check-label" for="survey-show-title">제목 표시</label>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="mb-2">';
        $html .= '<input type="hidden" name="content_config[show_desc]" value="0">';
        $html .= '<div class="form-check">';
        $html .= '<input type="checkbox" class="form-check-input" id="survey-show-desc" name="content_config[show_desc]" value="1"' . ($showDesc ? ' checked' : '') . '>';
        $html .= '<label class="form-check-label" for="survey-show-desc">설명 표시</label>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public function renderItemSelector(array $selectedItems = [], int $domainId = 0): string
    {
        return '';
    }
}
