<?php
declare(strict_types=1);
namespace Mublo\Core\Block\Renderer;

use Mublo\Contract\Block\BlockColumnView;

/**
 * ImageRenderer
 *
 * 이미지 콘텐츠 렌더러
 *
 * 스킨에 전달되는 변수:
 * - $titleConfig: 타이틀 설정 (SkinRendererTrait에서 추출)
 * - $contentConfig: 콘텐츠 설정
 * - $column: BlockColumnView 엔티티
 * - $images: 이미지 배열 [{pc_image, mo_image, link_url, link_target, alt, title, desc}, ...]
 *            title·desc 는 선택 입력이며 값이 없으면 빈 문자열이다.
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
    public function render(BlockColumnView $column): string
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
    private function extractImages(BlockColumnView $column): array
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
                'title' => $this->text($config['title'] ?? null),
                'desc' => $this->text($config['desc'] ?? null),
            ]];
        }

        return [];
    }

    /**
     * 이미지 데이터 정규화
     *
     * title·desc 는 선택 입력이다. 값이 없으면 빈 문자열이 되고, 스킨은
     * 빈 문자열을 아예 출력하지 않는 계약이라 기존 이미지의 화면은 그대로다.
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
                'title' => $this->text($item['title'] ?? null),
                'desc' => $this->text($item['desc'] ?? null),
            ];
        }
        return $images;
    }

    /**
     * 선택 입력 문구를 스킨이 바로 쓸 수 있는 문자열로 좁힌다.
     *
     * 배열·객체가 들어오면 스킨의 문자열 캐스팅이 터지므로 빈 값으로 떨군다.
     * 공백만 남은 값도 "없음"으로 본다 — 스킨에 빈 줄이 남지 않게 하기 위함.
     */
    private function text(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }
        return trim((string) $value);
    }
}
