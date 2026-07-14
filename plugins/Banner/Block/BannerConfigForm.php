<?php
namespace Mublo\Plugin\Banner\Block;

use Mublo\Core\Block\Form\ConfigFormInterface;

/**
 * BannerConfigForm
 *
 * 블록 관리자에서 배너 콘텐츠 설정 폼 렌더링
 *
 * 배너 아이템 선택은 DualListbox (BlockrowController.fetchContentItems)에서 처리되므로
 * 이 폼에서는 슬라이드 관련 설정만 제공합니다.
 */
class BannerConfigForm implements ConfigFormInterface
{
    /**
     * 설정 폼 HTML 렌더링
     */
    public function render(array $currentConfig = []): string
    {
        $effect = $currentConfig['effect'] ?? 'slide';
        $autoplay = (bool) ($currentConfig['autoplay'] ?? true);
        $autoplaySpeed = (int) ($currentConfig['autoplay_speed'] ?? 3000);
        $showNavigation = (bool) ($currentConfig['show_navigation'] ?? true);
        $showPagination = (bool) ($currentConfig['show_pagination'] ?? true);

        $html = '<div class="banner-config-form">';

        // 효과
        $html .= '<div class="mb-3">';
        $html .= '<label class="form-label">전환 효과</label>';
        $html .= '<select name="content_config[effect]" class="form-select">';
        $html .= '<option value="slide"' . ($effect === 'slide' ? ' selected' : '') . '>슬라이드</option>';
        $html .= '<option value="fade"' . ($effect === 'fade' ? ' selected' : '') . '>페이드</option>';
        $html .= '</select>';
        $html .= '</div>';

        // 자동 재생
        $html .= '<div class="mb-3">';
        $html .= '<div class="form-check">';
        $html .= '<input type="hidden" name="content_config[autoplay]" value="0">';
        $html .= '<input type="checkbox" name="content_config[autoplay]" value="1" class="form-check-input"';
        $html .= $autoplay ? ' checked' : '';
        $html .= ' id="banner-autoplay">';
        $html .= '<label class="form-check-label" for="banner-autoplay">자동 재생</label>';
        $html .= '</div>';
        $html .= '</div>';

        // 자동 재생 속도
        $html .= '<div class="mb-3">';
        $html .= '<label class="form-label">자동 재생 속도 (ms)</label>';
        $html .= '<input type="number" name="content_config[autoplay_speed]" class="form-control" style="width:120px;"';
        $html .= ' value="' . $autoplaySpeed . '" min="1000" step="500">';
        $html .= '</div>';

        // 네비게이션 표시
        $html .= '<div class="mb-3">';
        $html .= '<div class="form-check">';
        $html .= '<input type="hidden" name="content_config[show_navigation]" value="0">';
        $html .= '<input type="checkbox" name="content_config[show_navigation]" value="1" class="form-check-input"';
        $html .= $showNavigation ? ' checked' : '';
        $html .= ' id="banner-nav">';
        $html .= '<label class="form-check-label" for="banner-nav">좌우 화살표 표시</label>';
        $html .= '</div>';
        $html .= '</div>';

        // 페이지네이션 표시
        $html .= '<div class="mb-3">';
        $html .= '<div class="form-check">';
        $html .= '<input type="hidden" name="content_config[show_pagination]" value="0">';
        $html .= '<input type="checkbox" name="content_config[show_pagination]" value="1" class="form-check-input"';
        $html .= $showPagination ? ' checked' : '';
        $html .= ' id="banner-pagination">';
        $html .= '<label class="form-check-label" for="banner-pagination">페이지 인디케이터 표시</label>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * 아이템 선택은 DualListbox (BlockrowController.fetchContentItems)에서 처리
     */
    public function renderItemSelector(array $selectedItems = [], int $domainId = 0): string
    {
        return '';
    }
}
