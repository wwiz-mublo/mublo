<?php

namespace Mublo\Core\Event\Storage;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\AbstractEvent;

/**
 * 보안 파일 접근 권한 위임 이벤트.
 *
 * Core가 알지 못하는 category의 다운로드 권한은 Package/Plugin subscriber가
 * grant()를 호출해 허용한다. 아무 subscriber도 허용하지 않으면 관리자만 허용된다.
 */
class SecureFileAccessEvent extends AbstractEvent
{
    private bool $granted = false;

    public function __construct(
        private readonly int $domainId,
        private readonly string $category,
        private readonly string $entityId,
        private readonly string $filePath,
        private readonly Context $context,
    ) {
    }

    public function grant(): void
    {
        $this->granted = true;
        $this->stopPropagation();
    }

    public function isGranted(): bool
    {
        return $this->granted;
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

    public function getContext(): Context
    {
        return $this->context;
    }
}
