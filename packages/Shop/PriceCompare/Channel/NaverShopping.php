<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\PriceCompare\Channel;

use Mublo\Packages\Shop\Contract\PriceCompare\PriceCompareChannelInterface;
use Mublo\Packages\Shop\PriceCompare\FeedItem;
use Mublo\Packages\Shop\PriceCompare\FeedValue;

/**
 * 네이버 쇼핑 EP (기본 제공)
 *
 * Shop 이 예시로 싣는 구현이다. 컬럼 구성은 규격에서 흔히 쓰이는 최소 집합만 담았고,
 * 선택 컬럼(event_words, product_flag, naver_product_id 등)은 필요한 쪽이 이 클래스를
 * 복사해 자기 채널로 등록하면 된다.
 *
 * 상품 하나가 여러 줄로 나가지 않는다. 옵션 조합까지 펼쳐 올리는 방식은 노출은 늘지만
 * 중복 판정과 재고 동기화를 함께 감당해야 해서, 기본 제공은 대표가 한 줄로 둔다.
 */
final class NaverShopping implements PriceCompareChannelInterface
{
    public function code(): string
    {
        return 'naver';
    }

    public function label(): string
    {
        return '네이버 쇼핑';
    }

    public function format(): string
    {
        return 'tsv';
    }

    public function defaultCampaignKey(): string
    {
        return 'naver-shopping';
    }

    public function columns(): array
    {
        return [
            'id',
            'title',
            'price_pc',
            'link',
            'mobile_link',
            'image_link',
            'add_image_link',
            'category_name1',
            'category_name2',
            'category_name3',
            'category_name4',
            'brand',
            'maker',
            'search_tag',
            'shipping',
            'update_time',
        ];
    }

    public function row(FeedItem $item): array
    {
        return [
            (string) $item->goodsId,
            $item->name,
            (string) $item->price,
            $item->trackedUrl,
            // PC/모바일이 같은 URL 을 쓴다(반응형). 규격이 두 칸을 요구하므로 같은 값을 넣는다.
            $item->trackedUrl,
            $item->mainImageUrl,
            implode(',', $item->extraImageUrls),
            $item->categoryName(1),
            $item->categoryName(2),
            $item->categoryName(3),
            $item->categoryName(4),
            // 브랜드는 상품 테이블에 없다. 제조사만 있으므로 maker 에만 싣고 brand 는 비운다.
            // 없는 값을 제조사로 채우면 비교사에서 브랜드 오등록으로 잡힌다.
            '',
            $item->manufacturer,
            implode(',', $item->tags),
            (string) $item->shippingFee,
            FeedValue::dateYmd($item->updatedAt),
        ];
    }

    public function guide(): array
    {
        return [
            '네이버 쇼핑파트너센터에 판매자로 등록한 뒤, 상품 정보 제공 방식으로 이 피드 주소를 등록합니다.',
            '전체 피드와 별도로 변경분 주소(주소 끝에 /summary)를 등록하면 가격 변동이 더 빨리 반영됩니다. 변경분은 당일 바뀐 상품만 담습니다.',
            '카테고리는 쇼핑몰 카테고리명을 그대로 내보냅니다. 네이버 카테고리로 맞추려면 채널을 따로 만들어 매핑을 넣으세요.',
            '브랜드·모델명은 상품 정보에 없어 비워서 나갑니다. 매칭률을 높이려면 그 값을 채우는 채널이 필요합니다.',
            '가격은 상품 판매가 기준이며 옵션 추가금은 반영되지 않습니다.',
        ];
    }
}
