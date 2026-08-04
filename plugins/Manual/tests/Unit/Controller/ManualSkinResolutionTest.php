<?php

namespace Tests\Manual\Unit\Controller;

use Mublo\Plugin\Manual\Controller\Front\ManualController;
use Mublo\Plugin\Manual\Repository\ManualConfigRepository;
use Mublo\Plugin\Manual\Service\ManualService;
use PHPUnit\Framework\TestCase;

/**
 * 프론트 스킨 경로 해석 — 설정된 스킨을 쓰되, 파일이 없으면 파일 단위로 basic 에 폴백한다.
 */
final class ManualSkinResolutionTest extends TestCase
{
    private const SKIN_BASE = MUBLO_PLUGIN_PATH . '/Manual/views/Front/skins/';

    public function testUsesConfiguredSkinWhenViewFileExists(): void
    {
        $path = $this->resolve('basic', 'BookList');

        $this->assertSame(self::SKIN_BASE . 'basic/BookList', $path);
        $this->assertFileExists($path . '.php');
    }

    public function testFallsBackToBasicWhenSkinLacksTheView(): void
    {
        // 커스텀 스킨이 View.php 만 만들고 BookList.php 는 두지 않은 상황
        $this->assertSame(
            self::SKIN_BASE . 'basic/BookList',
            $this->resolve('nonexistent_skin_xyz', 'BookList')
        );
    }

    public function testFallbackIsPerFileNotPerSkin(): void
    {
        // 폴백은 파일 단위 — 미설정(=basic) 이든 없는 스킨이든 View 도 동일하게 해석된다
        $this->assertSame(
            self::SKIN_BASE . 'basic/View',
            $this->resolve('nonexistent_skin_xyz', 'View')
        );
    }

    private function resolve(string $skinName, string $view): string
    {
        $configRepo = $this->createMock(ManualConfigRepository::class);
        $configRepo->method('getSkinName')->willReturn($skinName);

        $controller = new ManualController(
            $this->createMock(ManualService::class),
            $configRepo
        );

        $method = new \ReflectionMethod($controller, 'skinView');

        return $method->invoke($controller, 1, $view);
    }
}
