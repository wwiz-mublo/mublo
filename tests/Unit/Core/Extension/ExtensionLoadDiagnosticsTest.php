<?php

namespace Mublo\Plugin\BrokenPlugin;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionProviderInterface;

class BrokenPluginProvider implements ExtensionProviderInterface
{
    public function register(DependencyContainer $container): void
    {
        throw new \RuntimeException('Broken plugin register failed');
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
    }
}

namespace Tests\Unit\Core\Extension;

use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionLoadDiagnostics;
use Mublo\Core\Extension\ExtensionManager;
use Tests\TestCase;

class ExtensionLoadDiagnosticsTest extends TestCase
{
    public function testExtensionManagerRecordsProviderLoadFailure(): void
    {
        $diagnostics = new ExtensionLoadDiagnostics();
        $manager = new ExtensionManager($this->getContainer(), $diagnostics);
        $context = $this->createMock(Context::class);
        $previousDebug = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'true';

        $this->expectException(\RuntimeException::class);

        try {
            $manager->loadExtensions($context, ['BrokenPlugin'], []);
        } finally {
            if ($previousDebug === null) {
                unset($_ENV['APP_DEBUG']);
            } else {
                $_ENV['APP_DEBUG'] = $previousDebug;
            }

            $failures = $diagnostics->all();

            $this->assertCount(1, $failures);
            $this->assertSame('plugin', $failures[0]['type']);
            $this->assertSame('BrokenPlugin', $failures[0]['name']);
            $this->assertSame('load', $failures[0]['stage']);
            $this->assertSame('Broken plugin register failed', $failures[0]['message']);
            $this->assertSame(\RuntimeException::class, $failures[0]['exception']);
        }
    }
}
