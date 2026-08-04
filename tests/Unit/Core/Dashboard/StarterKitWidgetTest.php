<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Dashboard;

use Mublo\Core\Dashboard\Widget\StarterKitWidget;
use Mublo\Infrastructure\Database\Database;
use PHPUnit\Framework\TestCase;

/**
 * 시작 킷 위젯 계약
 *
 * 위젯은 번들 매니페스트(database/seeders/starter-kits/kits.php)를 그대로 보여준다.
 * 매니페스트는 설치 시더와 공유하는 단일 진실이므로, 여기서 함께 검증한다.
 *
 * 썸네일의 단일 진실은 킷 JSON 의 screenshot(data URI)이다(#39 원칙 1) —
 * 정적 이미지 사본은 존재하지 않고, 위젯은 시더가 구운 썸네일(block_kits.
 * screenshot_path)을 우선 쓰되 없으면 킷 JSON 에서 직접 꺼낸다.
 */
class StarterKitWidgetTest extends TestCase
{
    public function testManifestBundlesThreeKitsWithReadableFiles(): void
    {
        $dir = MUBLO_ROOT_PATH . '/database/seeders/starter-kits';
        $manifest = require $dir . '/kits.php';

        $this->assertCount(3, $manifest);
        $this->assertSame(['company', 'community', 'shop'], array_keys($manifest));

        foreach ($manifest as $slug => $meta) {
            $this->assertFileExists($dir . '/' . $meta['file'], "{$slug} 킷 파일");

            $kit = json_decode((string) file_get_contents($dir . '/' . $meta['file']), true);
            $this->assertIsArray($kit, "{$slug} 킷 JSON");
            $this->assertSame('screen', $kit['target']['kind'] ?? null, "{$slug} 는 메인화면(screen) 킷이어야 한다");
            $this->assertNotEmpty($kit['rows'] ?? [], "{$slug} 킷에 행이 있어야 한다");
            $this->assertFalse($kit['contains_script'] ?? true, "{$slug} 번들 킷은 스크립트를 포함하면 안 된다");

            // 미리보기의 단일 진실 — 킷 JSON 에 임베드된 스크린샷(png/webp data URI)
            $this->assertMatchesRegularExpression(
                '#^data:image/(png|webp);base64,#',
                (string) ($kit['screenshot'] ?? ''),
                "{$slug} 킷 JSON 에 스크린샷 data URI 가 임베드되어야 한다"
            );
        }
    }

    public function testRenderPrefersBakedScreenshotPath(): void
    {
        $db = $this->createStub(Database::class);
        $db->method('select')->willReturn([
            ['kit_name' => '회사 홈페이지 시작 킷', 'screenshot_path' => '/storage/kit-screenshots/D1/1.webp'],
            ['kit_name' => '커뮤니티 시작 킷', 'screenshot_path' => '/storage/kit-screenshots/D1/2.webp'],
            ['kit_name' => '쇼핑몰 시작 킷', 'screenshot_path' => '/storage/kit-screenshots/D1/3.webp'],
        ]);

        $html = (new StarterKitWidget($db, fn() => 1))->render();

        $this->assertStringContainsString('회사 홈페이지 시작 킷', $html);
        $this->assertStringContainsString('커뮤니티 시작 킷', $html);
        $this->assertStringContainsString('쇼핑몰 시작 킷', $html);
        $this->assertStringContainsString('/admin/block-kit', $html);
        $this->assertStringContainsString('/storage/kit-screenshots/D1/2.webp', $html);
        $this->assertStringNotContainsString('data:image/', $html, '구운 썸네일이 있으면 data URI 폴백을 쓰지 않는다');
    }

    public function testRenderFallsBackToKitJsonWhenDbUnavailable(): void
    {
        // 위젯은 부가 UI — DB 가 죽어도 대시보드를 죽이지 않고 킷 JSON 폴백으로 그린다
        $db = $this->createStub(Database::class);
        $db->method('select')->willThrowException(new \RuntimeException('db down'));

        $html = (new StarterKitWidget($db, fn() => 1))->render();

        $this->assertStringContainsString('회사 홈페이지 시작 킷', $html);
        $this->assertStringContainsString('data:image/', $html, 'DB 실패 시 킷 JSON 의 data URI 로 폴백한다');
    }

    public function testWidgetIdentity(): void
    {
        $widget = new StarterKitWidget($this->createStub(Database::class), fn() => null);

        $this->assertSame('core.starter_kits', $widget->id());
        $this->assertSame('시작 킷', $widget->title());
    }
}
