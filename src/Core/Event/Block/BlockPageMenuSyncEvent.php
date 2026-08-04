<?php
declare(strict_types=1);

namespace Mublo\Core\Event\Block;

use Mublo\Core\Event\AbstractEvent;

/** 대체 생성 경로에서 블록 페이지 메뉴 아이템 동기화를 요청한다. */
class BlockPageMenuSyncEvent extends AbstractEvent
{
    public const NAME = 'block.page.menu_sync';

    public function __construct(
        private readonly int $domainId,
        private readonly int $pageId,
        private readonly string $pageCode,
        private readonly string $pageTitle
    ) {}

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getPageId(): int
    {
        return $this->pageId;
    }

    public function getPageCode(): string
    {
        return $this->pageCode;
    }

    public function getPageTitle(): string
    {
        return $this->pageTitle;
    }
}
