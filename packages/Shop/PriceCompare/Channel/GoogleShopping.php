<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\PriceCompare\Channel;

use Mublo\Packages\Shop\Contract\PriceCompare\PriceCompareChannelInterface;
use Mublo\Packages\Shop\PriceCompare\FeedItem;
use Mublo\Packages\Shop\PriceCompare\FeedValue;

/**
 * 구글 상품 피드 (기본 제공)
 *
 * 국내 비교사와 달리 RSS 2.0 + g: 네임스페이스를 쓴다. 형식만 다르고 채널이 하는
 * 일은 같다 — 컬럼명과 값을 선언한다.
 *
 * 두 곳에서 의도적으로 규격을 덜 채운다.
 *
 *  - google_product_category 를 넣지 않는다. 구글 분류 체계에 맞추려면 카테고리
 *    대응표가 필요하고, 그것을 운영자가 관리할 화면까지 딸려온다. product_type 에
 *    쇼핑몰 카테고리를 그대로 실으면 구글이 분류를 추정하므로 기본 제공은 여기까지
 *    한다. 정확한 분류가 필요한 쪽이 이 클래스를 복사해 대응표를 넣으면 된다.
 *  - shipping 을 넣지 않는다. 배송비는 Merchant Center 계정 설정으로도 지정할 수
 *    있고, 그쪽이 국가·지역별 규칙을 제대로 표현한다. 피드에 금액 하나만 실으면
 *    조건부 무료 같은 규칙이 뭉개진다.
 */
final class GoogleShopping implements PriceCompareChannelInterface
{
    public function code(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google 쇼핑';
    }

    public function format(): string
    {
        return 'rss';
    }

    public function defaultCampaignKey(): string
    {
        return 'google-shopping';
    }

    public function columns(): array
    {
        return [
            'id',
            'title',
            'description',
            'link',
            'image_link',
            'additional_image_link',
            'availability',
            'price',
            'brand',
            'condition',
            'product_type',
            'identifier_exists',
        ];
    }

    public function row(FeedItem $item): array
    {
        // 구글에는 브랜드 칸이 하나뿐이라 제조사를 그 칸에 넣는다. 네이버는 brand 와
        // maker 를 따로 받으므로 거기서는 maker 에만 넣고 brand 를 비운다.
        //
        // 그래도 비면 identifier_exists=no 로 "식별자 없는 상품"임을 명시한다.
        // 이 선언이 없으면 brand·gtin·mpn 이 비었다는 이유로 항목이 거부된다.
        $brand = $item->manufacturer;

        return [
            (string) $item->goodsId,
            $item->name,
            // 상품 상세는 HTML 탭 구조라 그대로 실을 수 없다. 설명이 필수 항목이므로
            // 상품명으로 채운다. 제대로 된 설명이 필요하면 상세 본문을 텍스트로 뽑는
            // 채널을 따로 만드는 편이 낫다(빈 값보다 짧은 사실이 안전하다).
            $item->name,
            $item->trackedUrl,
            $item->mainImageUrl,
            // 추가 이미지는 반복 요소가 규격이지만, 기본 제공은 한 장만 싣는다.
            $item->extraImageUrls[0] ?? '',
            // 품절 상품은 조회 단계에서 이미 빠진다.
            'in stock',
            FeedValue::money($item->price),
            $brand,
            'new',
            implode(' > ', $item->categoryNames),
            $brand === '' ? 'no' : '',
        ];
    }

    public function guide(): array
    {
        return [
            'Google Merchant Center 에서 [상품 → 피드] 로 이동해 예약된 가져오기 방식으로 이 피드 주소를 등록합니다.',
            '배송비는 피드에 넣지 않습니다. Merchant Center 계정의 배송 설정을 사용하세요.',
            '카테고리는 쇼핑몰 카테고리를 product_type 으로 내보내고 구글 분류는 비웁니다. 구글 분류로 맞추려면 채널을 따로 만들어 대응표를 넣으세요.',
            '상품 설명은 상품명으로 채워 나갑니다. 상세 본문이 필요하면 채널을 따로 만드세요.',
            '브랜드는 제조사 값을 사용하며, 비어 있으면 identifier_exists=no 로 내보냅니다.',
        ];
    }
}
