<?php

namespace Mublo\Packages\Shop\Block;

use Mublo\Core\Block\Form\ConfigFormInterface;

/**
 * ProductConfigForm
 *
 * 블록 관리자에서 상품 콘텐츠 설정 폼 렌더링
 *
 * 상품 아이템 선택은 DualListbox (block-product.js)에서 처리되므로
 * 이 폼에서는 표시 관련 설정만 제공합니다.
 */
class ProductConfigForm implements ConfigFormInterface
{
    /**
     * 설정 폼 HTML 렌더링
     */
    public function render(array $currentConfig = []): string
    {
        $showPrice = (bool) ($currentConfig['show_price'] ?? true);
        $showReward = (bool) ($currentConfig['show_reward'] ?? false);
        $showBadge = (bool) ($currentConfig['show_badge'] ?? true);

        $html = '<div class="product-config-form">';

        // 가격 표시
        $html .= '<div class="mb-3">';
        $html .= '<div class="form-check">';
        $html .= '<input type="hidden" name="content_config[show_price]" value="0">';
        $html .= '<input type="checkbox" name="content_config[show_price]" value="1" class="form-check-input"';
        $html .= $showPrice ? ' checked' : '';
        $html .= ' id="product-show-price">';
        $html .= '<label class="form-check-label" for="product-show-price">가격 표시</label>';
        $html .= '</div>';
        $html .= '</div>';

        // 적립금 표시
        $html .= '<div class="mb-3">';
        $html .= '<div class="form-check">';
        $html .= '<input type="hidden" name="content_config[show_reward]" value="0">';
        $html .= '<input type="checkbox" name="content_config[show_reward]" value="1" class="form-check-input"';
        $html .= $showReward ? ' checked' : '';
        $html .= ' id="product-show-reward">';
        $html .= '<label class="form-check-label" for="product-show-reward">적립금 표시</label>';
        $html .= '</div>';
        $html .= '</div>';

        // 배지 표시
        $html .= '<div class="mb-3">';
        $html .= '<div class="form-check">';
        $html .= '<input type="hidden" name="content_config[show_badge]" value="0">';
        $html .= '<input type="checkbox" name="content_config[show_badge]" value="1" class="form-check-input"';
        $html .= $showBadge ? ' checked' : '';
        $html .= ' id="product-show-badge">';
        $html .= '<label class="form-check-label" for="product-show-badge">배지 표시 (NEW, SALE 등)</label>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * 아이템 선택은 DualListbox (block-product.js)에서 처리
     */
    public function renderItemSelector(array $selectedItems = [], int $domainId = 0): string
    {
        return '';
    }
}
