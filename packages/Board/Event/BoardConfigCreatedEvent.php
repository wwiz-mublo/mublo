<?php
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Board\Entity\BoardConfig;

/**
 * 게시판 생성 완료 이벤트 (readonly)
 *
 * 발행 시점: DB 저장 후
 * 용도: 프론트 메뉴 자동 등록
 */
class BoardConfigCreatedEvent extends AbstractEvent
{
    public const NAME = 'board.config.created';

    public function __construct(
        private readonly BoardConfig $boardConfig
    ) {}

    public function getBoardConfig(): BoardConfig
    {
        return $this->boardConfig;
    }

    public function getDomainId(): int
    {
        return $this->boardConfig->getDomainId();
    }

    public function getBoardId(): int
    {
        return $this->boardConfig->getBoardId();
    }

    public function getBoardSlug(): string
    {
        return $this->boardConfig->getBoardSlug();
    }

    public function getBoardName(): string
    {
        return $this->boardConfig->getBoardName();
    }
}
