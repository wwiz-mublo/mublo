<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\PriceCompare\Channel;

use Mublo\Packages\Shop\Contract\PriceCompare\PriceCompareChannelInterface;
use Mublo\Packages\Shop\PriceCompare\FeedItem;
use Mublo\Packages\Shop\PriceCompare\FeedValue;

/**
 * 카카오 쇼핑하우 피드 (기본 제공)
 *
 * 국내 비교사 공통 형태인 탭 구분 텍스트로 낸다.
 *
 * 카카오는 규격을 제휴 과정에서 개별 안내하는 쪽이라, 공개된 컬럼 정의가 얇다.
 * 그래서 여기 컬럼은 "국내 비교사 피드가 공통으로 요구하는 값"으로 잡은 것이고,
 * 실제 제휴 시 받은 문서와 이름·순서가 다를 수 있다. 그때는 이 클래스의 columns()
 * 와 row() 두 곳만 문서에 맞춰 고치면 된다 — 상품 조회와 라이터는 건드릴 필요가 없다.
 */
final class KakaoShoppingHow implements PriceCompareChannelInterface
{
    public function code(): string
    {
        return 'kakao';
    }

    public function label(): string
    {
        return '카카오 쇼핑하우';
    }

    public function format(): string
    {
        return 'tsv';
    }

    public function defaultCampaignKey(): string
    {
        return 'kakao-shopping';
    }

    public function columns(): array
    {
        return [
            'id',
            'title',
            'price',
            'link',
            'image_link',
            'category_name1',
            'category_name2',
            'category_name3',
            'category_name4',
            'maker',
            'origin',
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
            $item->mainImageUrl,
            $item->categoryName(1),
            $item->categoryName(2),
            $item->categoryName(3),
            $item->categoryName(4),
            $item->manufacturer,
            $item->origin,
            (string) $item->shippingFee,
            FeedValue::dateYmd($item->updatedAt),
        ];
    }

    public function guide(): array
    {
        return [
            '카카오 쇼핑하우는 입점 심사를 거쳐 제휴가 성립한 뒤 피드 주소를 전달합니다.',
            '컬럼 이름과 순서는 제휴 시 받은 규격 문서 기준으로 맞춰야 합니다. 이 채널은 국내 비교사 공통 항목으로 잡아 둔 출발점입니다.',
            '전체 피드와 별도로 변경분 주소(주소 끝에 /summary)를 등록하면 가격 변동이 더 빨리 반영됩니다. 변경분은 당일 바뀐 상품만 담습니다.',
            '카테고리는 쇼핑몰 카테고리명을 그대로 내보냅니다.',
            '가격은 상품 판매가 기준이며 옵션 추가금은 반영되지 않습니다.',
        ];
    }
}
