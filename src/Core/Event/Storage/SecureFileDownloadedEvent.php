<?php

namespace Mublo\Core\Event\Storage;

use Mublo\Core\Event\AbstractEvent;

/**
 * 보안 파일 다운로드 완료 직전 발행 이벤트.
 */
class SecureFileDownloadedEvent extends AbstractEvent
{
    public function __construct(
        private readonly int $domainId,
        private readonly string $category,
        private readonly string $entityId,
        private readonly string $filePath,
        private readonly ?int $memberId,
        private readonly string $clientIp,
    ) {
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }

    public function getClientIp(): string
    {
        return $this->clientIp;
    }
}
