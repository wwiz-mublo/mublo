<?php

namespace Mublo\Service\Search;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Core\Event\Search\SearchEvent;

/**
 * 전체 검색 서비스
 *
 * SearchEvent를 발행하여 각 소스(게시판, Mshop 등)의 결과를 수집하고
 * site_config의 search_source_order 순서에 따라 정렬하여 반환한다.
 */
class SearchService
{
    private ?EventDispatcher $eventDispatcher;

    public function __construct(?EventDispatcher $eventDispatcher = null)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * 통합 검색 실행
     *
     * @param string $keyword   검색 키워드
     * @param int    $domainId  도메인 ID
     * @param array  $config    site_config 배열 (search_* 키 포함)
     * @return array {keyword, groups: [{source, source_label, items, total}]}
     */
    public function search(string $keyword, int $domainId, array $config, array $extras = []): array
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return ['keyword' => '', 'groups' => []];
        }

        $sourceOrder    = $config['search_source_order'] ?? [];
        // enabled_sources: null=미설정(전체 활성) / []=전체 해제 / [목록]=명시 활성
        $enabledConfig  = $config['search_enabled_sources'] ?? null;
        $allEnabled     = ($enabledConfig === null);
        $enabledSources = (array) $enabledConfig;
        $perSource      = max(1, (int) ($config['search_per_source'] ?? 5));

        // 전체 해제([])면 dispatch 자체를 생략한다 — SearchEvent는 빈 배열을
        // "전체 활성"으로 해석하므로, 여기서 걸러야 구독자들의 검색 쿼리가
        // 실행됐다가 정렬 단계에서 전부 버려지는 낭비를 막는다.
        if (!$allEnabled && $enabledSources === []) {
            return ['keyword' => $keyword, 'groups' => []];
        }

        $event = new SearchEvent($keyword, $domainId, $sourceOrder, $enabledSources, $perSource, $extras);
        $event = $this->dispatch($event);

        // sourceOrder 기준으로 결과 정렬 (비활성 소스 자동 제외)
        $rawResults = $event->getResults();
        $groups = [];
        foreach ($sourceOrder as $source) {
            if (
                isset($rawResults[$source]) &&
                ($allEnabled || in_array($source, $enabledSources, true))
            ) {
                $groups[] = $rawResults[$source];
            }
        }
        // sourceOrder에 없는 소스도 결과가 있으면 뒤에 추가
        foreach ($rawResults as $source => $group) {
            if (!in_array($source, $sourceOrder, true) &&
                ($allEnabled || in_array($source, $enabledSources, true))
            ) {
                $groups[] = $group;
            }
        }

        return [
            'keyword' => $keyword,
            'groups'  => $groups,
        ];
    }

    /**
     * 이벤트 발행 헬퍼
     */
    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }
}
