<?php

namespace Mublo\Packages\Shop\Block;

use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Core\Block\Renderer\SkinRendererTrait;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Packages\Shop\Helper\ProductPresenter;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\ShopConfigService;

/**
 * ProductRenderer
 *
 * 상품 블록 콘텐츠 렌더러
 *
 * content_items에서는 ID만 추출하고 현재 칼럼 도메인의 활성 상품을 DB에서 재조회합니다.
 * ID 배열과 레거시 객체 배열을 모두 지원합니다.
 *
 * 스킨에 전달되는 변수:
 * - $titleConfig: 타이틀 설정 (SkinRendererTrait에서 추출)
 * - $titlePartial: 타이틀 파셜 경로
 * - $contentConfig: 콘텐츠 설정
 * - $column: BlockColumn 엔티티
 * - $items: 상품 배열 (ProductPresenter 변환 완료)
 * - $config: content_config (show_count, sort, show_price, show_reward)
 */
class ProductRenderer implements RendererInterface
{
    use SkinRendererTrait;

    private ProductRepository $productRepository;
    private ShopConfigService $shopConfigService;

    public function __construct(
        ProductRepository $productRepository,
        ShopConfigService $shopConfigService
    ) {
        $this->productRepository = $productRepository;
        $this->shopConfigService = $shopConfigService;
    }

    protected function getSkinType(): string
    {
        return 'product';
    }

    protected function getSkinBasePath(): string
    {
        return MUBLO_PACKAGE_PATH . '/Shop/views/Block/';
    }

    public function render(BlockColumn $column): string
    {
        $contentItems = $column->getContentItems() ?? [];

        if (empty($contentItems)) {
            return $this->renderEmpty($column, '표시할 상품이 없습니다.');
        }

        $items = $this->resolveItems($contentItems, $column->getDomainId());

        if (empty($items)) {
            return $this->renderEmpty($column, '표시할 상품이 없습니다.');
        }

        $config = $column->getContentConfig() ?? [];
        $skin = $column->getContentSkin() ?: 'basic';

        return $this->renderSkin($column, $skin, [
            'items' => $items,
            'config' => $config,
        ]);
    }

    /**
     * 빈 상태 — 표시할 상품이 없어도 칸을 유지하고 점선 박스 안내(패키지 소유).
     */
    private function renderEmpty(BlockColumn $column, string $message): string
    {
        $skin = $column->getContentSkin() ?: 'basic';
        if ($this->assetManager) {
            $this->assetManager->addCss('/serve/package/Shop/views/Block/product/' . $skin . '/style.css');
        }
        $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        return '<div class="block-product-empty">'
             . '<i class="bi bi-inbox block-product-empty__icon"></i>'
             . '<span>' . $msg . '</span></div>';
    }

    /**
     * content_items → 스킨용 상품 배열 변환
     *
     * 객체 배열: content_items의 ID로 DB 조회 후 Presenter 적용
     * ID 배열(레거시): DB 조회로 폴백
     */
    private function resolveItems(array $contentItems, int $domainId): array
    {
        $first = reset($contentItems);

        // 객체 배열 또는 ID 배열에서 ID 추출
        if (is_array($first) && isset($first['id'])) {
            $goodsIds = array_map(fn($item) => (int) $item['id'], $contentItems);
        } else {
            $goodsIds = array_map('intval', $contentItems);
        }

        if (empty($goodsIds)) {
            return [];
        }

        // DB 조회 (활성 상품만)
        $rows = $this->productRepository->findByIdsForDomain($domainId, $goodsIds);

        if (empty($rows)) {
            return [];
        }

        // DB 반환 순서와 무관하게 content_items 순서를 유지한다. 조회되지 않은
        // 다른 도메인·비활성·삭제 상품은 맵에 없으므로 자연스럽게 제외된다.
        $verifiedById = [];
        foreach ($rows as $entity) {
            $item = $entity instanceof \Mublo\Packages\Shop\Entity\Product
                ? $entity->toArray()
                : (array) $entity;
            $verifiedById[(int) ($item['goods_id'] ?? 0)] = $item;
        }

        $items = [];
        foreach ($goodsIds as $goodsId) {
            if (isset($verifiedById[$goodsId])) {
                $items[] = $verifiedById[$goodsId];
            }
        }

        // 이미지도 도메인 검증을 통과해 실제 조회된 상품 ID로만 읽는다.
        $verifiedGoodsIds = array_map(fn (array $item): int => (int) $item['goods_id'], $items);
        $mainImages = $this->productRepository->getMainImages($verifiedGoodsIds);

        // ProductPresenter 적용
        $shopConfig = $this->shopConfigService->getConfig($domainId)->get('config', []);
        $presenter = new ProductPresenter($shopConfig);

        return $presenter->toList($items, $mainImages);
    }
}
