<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Helper;

use Mublo\Packages\Shop\Enum\DiscountType;
use Mublo\Packages\Shop\Enum\RewardType;
use Mublo\Packages\Shop\Service\PriceCalculator;

/**
 * ProductPresenter
 *
 * DB 원본 데이터를 스킨 개발자가 바로 사용할 수 있는 표시용 데이터로 변환.
 * ArticlePresenter 패턴 준수 — 저비용 변환만 수행, 보안 자동 적용.
 *
 * 사용:
 * ```php
 * $presenter = new ProductPresenter($shopConfig);
 *
 * // 목록 (Controller에서)
 * $products = $presenter->toList($items, $mainImages, $reviewStats, $wishlistCounts);
 *
 * // 상세 (Controller에서)
 * $product = $presenter->toView($item);
 * ```
 *
 * 스킨에서:
 * ```php
 * <a href="<?= $item['url'] ?>"><?= $item['goods_name_safe'] ?></a>
 * <strong><?= $item['sales_price_formatted'] ?>원</strong>
 * <?php if ($item['has_discount']): ?>
 *     <del><?= $item['display_price_formatted'] ?>원</del>
 *     <span class="discount"><?= $item['discount_percent'] ?>%</span>
 * <?php endif; ?>
 * <?php if ($item['has_reward']): ?>
 *     <span class="point"><?= $item['point_amount_formatted'] ?>P 적립</span>
 * <?php endif; ?>
 * ```
 */
class ProductPresenter
{
    private array $shopConfig;
    private PriceCalculator $priceCalculator;

    /** 보고 있는 회원의 등급 ID — 등급별 할인·적립을 표시가에 반영한다 (비회원이면 null) */
    private ?int $levelId;

    public function __construct(array $shopConfig = [], ?PriceCalculator $priceCalculator = null, ?int $levelId = null)
    {
        $this->shopConfig = $shopConfig;
        $this->priceCalculator = $priceCalculator ?? new PriceCalculator();
        $this->levelId = $levelId;
    }

    /* =========================================================
     * Public API
     * ========================================================= */

    /**
     * 목록용 변환
     *
     * @param array $items 상품 배열 목록 (toArray() 결과)
     * @param array $mainImages 메인 이미지 맵 [goods_id => ['image_url'=>..., 'thumbnail_url'=>...]]
     * @param array $reviewStats 구매후기 통계 맵 [goods_id => ['count'=>N, 'avg_rating'=>N.N]]
     * @param array $wishlistCounts 찜 수 맵 [goods_id => N]
     * @return array 변환된 상품 배열 목록
     */
    public function toList(array $items, array $mainImages = [], array $reviewStats = [], array $wishlistCounts = []): array
    {
        return array_map(function (array $item) use ($mainImages, $reviewStats, $wishlistCounts) {
            $transformed = $this->transform($item);

            $goodsId = $item['goods_id'] ?? 0;

            // 메인 이미지 매핑
            $img = $mainImages[$goodsId] ?? null;
            $transformed['main_image_url'] = $img['image_url'] ?? null;
            $transformed['main_thumbnail_url'] = $img['thumbnail_url'] ?? $img['image_url'] ?? null;

            // 구매후기 통계 매핑
            $review = $reviewStats[$goodsId] ?? null;
            $transformed['review_count'] = $review['count'] ?? 0;
            $transformed['review_count_formatted'] = number_format($review['count'] ?? 0);
            $transformed['average_rating'] = (float) ($review['avg_rating'] ?? 0);
            $transformed['average_rating_formatted'] = $this->formatRating($review['avg_rating'] ?? 0);
            $transformed['has_reviews'] = ($review['count'] ?? 0) > 0;

            // 찜 수 매핑
            $transformed['wishlist_count'] = (int) ($wishlistCounts[$goodsId] ?? 0);
            $transformed['wishlist_count_formatted'] = number_format($wishlistCounts[$goodsId] ?? 0);

            return $transformed;
        }, $items);
    }

    /**
     * 상세용 변환
     *
     * toList 필드 + 상세 전용 필드 (이미지 목록, 옵션, 태그 배열 등)
     *
     * @param array $item 상품 배열 (images, options, combos, details 포함)
     * @param array $reviewStats 구매후기 통계 ['count'=>N, 'avg_rating'=>N.N]
     * @param int $wishlistCount 찜 수
     * @param int $inquiryCount 상품문의 수 (노출 기준)
     * @return array 변환된 상품 배열
     */
    public function toView(array $item, array $reviewStats = [], int $wishlistCount = 0, int $inquiryCount = 0): array
    {
        $transformed = $this->transform($item);

        // 구매후기 통계
        $transformed['review_count'] = $reviewStats['count'] ?? 0;
        $transformed['review_count_formatted'] = number_format($reviewStats['count'] ?? 0);
        $transformed['average_rating'] = (float) ($reviewStats['avg_rating'] ?? 0);
        $transformed['average_rating_formatted'] = $this->formatRating($reviewStats['avg_rating'] ?? 0);
        $transformed['has_reviews'] = ($reviewStats['count'] ?? 0) > 0;

        // 상품문의 통계
        $transformed['inquiry_count'] = $inquiryCount;
        $transformed['inquiry_count_formatted'] = number_format($inquiryCount);
        $transformed['has_inquiries'] = $inquiryCount > 0;

        // 찜 수
        $transformed['wishlist_count'] = $wishlistCount;
        $transformed['wishlist_count_formatted'] = number_format($wishlistCount);

        // 이미지 목록 (detail에서는 전체 이미지)
        $images = is_array($item['images'] ?? null) ? $item['images'] : [];
        $transformed['images'] = $this->buildImageList($images);
        $transformed['main_image_url'] = $this->extractMainImage($images, 'image_url');
        $transformed['main_thumbnail_url'] = $this->extractMainImage($images, 'thumbnail_url')
            ?? $this->extractMainImage($images, 'image_url');

        // 태그 배열
        $transformed['tags_array'] = $this->parseTags($item['goods_tags'] ?? '');

        // 상세정보 안전 처리
        $transformed['details'] = $this->buildDetailList(
            is_array($item['details'] ?? null) ? $item['details'] : []
        );

        // 옵션/조합은 프론트 JS 공개 키만 전달한다. (장바구니와 같은 형태 — ProductOptionPresenter)
        $transformed['options'] = ProductOptionPresenter::toOptionList(
            is_array($item['options'] ?? null) ? $item['options'] : []
        );
        $transformed['combos'] = ProductOptionPresenter::toComboList(
            is_array($item['combos'] ?? null) ? $item['combos'] : []
        );

        // 구매후기 적립금 표시
        $rewardReview = (int) ($item['reward_review'] ?? 0);
        $transformed['reward_review'] = $rewardReview;
        $transformed['reward_review_formatted'] = number_format($rewardReview);
        $transformed['has_reward_review'] = $rewardReview > 0;

        return $transformed;
    }

    /* =========================================================
     * 변환 로직
     * ========================================================= */

    /**
     * 공통 변환 (목록/상세 공용)
     *
     * 변환 필드 목록:
     * ─────────────────────────────────────────
     * [보안]
     *   goods_name_safe      상품명 (HTML escape)
     *   goods_origin_safe    원산지 (HTML escape)
     *   goods_manufacturer_safe 제조사 (HTML escape)
     *
     * [URL]
     *   url                  /shop/products/{id}/{slug}
     *
     * [가격]
     *   origin_price_formatted   원가 포맷 (number_format)
     *   display_price_formatted  판매 표시가 포맷
     *   sales_price              할인 적용 최종가 (원시값)
     *   sales_price_formatted    할인 적용 최종가 포맷
     *   discount_amount          할인 금액
     *   discount_amount_formatted 할인 금액 포맷
     *   discount_percent         할인율 (%)
     *   has_discount             할인 적용 여부
     *   point_amount             적립금
     *   point_amount_formatted   적립금 포맷
     *   has_reward               적립금 있는지 여부
     *
     * [재고/상태]
     *   is_soldout           품절 여부
     *   stock_label          재고 라벨 (null/품절/재고 N개)
     *
     * [배지]
     *   is_new               신규 상품 여부
     *   badges               배지 배열 ['new','sale','soldout',custom...]
     *
     * [날짜] (ArticlePresenter 7포맷)
     *   date_raw             2026-02-10 14:30:00
     *   date_full            2026-02-10 14:30
     *   date_short           2026-02-10
     *   date_compact         02-10
     *   date_time            14:30
     *   date_relative        방금 전 / N분 전 / N시간 전 / 어제 / 02-10
     *   date_ymd             26.02.10
     *
     * [통계]
     *   hit_formatted        조회수 포맷
     *
     * [분류/표시]
     *   allowed_coupon_label 쿠폰 사용가 라벨 (쿠폰적용가능 / null)
     *   option_mode_label    옵션 모드 라벨 (단독/조합/null)
     * ─────────────────────────────────────────
     */
    private function transform(array $item): array
    {
        // Entity/DB의 내부 필드가 스킨 및 목록 JSON에 자동 노출되지 않도록 허용 목록만 유지한다.
        $item = array_intersect_key($item, array_flip([
            'goods_id', 'category_code', 'category_code_extra', 'item_code',
            'goods_name', 'goods_slug', 'goods_origin', 'goods_manufacturer',
            'goods_code', 'goods_badge', 'goods_icon', 'goods_filter', 'goods_tags',
            'origin_price', 'display_price', 'discount_type', 'discount_value',
            'reward_type', 'reward_value', 'reward_review', 'allowed_coupon',
            'stock_quantity', 'option_mode', 'shipping_apply_type', 'hit', 'is_active',
            'created_at', 'updated_at', 'sold_count', 'review_count', 'inquiry_count',
            'rating_avg', 'wishlist_count', 'images', 'options', 'combos', 'details',
        ]));

        // === 보안 (HTML escape) ===
        $item['goods_name_safe'] = htmlspecialchars($item['goods_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $item['goods_origin_safe'] = htmlspecialchars($item['goods_origin'] ?? '', ENT_QUOTES, 'UTF-8');
        $item['goods_manufacturer_safe'] = htmlspecialchars($item['goods_manufacturer'] ?? '', ENT_QUOTES, 'UTF-8');

        // === URL ===
        $goodsId = $item['goods_id'] ?? 0;
        $slug = $item['goods_slug'] ?? '';
        $item['url'] = $slug !== ''
            ? "/shop/products/{$goodsId}/{$slug}"
            : "/shop/products/{$goodsId}";

        // === 가격 ===
        $item = array_merge($item, $this->buildPriceFields($item));

        // === 날짜 (7포맷) ===
        $createdAt = $item['created_at'] ?? '';
        $item = array_merge($item, $this->buildDateFields($createdAt));

        // === 재고/상태 ===
        // 옵션 모드에 따라 재고 판단 기준이 다름:
        // NONE: shop_products.stock_quantity
        // SINGLE: 옵션 값들의 stock_quantity 합계
        // COMBINATION: 조합들의 stock_quantity 합계
        $optionMode = $item['option_mode'] ?? 'NONE';
        $stockQty = $this->resolveStockQuantity($item, $optionMode);
        $item['is_soldout'] = ($stockQty !== null && (int) $stockQty <= 0);
        $item['stock_label'] = $this->buildStockLabel($stockQty);

        // === 배지 ===
        $item['is_new'] = $this->isNew($createdAt);
        $item['badges'] = $this->buildBadges($item);

        // === 통계 포맷 ===
        $item['hit_formatted'] = number_format((int) ($item['hit'] ?? 0));

        // === 분류/표시 ===
        $item['allowed_coupon_label'] = ($item['allowed_coupon'] ?? 1) ? '쿠폰적용가능' : null;

        $optionMode = $item['option_mode'] ?? 'NONE';
        $item['option_mode_label'] = match ($optionMode) {
            'SINGLE' => '단독옵션',
            'COMBINATION' => '조합옵션',
            default => null,
        };

        return $item;
    }

    /* =========================================================
     * 가격
     * ========================================================= */

    /**
     * 상품 행의 등급별 설정 컬럼 해석 (DB에는 JSON 문자열로 저장된다)
     */
    private function levelSettings(array $item, string $key): array
    {
        $raw = $item[$key] ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * 가격 필드 생성 (PriceCalculator 활용)
     */
    private function buildPriceFields(array $item): array
    {
        $originPrice = (int) ($item['origin_price'] ?? 0);
        $displayPrice = (int) ($item['display_price'] ?? 0);
        $discountType = DiscountType::tryFrom($item['discount_type'] ?? 'NONE') ?? DiscountType::NONE;
        $discountValue = (float) ($item['discount_value'] ?? 0);
        $rewardType = RewardType::tryFrom($item['reward_type'] ?? 'NONE') ?? RewardType::NONE;
        $rewardValue = (float) ($item['reward_value'] ?? 0);

        // 할인 계산
        $priceResult = $this->priceCalculator->calculateSalesPrice(
            $displayPrice,
            $discountType,
            $discountValue,
            $this->shopConfig,
            $this->levelId,
            $this->levelSettings($item, 'discount_level_settings')
        );

        $salesPrice = $priceResult['sales_price'];
        $discountAmount = $priceResult['discount_amount'];
        $discountPercent = $priceResult['discount_percent'];
        $hasDiscount = $discountAmount > 0;

        // 적립금 계산
        $rewardResult = $this->priceCalculator->calculateRewardPoints(
            $salesPrice,
            $rewardType,
            $rewardValue,
            $this->shopConfig,
            $this->levelId,
            $this->levelSettings($item, 'reward_level_settings')
        );

        return [
            'origin_price_formatted' => number_format($originPrice),
            'display_price_formatted' => number_format($displayPrice),
            'sales_price' => $salesPrice,
            'sales_price_formatted' => number_format($salesPrice),
            'discount_amount' => $discountAmount,
            'discount_amount_formatted' => number_format($discountAmount),
            'discount_percent' => $discountPercent,
            'has_discount' => $hasDiscount,
            'point_amount' => $rewardResult['point_amount'],
            'point_amount_formatted' => number_format($rewardResult['point_amount']),
            'has_reward' => $rewardResult['point_amount'] > 0,
        ];
    }

    /* =========================================================
     * 날짜 (ArticlePresenter 7포맷)
     * ========================================================= */

    /**
     * 날짜 필드 생성 — 7가지 포맷
     */
    private function buildDateFields(string $dateStr): array
    {
        $empty = [
            'date_raw' => '',
            'date_full' => '',
            'date_short' => '',
            'date_compact' => '',
            'date_time' => '',
            'date_relative' => '',
            'date_ymd' => '',
        ];

        if ($dateStr === '') {
            return $empty;
        }

        try {
            $dt = new \DateTimeImmutable($dateStr);
        } catch (\Exception) {
            return $empty;
        }

        return [
            'date_raw' => $dt->format('Y-m-d H:i:s'),
            'date_full' => $dt->format('Y-m-d H:i'),
            'date_short' => $dt->format('Y-m-d'),
            'date_compact' => $dt->format('m-d'),
            'date_time' => $dt->format('H:i'),
            'date_relative' => $this->relativeTime($dt),
            'date_ymd' => $dt->format('y.m.d'),
        ];
    }

    /**
     * 상대시간 계산 (한국어)
     */
    private function relativeTime(\DateTimeImmutable $dt): string
    {
        $now = new \DateTimeImmutable();
        $diff = $now->getTimestamp() - $dt->getTimestamp();

        if ($diff < 0) {
            return $dt->format('m-d');
        }
        if ($diff < 60) {
            return '방금 전';
        }
        if ($diff < 3600) {
            return (int) ($diff / 60) . '분 전';
        }
        if ($diff < 86400) {
            return (int) ($diff / 3600) . '시간 전';
        }
        if ($diff < 172800) {
            return '어제';
        }

        return $dt->format('m-d');
    }

    /* =========================================================
     * 재고
     * ========================================================= */

    /**
     * 옵션 모드별 실제 재고 수량 산출
     *
     * NONE: shop_products.stock_quantity 사용
     * SINGLE: 옵션 값들(option_values)의 stock_quantity 합계
     * COMBINATION: 조합(combos)의 stock_quantity 합계
     *
     * @return int|null null=재고 미관리
     */
    private function resolveStockQuantity(array $item, string $optionMode): ?int
    {
        if ($optionMode === 'SINGLE') {
            $options = $item['options'] ?? [];
            if (empty($options)) {
                return $this->nullableStock($item['stock_quantity'] ?? null);
            }
            // 미관리(NULL) 값 = 무제한 재고. 무제한 값이 하나라도 있으면 상품 전체를
            // 미관리로 취급한다(합산 불가·품절 아님). 모두 관리값일 때만 합산한다.
            $hasManaged = false;
            $hasUnmanaged = false;
            $total = 0;
            foreach ($options as $opt) {
                foreach ($opt['values'] ?? [] as $val) {
                    if (isset($val['is_active']) && !$val['is_active']) continue;
                    $stock = $val['stock_quantity'] ?? null;
                    if ($stock === null || $stock === '') {
                        $hasUnmanaged = true;
                    } else {
                        $hasManaged = true;
                        $total += (int) $stock;
                    }
                }
            }
            if ($hasUnmanaged) {
                return null;
            }
            return $hasManaged ? $total : null;
        }

        if ($optionMode === 'COMBINATION') {
            $combos = $item['combos'] ?? [];
            if (empty($combos)) {
                return $this->nullableStock($item['stock_quantity'] ?? null);
            }
            // 미관리(NULL) 조합 = 무제한 재고. 무제한 조합이 하나라도 있으면 상품 전체를
            // 미관리로 취급한다(합산 불가·품절 아님). 모두 관리값일 때만 합산한다.
            $hasManaged = false;
            $hasUnmanaged = false;
            $total = 0;
            foreach ($combos as $combo) {
                if (isset($combo['is_active']) && !$combo['is_active']) continue;
                $stock = $combo['stock_quantity'] ?? null;
                if ($stock === null || $stock === '') {
                    $hasUnmanaged = true;
                } else {
                    $hasManaged = true;
                    $total += (int) $stock;
                }
            }
            if ($hasUnmanaged) {
                return null;
            }
            return $hasManaged ? $total : null;
        }

        // NONE 모드
        return $this->nullableStock($item['stock_quantity'] ?? null);
    }

    /**
     * stock_quantity 값을 nullable int로 변환
     * NULL/빈문자열 → null (미관리), 숫자 → int
     */
    private function nullableStock(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    /**
     * 재고 라벨 생성
     *
     * NULL → null (미관리), 0 → '품절', >0 → '재고 N개'
     */
    private function buildStockLabel(?int $stockQty): ?string
    {
        if ($stockQty === null) {
            return null;
        }
        if ($stockQty <= 0) {
            return '품절';
        }
        if ($stockQty <= $this->config('low_stock_threshold', 5)) {
            return "재고 {$stockQty}개";
        }

        return null;
    }

    /* =========================================================
     * 배지
     * ========================================================= */

    /**
     * 배지 배열 생성
     *
     * 순서: new → sale → soldout → custom(goods_badge)
     *
     * @return string[]
     */
    private function buildBadges(array $item): array
    {
        $badges = [];

        if ($item['is_new'] ?? false) {
            $badges[] = 'new';
        }
        if ($item['has_discount'] ?? false) {
            $badges[] = 'sale';
        }
        if ($item['is_soldout'] ?? false) {
            $badges[] = 'soldout';
        }

        // goods_badge: 시스템 배지(new/sale/soldout)와 중복되면 스킵
        if (!empty($item['goods_badge'])) {
            $customBadge = strtoupper(trim($item['goods_badge']));
            $systemBadges = ['NEW', 'SALE', 'SOLDOUT'];
            if (!in_array($customBadge, $systemBadges, true)) {
                $badges[] = htmlspecialchars($customBadge, ENT_QUOTES, 'UTF-8');
            }
        }

        return $badges;
    }

    /**
     * 신규 상품 여부
     *
     * shopConfig의 new_product_threshold(초) 사용, 기본 604800(7일)
     */
    private function isNew(string $createdAt): bool
    {
        if ($createdAt === '') {
            return false;
        }

        try {
            $dt = new \DateTimeImmutable($createdAt);
        } catch (\Exception) {
            return false;
        }

        $threshold = (int) ($this->shopConfig['new_product_threshold'] ?? 604800);
        $now = new \DateTimeImmutable();
        $diff = $now->getTimestamp() - $dt->getTimestamp();

        return $diff >= 0 && $diff < $threshold;
    }

    /* =========================================================
     * 이미지
     * ========================================================= */

    /**
     * 이미지 목록 안전 처리
     */
    private function buildImageList(array $images): array
    {
        return array_map(function (array $img) {
            return [
                'image_id' => $img['image_id'] ?? null,
                'image_url' => $img['image_url'] ?? '',
                'thumbnail_url' => $img['thumbnail_url'] ?? $img['image_url'] ?? '',
                'webp_url' => $img['webp_url'] ?? null,
                'is_main' => (bool) ($img['is_main'] ?? false),
                'sort_order' => (int) ($img['sort_order'] ?? 0),
            ];
        }, $images);
    }

    /** @return list<array<string, mixed>> */
    private function buildDetailList(array $details): array
    {
        return array_values(array_map(static fn(array $detail): array => [
            'detail_id' => (int) ($detail['detail_id'] ?? 0),
            'detail_type' => (string) ($detail['detail_type'] ?? ''),
            'detail_value' => (string) ($detail['detail_value'] ?? ''),
            'lang_code' => (string) ($detail['lang_code'] ?? 'ko'),
            'sort_order' => (int) ($detail['sort_order'] ?? 0),
        ], $details));
    }

    /**
     * 메인 이미지 추출
     */
    private function extractMainImage(array $images, string $field): ?string
    {
        // is_main=1인 이미지 우선
        foreach ($images as $img) {
            if (!empty($img['is_main']) && !empty($img[$field])) {
                return $img[$field];
            }
        }
        // 없으면 첫 번째
        return $images[0][$field] ?? null;
    }

    /* =========================================================
     * 유틸
     * ========================================================= */

    /**
     * 평점 포맷 (소수점 1자리)
     */
    private function formatRating(float|int|string $rating): string
    {
        $val = (float) $rating;
        if ($val <= 0) {
            return '0';
        }

        return number_format($val, 1);
    }

    /**
     * 태그 문자열 → 배열 (쉼표 구분)
     */
    private function parseTags(string $tags): array
    {
        if ($tags === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $tags)),
            fn(string $tag) => $tag !== ''
        ));
    }

    /**
     * 설정 접근
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->shopConfig[$key] ?? $default;
    }
}
