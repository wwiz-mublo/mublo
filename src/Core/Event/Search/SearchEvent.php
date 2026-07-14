<?php

namespace Mublo\Core\Event\Search;

use Mublo\Core\Event\AbstractEvent;

/**
 * 전체 검색 이벤트
 *
 * Core에서 발행하며, 각 Plugin/Package 구독자가 자신의 검색 결과를 추가한다.
 *
 * 흐름:
 *   SearchController → SearchService → dispatch(SearchEvent)
 *   → BoardSearchSubscriber (Board Package)
 *   → MshopSearchSubscriber, ShopSearchSubscriber ... (Package)
 *   → SearchService: sourceOrder 기준으로 결과 정렬
 *
 * 구독자 구현 예시:
 *   public function onSearch(SearchEvent $event): void
 *   {
 *       if (!$event->isSourceEnabled('mshop')) return;
 *       $items = $this->deviceRepository->searchByKeyword(...);
 *       $event->addResults('mshop', '핸드폰 쇼핑몰', $items, count($items));
 *   }
 */
class SearchEvent extends AbstractEvent
{
    private string $keyword;
    private int $domainId;
    private array $sourceOrder;
    private array $enabledSources;
    private int $perSource;
    private array $results = [];
    private array $extras  = [];

    /**
     * @param string $keyword        검색 키워드
     * @param int    $domainId       도메인 ID
     * @param array  $sourceOrder    소스 표시 순서 (['board', 'mshop', 'shop'])
     * @param array  $enabledSources 활성화된 소스 목록 (['board', 'mshop'])
     * @param int    $perSource      소스별 최대 결과 수
     * @param array  $extras         구독자에게 전달할 추가 컨텍스트 (예: ['rental_brand_code' => 'LG'])
     */
    public function __construct(
        string $keyword,
        int $domainId,
        array $sourceOrder,
        array $enabledSources,
        int $perSource = 5,
        array $extras = []
    ) {
        $this->keyword = $keyword;
        $this->domainId = $domainId;
        $this->sourceOrder = $sourceOrder;
        $this->enabledSources = $enabledSources;
        $this->perSource = $perSource;
        $this->extras = $extras;
    }

    public function getKeyword(): string
    {
        return $this->keyword;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getSourceOrder(): array
    {
        return $this->sourceOrder;
    }

    public function getEnabledSources(): array
    {
        return $this->enabledSources;
    }

    public function getPerSource(): int
    {
        return $this->perSource;
    }

    /**
     * 소스 활성화 여부 확인
     *
     * 구독자가 자신의 소스가 활성화되었는지 확인 후 처리 여부 결정.
     * enabledSources가 빈 배열이면 모든 소스가 활성화된 것으로 간주한다.
     */
    public function isSourceEnabled(string $source): bool
    {
        if (empty($this->enabledSources)) {
            return true;
        }
        return in_array($source, $this->enabledSources, true);
    }

    /**
     * 검색 결과 추가 (구독자가 호출)
     *
     * @param string $source      소스 식별자 ('board', 'mshop', 'shop' 등)
     * @param string $sourceLabel 소스 표시명 ('게시판', '핸드폰 쇼핑몰' 등)
     * @param array  $items       결과 항목 배열
     *   각 항목: ['title'=>string, 'url'=>string, 'summary'=>string|null,
     *             'thumbnail'=>string|null, 'date'=>string|null, 'meta'=>string|null]
     * @param int    $total       해당 소스의 전체 결과 수
     * @param array  $options     추가 옵션
     *   - view_path: string|null — 소스 전용 렌더링 파일 절대 경로 (없으면 기본 템플릿)
     */
    public function addResults(string $source, string $sourceLabel, array $items, int $total, array $options = []): void
    {
        $this->results[$source] = [
            'source'       => $source,
            'source_label' => $sourceLabel,
            'items'        => array_slice($items, 0, $this->perSource),
            'total'        => $total,
            'view_path'    => $options['view_path'] ?? null,
            'more_url'     => $options['more_url'] ?? null,
        ];
    }

    /**
     * 수집된 결과 반환 (source => group 맵)
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * 결과 존재 여부
     */
    public function hasResults(): bool
    {
        return !empty($this->results);
    }

    /**
     * 추가 컨텍스트 반환
     */
    public function getExtras(): array
    {
        return $this->extras;
    }

    /**
     * 추가 컨텍스트 단일 값 반환
     */
    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extras[$key] ?? $default;
    }
}
