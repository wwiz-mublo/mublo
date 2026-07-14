<?php

namespace Mublo\Packages\Shop\Block;

use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Core\Block\Renderer\SkinRendererTrait;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Packages\Shop\Entity\Product;
use Mublo\Packages\Shop\Helper\ProductPresenter;
use Mublo\Packages\Shop\Repository\CategoryRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\ShopConfigService;

/**
 * ProductAutoRenderer
 *
 * 상품 자동 진열 블록 콘텐츠 렌더러
 *
 * 수동으로 상품을 고르는 ProductRenderer와 달리, 관리자가 선택한
 * "정렬 기준 + 표시 개수 + 카테고리 제한"으로 상품을 자동 조회해 출력합니다.
 * (content_items를 쓰지 않고 content_config만 사용)
 *
 * content_config 키:
 * - sort_order:    정렬 기준 (newest | popular | price_asc | price_desc)
 * - display_count: 표시 개수 (구버전 호환: 없으면 pc_count/mo_count 중 큰 값)
 * - category_code: 카테고리 제한 (빈 값이면 전체)
 *
 * 스킨에 전달되는 변수는 ProductRenderer와 동일 (product/basic 마크업 재사용)
 */
class ProductAutoRenderer implements RendererInterface
{
    use SkinRendererTrait;

    private ProductRepository $productRepository;
    private CategoryRepository $categoryRepository;
    private ShopConfigService $shopConfigService;

    public function __construct(
        ProductRepository $productRepository,
        CategoryRepository $categoryRepository,
        ShopConfigService $shopConfigService
    ) {
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->shopConfigService = $shopConfigService;
    }

    protected function getSkinType(): string
    {
        return 'product_auto';
    }

    protected function getSkinBasePath(): string
    {
        return MUBLO_PACKAGE_PATH . '/Shop/views/Block/';
    }

    public function render(BlockColumn $column): string
    {
        $config = $column->getContentConfig() ?? [];
        $domainId = $column->getDomainId();

        // 표시 개수: 자동 진열 설정의 display_count 우선,
        // (구버전 호환) 없으면 PC/MO 출력갯수 중 큰 값, 그래도 없으면 4
        $count = (int) ($config['display_count'] ?? 0);
        if ($count < 1) {
            $count = max(
                (int) ($config['pc_count'] ?? 0),
                (int) ($config['mo_count'] ?? 0)
            );
        }
        if ($count < 1) {
            $count = 4;
        }

        $items = $this->fetchItems($domainId, $config, $count);

        if (empty($items)) {
            return $this->renderEmpty($column, '표시할 상품이 없습니다.');
        }

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
            $this->assetManager->addCss('/serve/package/Shop/views/Block/product_auto/' . $skin . '/style.css');
        }
        $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        return '<div class="block-product-empty">'
             . '<i class="bi bi-inbox block-product-empty__icon"></i>'
             . '<span>' . $msg . '</span></div>';
    }

    /**
     * 카테고리 + 진열 순서로 상품을 자동 조회해 스킨용 배열로 변환
     */
    private function fetchItems(int $domainId, array $config, int $count): array
    {
        $filters = [
            'sort' => $config['sort_order'] ?? 'newest',
            'is_active' => 1,
        ];

        // 카테고리 필터 (선택 카테고리 + 하위 카테고리 모두 포함)
        $categoryCode = trim((string) ($config['category_code'] ?? ''));
        if ($categoryCode !== '') {
            $filters['category_codes'] = $this->categoryRepository->getDescendantCodes($domainId, $categoryCode);
        }

        $result = $this->productRepository->getList($domainId, $filters, 1, $count);
        $rows = $result['items'] ?? [];

        if (empty($rows)) {
            return [];
        }

        // Entity → array 변환 + 대표 이미지 배치 로드
        $items = [];
        $goodsIds = [];
        foreach ($rows as $entity) {
            if ($entity instanceof Product) {
                $items[] = $entity->toArray();
                $goodsIds[] = $entity->getGoodsId();
            } else {
                $items[] = (array) $entity;
                $goodsIds[] = (int) ($entity['goods_id'] ?? 0);
            }
        }

        $mainImages = $this->productRepository->getMainImages($goodsIds);

        // ProductPresenter 적용 (가격/뱃지/URL 등 가공)
        $shopConfig = $this->shopConfigService->getConfig($domainId)->get('config', []);
        $presenter = new ProductPresenter($shopConfig);

        return $presenter->toList($items, $mainImages);
    }
}
