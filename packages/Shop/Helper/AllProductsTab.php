<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Helper;

/**
 * 상품 목록 "전체" 탭 보이기 설정 (shop_config.show_all_products_tab)
 *
 * 끄면 카테고리 메뉴에서 "전체" 링크를 숨기고, 카테고리·검색어 없이 들어온
 * 목록 요청(/shop, /shop/products)은 첫 번째 대분류로 보낸다.
 * 검색어가 있으면 검색 결과이므로 그대로 전체 상품에서 찾는다.
 */
final class AllProductsTab
{
    /** 설정값이 없으면(마이그레이션 전) 지금처럼 보인다 */
    public static function isShown(array $shopConfig): bool
    {
        return !array_key_exists('show_all_products_tab', $shopConfig)
            || !empty($shopConfig['show_all_products_tab']);
    }

    /**
     * 첫 카테고리로 보낼 주소. 보낼 필요가 없으면 null.
     *
     * @param array $categoryTree 표준 카테고리 트리 (활성 카테고리만, code 키)
     * @param array $query        현재 쿼리스트링 (정렬 등은 유지, page 는 버린다)
     */
    public static function redirectUrl(
        array $shopConfig,
        array $categoryTree,
        string $categoryCode,
        string $keyword,
        array $query = []
    ): ?string {
        if (self::isShown($shopConfig) || $categoryCode !== '' || $keyword !== '') {
            return null;
        }

        foreach ($categoryTree as $root) {
            $code = trim((string) ($root['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            unset($query['category_code'], $query['keyword'], $query['page']);
            return '/shop/category/' . rawurlencode($code)
                . ($query !== [] ? '?' . http_build_query($query) : '');
        }

        return null;
    }
}
