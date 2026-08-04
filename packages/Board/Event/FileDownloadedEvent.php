<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Board\Entity\BoardAttachment;
use Mublo\Contract\Member\MemberIdentity;

/**
 * 파일 다운로드 이벤트
 *
 * 발행 시점: 파일 다운로드 시 (download_count 증가 전)
 * 용도: 포인트 차감, 다운로드 이력 기록
 */
class FileDownloadedEvent extends AbstractEvent
{
    public const NAME = 'board.file.downloaded';

    public function __construct(
        private readonly BoardAttachment $attachment,
        private readonly ?MemberIdentity $downloader = null,
        private readonly ?string $ipAddress = null
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

    /** @deprecated Use getDownloaderIdentity(). */
    public function getDownloader(): ?MemberIdentity
    {
        return $this->downloader;
    }

    public function getDownloaderIdentity(): ?MemberIdentity
    {
        return $this->downloader;
    }

    public function getDownloaderId(): ?int
    {
        return $this->downloader?->memberId;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getFileName(): string
    {
        return $this->attachment->getOriginalName();
    }

    public function getFileSize(): int
    {
        return $this->attachment->getFileSize();
    }
}
