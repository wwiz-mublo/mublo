<?php
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Board\Entity\BoardAttachment;

/**
 * 파일 업로드 완료 이벤트
 *
 * 발행 시점: board_attachments 테이블 INSERT 후
 * 용도: 용량 계산, 바이러스 스캔
 */
class FileUploadedEvent extends AbstractEvent
{
    public const NAME = 'board.file.uploaded';

    public function __construct(
        private readonly BoardAttachment $attachment,
        private readonly ?int $memberId = null
    ) {}

    public function getAttachment(): BoardAttachment
    {
        return $this->attachment;
    }

    public function getAttachmentId(): int
    {
        return $this->attachment->getAttachmentId();
    }

    public function getArticleId(): int
    {
        return $this->attachment->getArticleId();
    }

    public function getBoardId(): int
    {
        return $this->attachment->getBoardId();
    }

    public function getFileSize(): int
    {
        return $this->attachment->getFileSize();
    }

    public function getFileName(): string
    {
        return $this->attachment->getOriginalName();
    }

    public function getMimeType(): string
    {
        return $this->attachment->getMimeType();
    }

    public function isImage(): bool
    {
        return $this->attachment->isImage();
    }

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }
}
