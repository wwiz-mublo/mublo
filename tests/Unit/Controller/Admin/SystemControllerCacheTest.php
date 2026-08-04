<?php

namespace Tests\Unit\Controller\Admin;

use Mublo\Controller\Admin\SystemController;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Result\Result;
use Mublo\Entity\Domain\Domain;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Extension\ExtensionService;
use Mublo\Service\System\DatabaseBackupService;
use Mublo\Service\System\DataResetService;
use Mublo\Service\System\SystemService;
use PHPUnit\Framework\TestCase;

final class SystemControllerCacheTest extends TestCase
{
    public function testClearCacheUsesResolvedCanonicalDomainInsteadOfRawHost(): void
    {
        $systemService = $this->createMock(SystemService::class);
        $systemService->expects($this->once())
            ->method('clearAllCache')
            ->with(7, 'example.local')
            ->willReturn(Result::success('캐시를 초기화했습니다.'));

        $context = $this->createMock(Context::class);
        $context->method('getDomainId')->willReturn(7);
        $context->method('getDomain')->willReturn('demo.example.local');
        $context->method('getDomainInfo')->willReturn(new Domain(7, 'example.local'));

        $controller = new SystemController(
            $systemService,
            $this->createMock(ExtensionService::class),
            $this->createMock(DataResetService::class),
            $this->createMock(DatabaseBackupService::class),
            $this->createMock(AuthService::class),
            $this->createMock(DependencyContainer::class),
        );

        $controller->clearCache([], $context);
    }
}
