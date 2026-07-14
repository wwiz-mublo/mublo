<?php
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;

/**
 * 게시판 삭제 완료 이벤트 (readonly)
 *
 * 발행 시점: DB 삭제 후
 * 용도: 프론트 메뉴 자동 삭제
 *
 * Note: 삭제 후 엔티티가 없으므로 원시값으로 전달
 */
class BoardConfigDeletedEvent extends AbstractEvent
{
    public const NAME = 'board.config.deleted';

    public function __construct(
        private readonly int $domainId,
        private readonly int $boardId,
        private readonly string $boardSlug,
        private readonly string $boardName
    ) {}

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getBoardId(): int
    {
        return $this->boardId;
    }

    public function getBoardSlug(): string
    {
        return $this->boardSlug;
    }

    public function getBoardName(): string
    {
        return $this->boardName;
    }
}
