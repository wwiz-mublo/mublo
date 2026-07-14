<?php

namespace Mublo\Core\Event\Block;

use Mublo\Core\Event\AbstractEvent;

/**
 * 블록 콘텐츠 아이템 수집 이벤트
 *
 * Core BlockRowController에서 발행 → Package가 구독하여 아이템 목록 제공
 * 블록 관리 폼에서 콘텐츠 타입 선택 시 해당 타입의 아이템 목록을 가져올 때 사용
 */
class BlockContentItemsCollectEvent extends AbstractEvent
{
    private array $items = [];

    public function __construct(
        private readonly int $domainId,
        private readonly string $contentType,
    ) {}

    public function getDomainId(): int { return $this->domainId; }
    public function getContentType(): string { return $this->contentType; }

    /**
     * @param array $items [{id: string, label: string}, ...]
     */
    public function setItems(array $items): void
    {
        $this->items = $items;
    }

    public function getItems(): array { return $this->items; }
    public function hasItems(): bool { return !empty($this->items); }
}
