<?php

namespace Tests\Unit\Core\Event\Storage;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\Storage\SecureFileAccessEvent;
use Mublo\Core\Event\Storage\SecureFileDownloadedEvent;
use PHPUnit\Framework\TestCase;

class SecureFileEventTest extends TestCase
{
    public function testSecureFileAccessEventCanGrantAccessAndStopPropagation(): void
    {
        $context = $this->createMock(Context::class);
        $event = new SecureFileAccessEvent(1, 'orders', 'ORD1', '/secure/file.pdf', $context);

        $this->assertFalse($event->isGranted());

        $event->grant();

        $this->assertTrue($event->isGranted());
        $this->assertTrue($event->isPropagationStopped());
        $this->assertSame(1, $event->getDomainId());
        $this->assertSame('orders', $event->getCategory());
        $this->assertSame('ORD1', $event->getEntityId());
        $this->assertSame('/secure/file.pdf', $event->getFilePath());
        $this->assertSame($context, $event->getContext());
    }

    public function testSecureFileDownloadedEventExposesPayload(): void
    {
        $event = new SecureFileDownloadedEvent(2, 'member-fields', '15', '/secure/avatar.png', 10, '127.0.0.1');

        $this->assertSame(2, $event->getDomainId());
        $this->assertSame('member-fields', $event->getCategory());
        $this->assertSame('15', $event->getEntityId());
        $this->assertSame('/secure/avatar.png', $event->getFilePath());
        $this->assertSame(10, $event->getMemberId());
        $this->assertSame('127.0.0.1', $event->getClientIp());
    }
}
