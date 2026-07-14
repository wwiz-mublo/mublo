<?php

namespace Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 프리뷰 토큰의 소유권 계약
 *
 * window.MubloFrontPreviewTokens 는 그 값을 실제로 쓰는 화면(블록 에디터·블록 행)이
 * 자기 뷰에서 발행한다. 관리자 셸(frame Head.php)은 관여하지 않는다.
 *
 * 이 계약이 깨져도 화면은 에러 없이 동작하고 색만 조용히 기본값으로 돌아가므로
 * (front-preview-tokens.js 는 설정이 없으면 그냥 층을 생략한다) 텍스트로 고정한다.
 */
class FrontPreviewTokensOwnershipTest extends TestCase
{
    /** 토큰을 소비하는 화면 = 발행해야 하는 화면 */
    private const CONSUMER_VIEWS = [
        '/views/Admin/Blockeditor/Index.php',
        '/views/Admin/Blockrow/Form.php',
        '/views/Admin/Blockrow/Index.php',
    ];

    /**
     * @return list<array{0: string}>
     */
    public static function consumerViewProvider(): array
    {
        return array_map(static fn (string $v): array => [$v], self::CONSUMER_VIEWS);
    }

    #[DataProvider('consumerViewProvider')]
    public function testConsumerViewPublishesTokensItself(string $view): void
    {
        $source = file_get_contents(MUBLO_ROOT_PATH . $view);

        $this->assertStringContainsString(
            'window.MubloFrontPreviewTokens = <?= json_encode($frontPreviewTokens',
            $source,
            "{$view} 는 컨트롤러가 넘긴 frontPreviewTokens 를 스스로 발행해야 한다"
        );
    }

    /**
     * 발행이 소비 스크립트보다 앞서야 한다 — front-preview-tokens.js 는 IIFE 라
     * 로드 시점에 전역을 읽는다.
     */
    #[DataProvider('consumerViewProvider')]
    public function testTokensArePublishedBeforeTheScriptThatReadsThem(string $view): void
    {
        $source = file_get_contents(MUBLO_ROOT_PATH . $view);

        $publish = strpos($source, 'window.MubloFrontPreviewTokens =');
        // 주석 언급이 아니라 실제 로드 지점을 기준으로 잰다.
        $load = strpos($source, "<script src=\"<?= asset('/assets/js/admin/front-preview-tokens.js')");

        $this->assertNotFalse($publish, "{$view} 에 토큰 발행이 없다");
        $this->assertNotFalse($load, "{$view} 에 front-preview-tokens.js 로드가 없다");
        $this->assertLessThan($load, $publish, "{$view} 는 스크립트 로드 전에 토큰을 발행해야 한다");
    }

    /**
     * 관리자 셸은 프론트 프리뷰를 몰라야 한다 — 세 화면짜리 기능을 전 관리자
     * 페이지에 깔지 않는다.
     */
    public function testAdminShellDoesNotPublishTokens(): void
    {
        $head = file_get_contents(MUBLO_ROOT_PATH . '/views/Admin/frame/basic/Head.php');

        $this->assertStringNotContainsString('MubloFrontPreviewTokens', $head);
        $this->assertStringNotContainsString('frontFrameSkin', $head);
    }

    /**
     * 렌더러도 마찬가지 — 프론트 theme_config 를 관리자 렌더 경로에서 읽지 않는다.
     */
    public function testAdminViewRendererDoesNotResolveFrontTheme(): void
    {
        $renderer = file_get_contents(MUBLO_ROOT_PATH . '/src/Core/Rendering/AdminViewRenderer.php');

        $this->assertStringNotContainsString('sitePrimaryColor', $renderer);
        $this->assertStringNotContainsString('frontFrameSkin', $renderer);
    }
}
