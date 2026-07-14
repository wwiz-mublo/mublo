<?php

namespace Tests\Unit\Core;

use Mublo\Core\AiConfig;
use PHPUnit\Framework\TestCase;

final class AiConfigTest extends TestCase
{
    public function testInstalledConfigHasSupportedVersionAndRequiredSections(): void
    {
        $config = AiConfig::load();

        $this->assertGreaterThanOrEqual(AiConfig::CURRENT_VERSION, $config['config_version']);
        $this->assertArrayHasKey('openai', $config['providers']);
        $this->assertArrayHasKey('anthropic', $config['providers']);
        $this->assertArrayHasKey('gemini', $config['providers']);
        $this->assertNotEmpty(AiConfig::assets()['allowed_extensions']);
    }

    public function testInstallerDoesNotOverwriteExistingOperatorConfig(): void
    {
        $path = MUBLO_CONFIG_PATH . '/ai.php';
        $before = hash_file('sha256', $path);

        $this->assertTrue((new \Mublo\Core\Install\Installer())->generateAiConfig());
        $this->assertSame($before, hash_file('sha256', $path));
    }
}
