<?php
namespace Mublo\Core\Event\Block;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Entity\Block\BlockPage;

/**
 * 블록 페이지 생성 완료 이벤트 (readonly)
 *
 * 발행 시점: DB 저장 후
 * 용도: 프론트 메뉴 아이템 자동 등록
 */
class BlockPageCreatedEvent extends AbstractEvent
{
    public const NAME = 'block.page.created';

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
