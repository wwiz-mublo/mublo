<?php
namespace Mublo\Core\Block\Renderer;

use Mublo\Entity\Block\BlockColumn;

/**
 * ImageRenderer
 *
 * 이미지 콘텐츠 렌더러
 *
 * 스킨에 전달되는 변수:
 * - $titleConfig: 타이틀 설정 (SkinRendererTrait에서 추출)
 * - $contentConfig: 콘텐츠 설정
 * - $column: BlockColumn 엔티티
 * - $images: 이미지 배열 [{pc_image, mo_image, link_url, link_target, alt}, ...]
 */
class ImageRenderer implements RendererInterface
{
    use SkinRendererTrait;

    /**
     * 스킨 타입 반환
     */
    protected function getSkinType(): string
    {
        return 'image';
    }

    /**
     * {@inheritdoc}
     */
    public function render(BlockColumn $column): string
    {
        $skin = $column->getContentSkin() ?: 'basic';
        $images = $this->extractImages($column);

        if (empty($images)) {
            return $this->renderEmptyContent('이미지가 설정되지 않았습니다.');
        }

        // 스킨 렌더링 (타이틀 + 콘텐츠 모두 스킨에서 처리)
        return $this->renderSkin($column, $skin, [
            'images' => $images,
        ]);
    }

    /**
     * 이미지 데이터 추출
     *
     * 여러 구조 지원:
     * 1. content_items 배열 (신규)
     * 2. content_config.images 배열 (하위호환)
     * 3. content_config 단일 이미지 (레거시)
     */
    private function extractImages(BlockColumn $column): array
    {
        // 1. content_items에서 이미지 배열 읽기 (리팩토링 후 구조)
        $items = $column->getContentItems();
        if (!empty($items) && is_array($items) && isset($items[0]['pc_image'])) {
            return $this->normalizeImages($items);
        }

        // 2. content_config.images 배열 (하위 호환)
        $config = $column->getContentConfig() ?? [];
        if (!empty($config['images']) && is_array($config['images'])) {
            return $this->normalizeImages($config['images']);
        }

        // 3. 레거시 단일 이미지 구조
        if (!empty($config['pc_image'])) {
            return [[
                'pc_image' => $config['pc_image'],
                'mo_image' => $config['mobile_image'] ?? $config['pc_image'],
                'link_url' => $config['link'] ?? null,
                'link_target' => $config['target'] ?? '_self',
                'alt' => $config['alt'] ?? '',
            ]];
        }

        return [];
    }

    /**
     * 이미지 데이터 정규화
     */
    private function normalizeImages(array $items): array
    {
        $images = [];
        foreach ($items as $item) {
            if (empty($item['pc_image'])) {
                continue;
            }
            $images[] = [
                'pc_image' => $item['pc_image'],
                'mo_image' => $item['mo_image'] ?? $item['pc_image'],
                'link_url' => $item['link_url'] ?? null,
                'link_target' => ($item['link_win'] ?? '0') === '1' ? '_blank' : '_self',
                'alt' => $item['alt'] ?? '',
            ];
        }
        return $images;
    }
}
