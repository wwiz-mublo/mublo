<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Contract\PriceCompare;

use Mublo\Packages\Shop\PriceCompare\FeedItem;

/**
 * 가격비교 채널 계약
 *
 * 채널 하나가 "피드에 무엇을 어떤 이름으로 싣는가"와 "운영자에게 무엇을 안내하는가"를
 * 함께 들고 온다. 그래서 채널을 추가할 때 라우트·화면·스키마를 건드릴 일이 없다.
 *
 * 등록은 ContractRegistry 에 이 인터페이스를 계약으로 쓴다(PG 게이트웨이와 같은 방식).
 *
 *   $registry->register(
 *       PriceCompareChannelInterface::class,
 *       'mynaver',
 *       fn() => new MyNaverChannel(),
 *       ['label' => '네이버 쇼핑(자체)']
 *   );
 *
 * 키는 피드 URL 의 마지막 세그먼트가 된다. 이름 공간이 이미 /shop/price-compare/ 로
 * 좁혀져 있어 기본 제공 채널도 'naver' 처럼 채널사 이름을 그대로 쓴다.
 *
 * 자체 구현은 자기 키로 등록한다(예: 'naver-custom'). 기본 채널과 나란히 존재할 수
 * 있고 주소도 따로 생긴다. ContractRegistry 는 키가 겹치면 예외를 던지는데, 기본
 * 채널은 이미 잡힌 키에서 스스로 물러나 그 충돌을 피한다.
 */
interface PriceCompareChannelInterface
{
    /** 레지스트리 키와 피드 URL 마지막 세그먼트로 쓰이는 값 (예: 'naver') */
    public function code(): string;

    /** 관리자 화면에 보일 이름 (예: '네이버 쇼핑') */
    public function label(): string;

    /**
     * 출력 형식. 라이터가 형식 단위로 재사용되므로 채널은 형식을 고르기만 한다.
     *
     * 현재 지원: 'tsv'. 지원하지 않는 형식을 돌려주면 그 채널만 피드를 내지 못한다.
     */
    public function format(): string;

    /**
     * 피드 컬럼 이름 목록. 순서가 곧 출력 순서다.
     *
     * @return list<string>
     */
    public function columns(): array;

    /**
     * 상품 한 건을 컬럼 순서에 맞춘 값 배열로 변환한다.
     *
     * 반환 개수는 columns() 와 같아야 한다. 값이 없는 컬럼은 빈 문자열로 채운다.
     *
     * @return list<string>
     */
    public function row(FeedItem $item): array;

    /**
     * 유입 추적 캠페인키의 기본값.
     *
     * 피드 링크에 `?k=` 로 붙어 방문자 통계에서 이 채널 유입이 갈린다. 운영자가
     * 채널 설정에서 다른 값을 지정하면 그것이 우선하고, 비어 있으면 이 값을 쓴다.
     * 통계 도구에 이미 쓰던 키가 있는 경우를 위해 설정으로 덮어쓸 수 있어야 한다.
     */
    public function defaultCampaignKey(): string;

    /**
     * 관리자 안내문. 이 채널에 피드를 등록하려면 무엇을 해야 하는지.
     *
     * @return list<string>
     */
    public function guide(): array;
}
