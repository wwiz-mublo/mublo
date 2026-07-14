<?php
namespace Mublo\Core\Event\Block;

use Mublo\Core\Event\AbstractEvent;

/**
 * 블록 페이지 삭제 이벤트 (readonly)
 *
 * 발행 시점: DB 삭제 후
 * 용도: 프론트 메뉴 아이템 자동 삭제
 */
class BlockPageDeletedEvent extends AbstractEvent
{
    public const NAME = 'block.page.deleted';

    public function __construct(
        private readonly int $domainId,
        private readonly string $pageCode
    ) {}

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getPageCode(): string
    {
        return $this->pageCode;
    }
}
