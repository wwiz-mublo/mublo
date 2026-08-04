<?php

namespace Tests\Unit\Service\Extension;

use Mublo\Core\Extension\MigrationRunner;
use Mublo\Infrastructure\Cache\DomainCache;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Service\Extension\ExtensionCompatibility;
use Mublo\Service\Extension\ExtensionService;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class ExtensionLifecycleMigrationTest extends TestCase
{
    public function testNestedPluginUsesDiscoveredDirectoryAndStopsOnMigrationFailure(): void
    {
        $runner = $this->createMock(MigrationRunner::class);
        $runner->expects($this->once())
            ->method('run')
            ->with(
                'plugin',
                'Board/BoardReport',
                MUBLO_PACKAGE_PATH . '/Board/Plugins/BoardReport/database/migrations'
            )
            ->willReturn([
                'success' => false,
                'executed' => [],
                'error' => '[001_create_board_reports.sql] forced failure',
            ]);
        $this->getContainer()->set(MigrationRunner::class, $runner);

        $service = new ExtensionService(
            $this->createMock(DomainRepository::class),
            $this->createMock(DomainCache::class),
            $this->createMock(Database::class),
            new ExtensionCompatibility()
        );
        $method = (new ReflectionClass($service))->getMethod('runMigrationsOrFail');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migration failed [plugin:Board/BoardReport]');

        $method->invoke($service, 'plugin', 'Board/BoardReport', $this->getContainer());
    }
}
