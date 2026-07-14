<?php

namespace Mublo\Core\Event\Block;

use Mublo\Core\Event\AbstractEvent;

/**
 * 블록 콘텐츠 필터 이벤트
 *
 * 블록 렌더러가 아이템을 resolve한 후, 스킨 렌더링 전에 발행합니다.
 * 패키지가 Context(siteOverride 등)를 기반으로 아이템을 필터링할 수 있습니다.
 *
 * 사용 예:
 *   - Rental 패키지: 브랜드샵에서 해당 브랜드 배너만 노출
 *   - Shop 패키지: 카테고리별 배너 필터링
 *
 * 렌더러에서의 사용:
 *   $event = new BlockContentFilterEvent($contentType, $items);
 *   $this->eventDispatcher->dispatch($event);
 *   $items = $event->getItems();
 */
class BlockContentFilterEvent extends AbstractEvent
{
    public function __construct(
        private readonly string $contentType,
        private array $items,
    ) {}

    public function getContentType(): string
    {
        return $this->contentType;
    }

    /**
     * @return array 현재 아이템 목록
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * 아이템 목록 교체
     */
    public function setItems(array $items): void
    {
        $this->items = $items;
    }

    /**
     * 콜백으로 아이템 필터링 (편의 메서드)
     *
     * @param callable $callback fn($item) => bool
     */
    public function filterItems(callable $callback): void
    {
        $this->items = array_values(array_filter($this->items, $callback));
    }
}
