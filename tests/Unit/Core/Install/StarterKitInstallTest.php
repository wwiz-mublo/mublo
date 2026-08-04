<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Install;

use Mublo\Core\Install\Installer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 설치 마법사 시작 킷 계약
 *
 * step3 의 시작 킷 선택이 의존하는 세 가지를 고정한다:
 * 매니페스트 조회, 킷 로드, 필요 확장의 extension_config 병합.
 */
class StarterKitInstallTest extends TestCase
{
    private Installer $installer;

    protected function setUp(): void
    {
        $this->installer = new Installer();
    }

    public function testGetStarterKitsReturnsBundledKitsWithValidExtensions(): void
    {
        $kits = $this->installer->getStarterKits();

        $this->assertSame(['company', 'community', 'shop'], array_keys($kits));

        foreach ($kits as $slug => $meta) {
            $ext = $meta['extensions'] ?? null;
            $this->assertIsArray($ext, "{$slug}: extensions 매니페스트 필요 (설치 마법사가 활성 목록에 등록)");

            // 매니페스트가 가리키는 확장 디렉토리가 실제로 존재해야
            // 첫 부팅 reconcile 이 설치할 수 있다
            foreach ($ext['packages'] ?? [] as $name) {
                $this->assertDirectoryExists(MUBLO_PACKAGE_PATH . '/' . $name, "{$slug}: 패키지 {$name}");
            }
            foreach ($ext['plugins'] ?? [] as $name) {
                $this->assertDirectoryExists(MUBLO_PLUGIN_PATH . '/' . $name, "{$slug}: 플러그인 {$name}");
            }
        }
    }

    public function testLoadStarterKitReturnsScreenKitOrNull(): void
    {
        $kit = $this->installer->loadStarterKit('community');

        $this->assertIsArray($kit);
        $this->assertSame('screen', $kit['target']['kind'] ?? null);
        $this->assertNotEmpty($kit['rows']);
        $this->assertArrayHasKey('site_config', $kit['site_settings'] ?? []);

        $this->assertNull($this->installer->loadStarterKit('no-such-kit'));
    }

    public function testBundledKitsEmbedValidScreenshots(): void
    {
        // 보관함 목록 썸네일의 원천 — 시더(003)가 BlockKitScreenshot 으로 굽는다
        $screenshot = new \Mublo\Service\Block\BlockKitScreenshot(
            new \Mublo\Infrastructure\Image\ImageProcessor(),
            sys_get_temp_dir() // 실제 public/storage 를 건드리지 않도록 주입
        );

        foreach (array_keys($this->installer->getStarterKits()) as $slug) {
            $kit = $this->installer->loadStarterKit($slug);
            $this->assertTrue(
                $screenshot->isValidDataUri($kit['screenshot'] ?? null),
                "{$slug} 킷의 임베드 스크린샷이 유효해야 한다 (png/webp data URI, 500KB 이하)"
            );
        }
    }

    public function testKitExtensionsAreMergedIntoActiveListsButNotInstalled(): void
    {
        $method = (new ReflectionClass(Installer::class))->getMethod('buildDefaultExtensionConfig');
        $method->setAccessible(true);

        $json = $method->invoke($this->installer, [
            'packages' => ['Board', 'Shop'],
            'plugins' => ['Faq', 'Survey'],
        ]);
        $config = json_decode($json, true);

        $this->assertContains('Board', $config['packages']);
        $this->assertContains('Shop', $config['packages']);
        $this->assertContains('Faq', $config['plugins']);
        $this->assertContains('Survey', $config['plugins']);

        // installed 는 비워 둔다 — 첫 부팅 reconcile 이 마이그레이션+install 을 수행하는 전제
        $this->assertSame([], $config['installed']['plugins']);
        $this->assertSame([], $config['installed']['packages']);
    }

    public function testNoKitKeepsDefaultBehaviour(): void
    {
        $method = (new ReflectionClass(Installer::class))->getMethod('buildDefaultExtensionConfig');
        $method->setAccessible(true);

        $config = json_decode($method->invoke($this->installer, null), true);

        $this->assertSame([], $config['plugins']);
        $this->assertIsArray($config['packages']); // default:true 패키지 목록 (현재 저장소에선 빈 배열)
    }
}
