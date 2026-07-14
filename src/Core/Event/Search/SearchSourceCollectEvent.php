<?php

namespace Mublo\Core\Event\Search;

use Mublo\Core\Event\AbstractEvent;

/**
 * 검색 소스 수집 이벤트
 *
 * 관리자 검색 설정 / 소스 검증 시 발행하여
 * 각 Package가 자신이 제공하는 검색 소스를 등록하도록 한다.
 *
 * 흐름:
 *   DomainSettingsService → dispatch(SearchSourceCollectEvent)
 *   → BoardSearchSubscriber::onCollect()  (Board Package)
 *   → MshopSearchSubscriber::onCollect()  (Package)
 *   → RentalSearchSubscriber::onCollect() (Package)
 *
 * 구독자 구현 예시:
 *   public function onCollect(SearchSourceCollectEvent $event): void
 *   {
 *       $event->addSource('rental', '렌탈 상품');
 *       $event->addSource('rental_review', '렌탈 리뷰');
 *   }
 */
class SearchSourceCollectEvent extends AbstractEvent
{
    /** @var array<string, array{source: string, label: string, always: bool, group: string}> */
    private array $sources = [];

    /**
     * 검색 소스 등록
     *
     * @param string $source 소스 식별자 ('board', 'mshop', 'rental_review' 등)
     * @param string $label  표시명 ('게시판', '렌탈 리뷰' 등)
     * @param bool   $always 항상 포함 여부 (체크박스 비활성화)
     * @param string $group  출처 종류 'package' | 'plugin' (관리자 출처 배지 색 구분)
     */
    public function addSource(string $source, string $label, bool $always = false, string $group = 'package'): void
    {
        $this->sources[$source] = [
            'source' => $source,
            'label'  => $label,
            'always' => $always,
            'group'  => $group,
        ];
    }

    /**
     * 수집된 소스 목록 반환
     *
     * @return array<array{source: string, label: string, always: bool, group: string}>
     */
    public function getSources(): array
    {
        return array_values($this->sources);
    }

    /**
     * 수집된 소스 식별자 목록 반환 (sanitize 허용 목록용)
     *
     * @return string[]
     */
    public function getSourceKeys(): array
    {
        return array_keys($this->sources);
    }
}
