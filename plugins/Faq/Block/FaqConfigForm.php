<?php
namespace Mublo\Plugin\Faq\Block;

use Mublo\Core\Block\Form\ConfigFormInterface;

/**
 * FaqConfigForm
 *
 * 블록 관리자에서 FAQ 콘텐츠 설정 폼 렌더링
 */
class FaqConfigForm implements ConfigFormInterface
{
    public function render(array $currentConfig = []): string
    {
        $maxItems = (int) ($currentConfig['max_items'] ?? 0);
        $showCategory = (bool) ($currentConfig['show_category'] ?? true);

        $html = '<div class="faq-config-form">';

        // 최대 표시 개수
        $html .= '<div class="mb-3">';
        $html .= '<label class="form-label">최대 표시 개수</label>';
        $html .= '<input type="number" name="content_config[max_items]" class="form-control" style="width:120px;"';
        $html .= ' value="' . $maxItems . '" min="0" placeholder="0 = 전체">';
        $html .= '<div class="form-text">0이면 전체 표시</div>';
        $html .= '</div>';

        // 카테고리명 표시
        $html .= '<div class="mb-3">';
        $html .= '<div class="form-check">';
        $html .= '<input type="hidden" name="content_config[show_category]" value="0">';
        $html .= '<input type="checkbox" name="content_config[show_category]" value="1" class="form-check-input"';
        $html .= $showCategory ? ' checked' : '';
        $html .= ' id="faq-show-category">';
        $html .= '<label class="form-check-label" for="faq-show-category">카테고리명 표시</label>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    public function renderItemSelector(array $selectedItems = [], int $domainId = 0): string
    {
        return '';
    }
}
