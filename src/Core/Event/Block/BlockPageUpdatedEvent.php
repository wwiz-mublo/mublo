<?php
declare(strict_types=1);

namespace Mublo\Core\Event\Block;

use Mublo\Core\Event\AbstractEvent;

/** 블록 페이지 코드·제목 변경 뒤 자동 메뉴를 동기화하기 위한 이벤트. */
final class BlockPageUpdatedEvent extends AbstractEvent
{
    public function __construct(
        private readonly int $domainId,
        private readonly int $pageId,
        private readonly string $oldPageCode,
        private readonly string $pageCode,
        private readonly string $pageTitle
    ) {
    }

    public function getDomainId(): int { return $this->domainId; }
    public function getPageId(): int { return $this->pageId; }
    public function getOldPageCode(): string { return $this->oldPageCode; }
    public function getPageCode(): string { return $this->pageCode; }
    public function getPageTitle(): string { return $this->pageTitle; }
}
