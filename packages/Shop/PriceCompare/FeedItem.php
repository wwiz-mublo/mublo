<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\PriceCompare;

/**
 * 가격비교 피드 한 행 (채널 무관 정규 형태)
 *
 * 상품을 읽는 층은 채널을 모르고, 채널은 shop_products 를 모른다.
 * 채널이 늘어나도 이 값 객체와 상품 조회는 바뀌지 않는다.
 *
 * 여기 없는 필드가 필요해지면(모델명·GTIN 등) 상품 테이블에 없는 값이라는 뜻이다.
 * 그때는 이 객체에 필드를 늘리고 채널이 그것을 소비하면 된다.
 */
final readonly class FeedItem
{
    /**
     * $url 은 사이트가 canonical 로 선언하는 형태, $trackedUrl 은 거기에 유입 추적
     * 파라미터(`?k=`)를 붙인 형태다. 링크로 내보낼 값은 추적 URL 이고, 정규 URL 은
     * 그것과 대조할 필요가 있을 때 쓴다.
     *
     * @param list<string> $extraImageUrls 추가 이미지 (절대 URL)
     * @param list<string> $categoryNames  상위 → 하위 순 카테고리명
     * @param list<string> $tags           검색 태그
     */
    public function __construct(
        public int $goodsId,
        public string $name,
        public string $url,
        public string $trackedUrl,
        public string $mainImageUrl,
        public array $extraImageUrls,
        public array $categoryNames,
        public string $manufacturer,
        public string $origin,
        public array $tags,
        public int $price,
        public int $normalPrice,
        public int $shippingFee,
        public string $updatedAt,
    ) {
    }

    /** 상위 → 하위 순 카테고리명 중 depth 번째 (1부터). 없으면 빈 문자열. */
    public function categoryName(int $depth): string
    {
        return $this->categoryNames[$depth - 1] ?? '';
    }
}
