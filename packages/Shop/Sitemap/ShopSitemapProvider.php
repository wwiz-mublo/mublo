<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Sitemap;

use Mublo\Contract\Sitemap\SitemapUrlProviderInterface;
use Mublo\Packages\Shop\Helper\PublicProductCriteria;
use Mublo\Infrastructure\Database\Database;

/**
 * Shop 패키지 사이트맵 URL Provider
 *
 * 코어 SitemapController 가 ContractRegistry 를 통해 소비한다.
 * 싣는 것은 "공개 + 도메인 소속 + 실제로 내용이 있는" 상세 URL 뿐이다.
 *
 *  - 상품 상세   /shop/products/{id}[/{slug}]   활성 상품만
 *  - 카테고리    /shop/category/{code}          활성 상품이 실제로 걸린 카테고리만
 *  - 기획전 상세 /shop/exhibitions/{slug|id}    노출 기간 내 + 상품이 남아있는 기획전만
 *
 * 싣지 않는 것:
 *  - 장바구니/체크아웃/주문(/shop/cart, /shop/checkout, /shop/order*, /shop/orders)
 *  - 마이페이지성 목록(/shop/wishlist, /shop/coupons, /shop/reviews/my ...)
 *  - AJAX/JSON 엔드포인트(/shop/api/*, /shop/products/list)
 *  - 관리자 경로 일체
 * 개인 상태나 로그인이 걸린 URL 이거나, 색인 대상 콘텐츠가 아니다.
 *
 * 목록 페이지(/shop, /shop/products, /shop/exhibitions)는 도메인 메뉴로 등록되어
 * 코어층(menu_items)이 이미 싣는다. 여기서 중복 기여하지 않는다.
 *
 * 모든 쿼리는 domain_id 로 거른다(계약 2번). 경로만 반환하고 호스트를 붙이지
 * 않는다(계약 3번). 쿼리스트링은 만들지 않는다(계약 4번).
 */
class ShopSitemapProvider implements SitemapUrlProviderInterface
{
    /** 사이트맵 한 번에 실을 상품 상한 (코어 50,000 캡을 상품이 독식하지 않도록) */
    private const PRODUCT_LIMIT = 20000;

    /** @var array<string, bool> 테이블 존재 여부 캐시 */
    private array $tableCache = [];

    private readonly PublicProductCriteria $publicProducts;

    public function __construct(
        private readonly Database $db,
    ) {
        $this->publicProducts = new PublicProductCriteria($db);
    }

    /**
     * @return iterable<array{path: string, lastmod?: string, changefreq?: string, priority?: string}>
     */
    public function sitemapUrls(int $domainId): iterable
    {
        yield from $this->productUrls($domainId);
        yield from $this->categoryUrls($domainId);
        yield from $this->exhibitionUrls($domainId);
    }

    // ─────────────────────────────────────────
    // 상품 상세
    // ─────────────────────────────────────────

    /**
     * 활성 상품의 상세 URL.
     *
     * canonical 은 요청 경로(쿼리 제외)이므로, 사이트 안의 링크가 가리키는 형태와
     * 정확히 같은 형태를 실어야 한다. 상품 링크는 ProductPresenter 가
     * "슬러그가 있으면 /shop/products/{id}/{slug}, 없으면 /shop/products/{id}" 로
     * 만들므로 그 규칙을 그대로 따른다.
     *
     * 무엇을 공개 상품으로 볼 것인가(비활성·접근 제한·성인 인증 제외)는
     * PublicProductCriteria 가 단독으로 판정한다(계약 1번). 가격비교 피드도 같은
     * 판정을 쓰므로 이 조건을 여기서 따로 손보면 두 출력이 갈라진다.
     *
     * @return iterable<array<string, string>>
     */
    private function productUrls(int $domainId): iterable
    {
        if (!$this->tableExists('shop_products')) {
            return;
        }

        $rows = $this->db->select(
            "SELECT g.goods_id, g.goods_slug, g.updated_at
               FROM shop_products g
              WHERE g.domain_id = ?
                AND " . $this->publicProducts->predicate('g') . "
              ORDER BY g.goods_id
              LIMIT " . self::PRODUCT_LIMIT,
            [$domainId]
        );

        // 상한에 걸리면 반드시 남긴다. 조용히 잘리면 사이트맵이 "전부 실었다"고
        // 보이면서 실제로는 일부만 실린 상태가 되어 아무도 눈치채지 못한다.
        if (count($rows) >= self::PRODUCT_LIMIT) {
            error_log(sprintf(
                '[ShopSitemap] domain %d reached the %d product cap; additional products were omitted',
                $domainId,
                self::PRODUCT_LIMIT
            ));
        }

        foreach ($rows as $row) {
            $goodsId = (int) ($row['goods_id'] ?? 0);
            if ($goodsId <= 0) {
                continue;
            }

            $path = '/shop/products/' . $goodsId;
            $slug = $this->safeSlug((string) ($row['goods_slug'] ?? ''));
            if ($slug !== '') {
                $path .= '/' . $slug;
            }

            yield [
                'path'       => $path,
                'lastmod'    => (string) ($row['updated_at'] ?? ''),
                'changefreq' => 'weekly',
                'priority'   => '0.7',
            ];
        }
    }

    // ─────────────────────────────────────────
    // 카테고리 목록
    // ─────────────────────────────────────────

    /**
     * 활성 상품이 실제로 걸린 카테고리만 낸다(계약 5번).
     *
     * 빈 카테고리를 그대로 실으면 사이트맵의 대부분이 "상품 0건" 목록이 되어
     * 크롤 예산만 태우고 얇은 콘텐츠로 평가된다. 그래서 상품 테이블과의 조인을
     * 통과한 카테고리만 통과시킨다.
     *
     * 판정 범위는 프론트 목록과 동일하게 "자기 + 하위 카테고리"다.
     * (ProductService::getList 가 getDescendantCodes 로 하위를 포함하므로,
     *  자기 카테고리에 상품이 없어도 하위에 있으면 페이지는 비어있지 않다.)
     *
     * 접근 제한(allow_member_level>0)·성인 인증(is_adult=1) 카테고리는 공개
     * 콘텐츠가 아니므로 제외한다(계약 1번).
     *
     * @return iterable<array<string, string>>
     */
    private function categoryUrls(int $domainId): iterable
    {
        if (!$this->tableExists('shop_category_items')
            || !$this->tableExists('shop_category_tree')
            || !$this->tableExists('shop_products')
        ) {
            return;
        }

        $rows = $this->db->select(
            "SELECT ci.category_code,
                    MAX(p.updated_at) AS lastmod
               FROM shop_category_items ci
               JOIN shop_category_tree ct
                 ON ct.domain_id = ci.domain_id
                AND ct.category_code = ci.category_code
               JOIN shop_category_tree sub
                 ON sub.domain_id = ci.domain_id
                AND (sub.path_code = ct.path_code
                     OR sub.path_code LIKE CONCAT(ct.path_code, '>%'))
               JOIN shop_products p
                 ON p.domain_id = ci.domain_id
                AND p.category_code = sub.category_code
                AND p.is_active = 1
              WHERE ci.domain_id = ?
                AND ci.is_active = 1
                AND COALESCE(ci.allow_member_level, 0) = 0
                AND COALESCE(ci.is_adult, 0) = 0
              GROUP BY ci.category_code
              ORDER BY ci.category_code",
            [$domainId]
        );

        foreach ($rows as $row) {
            $code = $this->safeSegment((string) ($row['category_code'] ?? ''));
            if ($code === '') {
                continue;
            }

            yield [
                'path'       => '/shop/category/' . $code,
                'lastmod'    => (string) ($row['lastmod'] ?? ''),
                'changefreq' => 'daily',
                'priority'   => '0.6',
            ];
        }
    }

    // ─────────────────────────────────────────
    // 기획전 상세
    // ─────────────────────────────────────────

    /**
     * 지금 노출되는 기획전만 낸다.
     *
     * 노출 조건은 프론트 ExhibitionController::isVisible 과 동일하다
     * (is_active=1 + 기간 내). 여기에 더해, 연결된 상품이 하나도 남아있지 않은
     * 기획전은 결과가 빈 페이지이므로 제외한다(계약 5번). 기획전 아이템은
     * 상품 직접 지정(goods)과 카테고리 지정(category) 두 종류라 양쪽 모두를 본다.
     *
     * URL 형태는 ExhibitionUrl::detail 과 같다 — 슬러그가 있으면 슬러그, 없으면 id.
     * 관리자 메뉴 등록·프론트 링크가 모두 이 형태를 쓰므로 canonical 과 일치한다.
     *
     * @return iterable<array<string, string>>
     */
    private function exhibitionUrls(int $domainId): iterable
    {
        if (!$this->tableExists('shop_exhibitions')
            || !$this->tableExists('shop_exhibition_items')
            || !$this->tableExists('shop_category_tree')
            || !$this->tableExists('shop_products')
        ) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $rows = $this->db->select(
            "SELECT e.exhibition_id, e.slug, e.updated_at
               FROM shop_exhibitions e
              WHERE e.domain_id = ?
                AND e.is_active = 1
                AND (e.start_date IS NULL OR e.start_date <= ?)
                AND (e.end_date IS NULL OR e.end_date >= ?)
                AND EXISTS (
                    SELECT 1
                      FROM shop_exhibition_items i
                     WHERE i.exhibition_id = e.exhibition_id
                       AND (
                            (i.target_type = 'goods' AND EXISTS (
                                SELECT 1
                                  FROM shop_products p
                                 WHERE p.goods_id = i.goods_id
                                   AND p.domain_id = e.domain_id
                                   AND p.is_active = 1
                            ))
                            OR
                            (i.target_type = 'category' AND EXISTS (
                                SELECT 1
                                  FROM shop_category_tree ct
                                  JOIN shop_category_tree sub
                                    ON sub.domain_id = ct.domain_id
                                   AND (sub.path_code = ct.path_code
                                        OR sub.path_code LIKE CONCAT(ct.path_code, '>%'))
                                  JOIN shop_products p2
                                    ON p2.domain_id = ct.domain_id
                                   AND p2.category_code = sub.category_code
                                   AND p2.is_active = 1
                                 WHERE ct.domain_id = e.domain_id
                                   AND ct.category_code = i.category_code
                            ))
                       )
                )
              ORDER BY e.exhibition_id",
            [$domainId, $now, $now]
        );

        foreach ($rows as $row) {
            $exhibitionId = (int) ($row['exhibition_id'] ?? 0);
            if ($exhibitionId <= 0) {
                continue;
            }

            $slug = $this->safeSegment((string) ($row['slug'] ?? ''));
            $path = '/shop/exhibitions/' . ($slug !== '' ? $slug : $exhibitionId);

            yield [
                'path'       => $path,
                'lastmod'    => (string) ($row['updated_at'] ?? ''),
                'changefreq' => 'daily',
                'priority'   => '0.6',
            ];
        }
    }

    // ─────────────────────────────────────────
    // 보조
    // ─────────────────────────────────────────

    /**
     * 상품 슬러그를 URL 세그먼트로 쓸 수 있을 때만 돌려준다.
     *
     * 슬러그가 비었거나 경로를 깨뜨릴 문자를 담고 있으면 빈 문자열을 돌려
     * 호출부가 id 전용 형태로 폴백하게 한다. 사이트맵에 쿼리·프래그먼트를
     * 흘리지 않기 위한 방어다(계약 4번).
     */
    private function safeSlug(string $slug): string
    {
        return $this->safeSegment($slug);
    }

    /** URL 경로 한 세그먼트로 안전한 값인지 검사한다. */
    private function safeSegment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        // 경로 구분자·쿼리·프래그먼트·공백·제어문자가 섞이면 쓰지 않는다
        if (preg_match('/[\/?#\s\x00-\x1F]/u', $value)) {
            return '';
        }
        return $value;
    }

    /**
     * 테이블 존재 확인.
     *
     * Shop 이 아직 설치(마이그레이션)되지 않았거나 일부 테이블만 있는 상태에서
     * 쿼리가 터지면, 코어의 try/catch 가 그 지점부터 뒤를 통째로 버려 부분적인
     * 사이트맵이 나간다. 그래서 쿼리 전에 확인하고 없으면 조용히 건너뛴다.
     */
    private function tableExists(string $table): bool
    {
        if (isset($this->tableCache[$table])) {
            return $this->tableCache[$table];
        }

        try {
            $row = $this->db->selectOne(
                "SELECT 1 AS ok
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                  LIMIT 1",
                [$table]
            );
            $exists = $row !== null;
        } catch (\Throwable $e) {
            error_log('[ShopSitemap] table check failed for ' . $table . ': ' . $e->getMessage());
            $exists = false;
        }

        return $this->tableCache[$table] = $exists;
    }
}
