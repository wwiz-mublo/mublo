<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\PriceCompare;

use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\Shop\Helper\PublicProductCriteria;

/**
 * 가격비교 피드용 상품 조회
 *
 * 상품을 FeedItem 으로 펼치는 유일한 층이다. 채널은 이 결과만 보고, 상품 테이블
 * 구조를 알지 못한다. 반대로 여기는 어느 채널에 나갈 값인지 모른다.
 *
 * 전 상품을 훑기 때문에 한 번에 다 읽지 않고 goods_id 커서로 조각내어 읽는다.
 * 조각마다 이미지·카테고리·배송비를 묶음으로 채워 상품 수만큼 쿼리가 늘어나지
 * 않게 한다.
 *
 * 노출 판정은 PublicProductCriteria 가 하고(사이트맵과 공유), 여기서는 "지금 이
 * 가격으로 팔 수 있는가"만 더 본다. 품절이나 가격 없는 상품을 비교사에 올리면
 * 클릭이 구매로 이어지지 않아 채널 품질 지표만 깎인다.
 *
 *  - 판매가 0원 제외. 비교사에 실을 가격이 없다.
 *  - 상품 재고 0 제외.
 *
 * 옵션 재고는 보지 않는다. 옵션상품은 상품 행 재고가 NULL(미관리)이라 옵션이 모두
 * 소진돼도 이 조건을 통과한다. 판정 규칙이 단순하지 않아서다 — 미관리 값이 하나라도
 * 섞이면 상품 전체를 무제한으로 취급하는 등의 전파 규칙이
 * ProductPresenter::resolveStockQuantity 에 PHP 로 있다. 그것을 SQL 로 옮기면 같은
 * 정책이 두 벌이 되고, 한쪽만 고치는 순간 프론트 표시와 피드가 갈린다. 옵션 품절까지
 * 걸러야 하면 그 판정을 공유 가능한 형태로 먼저 뽑는 것이 순서다.
 */
final class ProductFeedQuery
{
    /** 한 조각에 읽는 상품 수 */
    private const CHUNK_SIZE = 500;

    /** 상품 1건이 실을 추가 이미지 상한 */
    private const EXTRA_IMAGE_LIMIT = 10;

    /** @var array<string, bool> 테이블 존재 여부 캐시 */
    private array $tableCache = [];

    /** @var array<string, list<string>> category_code → 카테고리명 경로 */
    private array $categoryCache = [];

    public function __construct(
        private readonly Database $db,
        private readonly PublicProductCriteria $publicProducts,
    ) {
    }

    /**
     * @param string      $baseUrl       스킴 포함 호스트 (예: https://example.com)
     * @param string|null $updatedSince  이 시각 이후 바뀐 상품만 (변경분 피드용, 'Y-m-d H:i:s')
     * @param string      $campaignKey   링크에 붙일 유입 추적 키 (빈 값이면 붙이지 않는다)
     * @return iterable<FeedItem>
     */
    public function items(
        int $domainId,
        string $baseUrl,
        ?string $updatedSince = null,
        string $campaignKey = ''
    ): iterable {
        if (!$this->tableExists('shop_products')) {
            return;
        }

        $baseUrl = rtrim($baseUrl, '/');
        $shippingFees = $this->shippingFeeMap($domainId);
        $defaultTemplateId = $this->defaultShippingTemplateId($domainId);
        $lastGoodsId = 0;

        [$changedSql, $changedParams] = $this->changedSince($updatedSince);

        while (true) {
            $rows = $this->db->select(
                "SELECT g.goods_id,
                        g.goods_name,
                        g.goods_slug,
                        g.category_code,
                        g.goods_manufacturer,
                        g.goods_origin,
                        g.goods_tags,
                        g.display_price,
                        g.origin_price,
                        g.shipping_template_id,
                        g.updated_at
                   FROM shop_products g
                  WHERE g.domain_id = ?
                    AND g.goods_id > ?
                    AND " . $this->publicProducts->predicate('g') . "
                    AND g.display_price > 0
                    AND (g.stock_quantity IS NULL OR g.stock_quantity > 0)"
                    . $changedSql . "
                  ORDER BY g.goods_id
                  LIMIT " . self::CHUNK_SIZE,
                array_merge([$domainId, $lastGoodsId], $changedParams)
            );

            if ($rows === []) {
                return;
            }

            $goodsIds = array_map(static fn(array $row): int => (int) $row['goods_id'], $rows);
            $lastGoodsId = (int) end($goodsIds);

            $images = $this->imageMap($goodsIds, $baseUrl);
            $this->warmCategoryCache($domainId, $rows);

            foreach ($rows as $row) {
                yield $this->toFeedItem(
                    $row,
                    $baseUrl,
                    $images,
                    $shippingFees,
                    $defaultTemplateId,
                    $campaignKey
                );
            }

            if (count($rows) < self::CHUNK_SIZE) {
                return;
            }
        }
    }

    /**
     * 변경분 조건.
     *
     * 상품 행의 updated_at 만 보면 이미지 교체가 빠진다. 이미지 저장은
     * shop_product_images 만 갱신하고 상품 행을 건드리지 않기 때문이다.
     * 그래서 이미지 테이블도 함께 본다.
     *
     * 옵션은 보지 않는다. 피드에 옵션 값을 싣지 않으므로 옵션이 바뀌어도
     * 내보낼 값이 달라지지 않는다.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function changedSince(?string $updatedSince): array
    {
        if ($updatedSince === null) {
            return ['', []];
        }

        if (!$this->tableExists('shop_product_images')) {
            return [' AND g.updated_at >= ?', [$updatedSince]];
        }

        return [
            " AND (g.updated_at >= ?
                   OR EXISTS (
                       SELECT 1
                         FROM shop_product_images i
                        WHERE i.goods_id = g.goods_id
                          AND i.updated_at >= ?
                   ))",
            [$updatedSince, $updatedSince],
        ];
    }

    // ─────────────────────────────────────────
    // 행 조립
    // ─────────────────────────────────────────

    /**
     * @param array<string, mixed>            $row
     * @param array<int, list<string>>        $images
     * @param array<int, int>                 $shippingFees
     */
    private function toFeedItem(
        array $row,
        string $baseUrl,
        array $images,
        array $shippingFees,
        int $defaultTemplateId,
        string $campaignKey
    ): FeedItem {
        $goodsId = (int) $row['goods_id'];
        $goodsImages = $images[$goodsId] ?? [];

        $templateId = (int) ($row['shipping_template_id'] ?? 0);
        if ($templateId <= 0) {
            $templateId = $defaultTemplateId;
        }

        $categoryCode = (string) ($row['category_code'] ?? '');
        $url = $baseUrl . $this->productPath($goodsId, (string) ($row['goods_slug'] ?? ''));

        return new FeedItem(
            goodsId: $goodsId,
            name: trim((string) ($row['goods_name'] ?? '')),
            url: $url,
            trackedUrl: $this->withCampaignKey($url, $campaignKey),
            mainImageUrl: $goodsImages[0] ?? '',
            extraImageUrls: array_slice($goodsImages, 1, self::EXTRA_IMAGE_LIMIT),
            categoryNames: $this->categoryCache[$categoryCode] ?? [],
            manufacturer: trim((string) ($row['goods_manufacturer'] ?? '')),
            origin: trim((string) ($row['goods_origin'] ?? '')),
            tags: $this->splitTags((string) ($row['goods_tags'] ?? '')),
            price: (int) ($row['display_price'] ?? 0),
            normalPrice: (int) ($row['origin_price'] ?? 0),
            shippingFee: $shippingFees[$templateId] ?? 0,
            updatedAt: (string) ($row['updated_at'] ?? ''),
        );
    }

    /**
     * 상품 상세 경로.
     *
     * 사이트 안의 링크와 같은 형태여야 한다. ProductPresenter 가
     * "슬러그가 있으면 /shop/products/{id}/{slug}, 없으면 /shop/products/{id}" 로
     * 만들므로 그 규칙을 따른다. 비교사에 canonical 과 다른 URL 을 넘기면
     * 유입이 정규 페이지로 모이지 않는다.
     */
    private function productPath(int $goodsId, string $slug): string
    {
        $path = '/shop/products/' . $goodsId;

        $slug = trim($slug);
        if ($slug !== '' && !preg_match('/[\/?#\s\x00-\x1F]/u', $slug)) {
            $path .= '/' . $slug;
        }

        return $path;
    }

    /**
     * 유입 추적 파라미터를 붙인다.
     *
     * 방문자 통계가 `k` 쿼리를 캠페인키로 집계한다. 어느 비교사에서 들어온 방문인지
     * 갈리는 유일한 근거이므로 링크로 내보내는 값에는 항상 붙인다.
     */
    private function withCampaignKey(string $url, string $campaignKey): string
    {
        if ($campaignKey === '' || $url === '') {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'k=' . rawurlencode($campaignKey);
    }

    /** @return list<string> */
    private function splitTags(string $tags): array
    {
        if (trim($tags) === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $tags));

        return array_values(array_filter($parts, static fn(string $tag): bool => $tag !== ''));
    }

    // ─────────────────────────────────────────
    // 묶음 조회
    // ─────────────────────────────────────────

    /**
     * 상품별 이미지 URL 목록. 대표 이미지가 앞에 온다.
     *
     * @param list<int> $goodsIds
     * @return array<int, list<string>>
     */
    private function imageMap(array $goodsIds, string $baseUrl): array
    {
        if ($goodsIds === [] || !$this->tableExists('shop_product_images')) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($goodsIds), '?'));

        $rows = $this->db->select(
            "SELECT goods_id, image_url
               FROM shop_product_images
              WHERE goods_id IN ({$placeholders})
              ORDER BY goods_id, is_main DESC, sort_order, image_id",
            $goodsIds
        );

        $map = [];
        foreach ($rows as $row) {
            $url = $this->absoluteUrl((string) ($row['image_url'] ?? ''), $baseUrl);
            if ($url === '') {
                continue;
            }
            $map[(int) $row['goods_id']][] = $url;
        }

        return $map;
    }

    /**
     * 조각에 등장한 카테고리의 이름 경로를 채운다.
     *
     * shop_category_tree.path_name 이 "이름>이름>이름" 형태로 경로를 들고 있어
     * 계층을 다시 타고 올라갈 필요가 없다.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function warmCategoryCache(int $domainId, array $rows): void
    {
        if (!$this->tableExists('shop_category_tree')) {
            return;
        }

        $codes = [];
        foreach ($rows as $row) {
            $code = (string) ($row['category_code'] ?? '');
            if ($code !== '' && !array_key_exists($code, $this->categoryCache)) {
                $codes[$code] = true;
            }
        }

        if ($codes === []) {
            return;
        }

        $codes = array_keys($codes);
        $placeholders = implode(',', array_fill(0, count($codes), '?'));

        $treeRows = $this->db->select(
            "SELECT category_code, path_name
               FROM shop_category_tree
              WHERE domain_id = ?
                AND category_code IN ({$placeholders})",
            array_merge([$domainId], $codes)
        );

        foreach ($treeRows as $row) {
            $names = array_map('trim', explode('>', (string) ($row['path_name'] ?? '')));
            $this->categoryCache[(string) $row['category_code']] = array_values(
                array_filter($names, static fn(string $name): bool => $name !== '')
            );
        }

        // 트리에 없는 코드는 다시 조회하지 않도록 빈 값으로 못박는다
        foreach ($codes as $code) {
            $this->categoryCache[$code] ??= [];
        }
    }

    /**
     * 배송 템플릿별 배송비.
     *
     * 조건부 무료(COND)는 기본 배송비로 낸다. 비교사 규격의 배송비 칸은 금액 하나라
     * "3만원 이상 무료" 같은 조건을 담을 자리가 없고, 낮게 신고하는 쪽이 실제 결제
     * 금액과 어긋나 분쟁이 된다.
     *
     * @return array<int, int>
     */
    private function shippingFeeMap(int $domainId): array
    {
        if (!$this->tableExists('shop_shipping_templates')) {
            // 배송비를 0으로 내보내면 비교사에는 "무료배송"으로 실린다. 실제 결제
            // 금액과 어긋나는 값이 외부로 나가는 것이므로 조용히 넘기지 않는다.
            error_log('[Shop] 가격비교 — shop_shipping_templates 가 없어 배송비를 0으로 내보냅니다.');

            return [];
        }

        $rows = $this->db->select(
            "SELECT shipping_id, shipping_method, basic_cost
               FROM shop_shipping_templates
              WHERE domain_id = ?",
            [$domainId]
        );

        $map = [];
        foreach ($rows as $row) {
            $method = strtoupper((string) ($row['shipping_method'] ?? ''));
            $map[(int) $row['shipping_id']] = $method === 'FREE'
                ? 0
                : max(0, (int) ($row['basic_cost'] ?? 0));
        }

        return $map;
    }

    /** 상품에 템플릿이 지정되지 않았을 때 적용되는 도메인 기본 배송 템플릿 */
    private function defaultShippingTemplateId(int $domainId): int
    {
        if (!$this->tableExists('shop_config')) {
            return 0;
        }

        try {
            $row = $this->db->selectOne(
                "SELECT default_shipping_template_id
                   FROM shop_config
                  WHERE domain_id = ?
                  LIMIT 1",
                [$domainId]
            );
        } catch (\Throwable $e) {
            error_log('[Shop] 가격비교 — 기본 배송 템플릿 조회 실패: ' . $e->getMessage());

            return 0;
        }

        return (int) ($row['default_shipping_template_id'] ?? 0);
    }

    // ─────────────────────────────────────────
    // 보조
    // ─────────────────────────────────────────

    /** 저장된 경로를 비교사가 바로 받아갈 수 있는 절대 URL로 만든다. */
    private function absoluteUrl(string $url, string $baseUrl): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return $baseUrl . '/' . ltrim($url, '/');
    }

    /**
     * 테이블 존재 확인.
     *
     * Shop 이 일부 테이블만 있는 상태에서 쿼리가 터지면 피드가 500 으로 나가고,
     * 비교사는 그것을 "피드 없음"으로 취급한다. 쿼리 전에 확인한다.
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
            error_log('[Shop] 가격비교 — 테이블 확인 실패 ' . $table . ': ' . $e->getMessage());
            $exists = false;
        }

        return $this->tableCache[$table] = $exists;
    }
}
