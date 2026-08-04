<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Block\Renderer;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Core\Block\Renderer\MenuRenderer;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Repository\Menu\MenuTreeRepository;
use PHPUnit\Framework\TestCase;

/**
 * MenuRenderer 계약 — scope('all'|'current') · active 판정 · 자동 숨김
 *
 * scope=current 는 서브페이지의 탭/LNB 내비용이다: 현재 URL 이 속한 1차 메뉴의
 * 하위만 렌더하고, 하위가 없으면 프론트에서 아무것도 출력하지 않는다(빈 탭바
 * 방지 — 킷이 사이트 메뉴 구조를 가정하지 않게 하는 장치).
 */
class MenuRendererTest extends TestCase
{
    private string $originalRequestUri = '/';

    public function testMenuRowsAreNeverSharedAcrossRequestPaths(): void
    {
        $this->assertTrue(BlockRegistry::isNoCache('menu'));
    }

    protected function setUp(): void
    {
        $this->originalRequestUri = $_SERVER['REQUEST_URI'] ?? '/';
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_URI'] = $this->originalRequestUri;
    }

    /**
     * 회사(하위: 인사말·연혁) / 사업(하위 없음) / 문의(하위 없음) 구조
     */
    private function renderer(): MenuRenderer
    {
        $repo = $this->createStub(MenuTreeRepository::class);
        $repo->method('findTreeWithItems')->willReturn([
            ['menu_code' => 'about', 'parent_code' => null, 'label' => '회사소개', 'url' => '/p/about'],
            ['menu_code' => 'greeting', 'parent_code' => 'about', 'label' => '인사말', 'url' => '/p/greeting'],
            ['menu_code' => 'history', 'parent_code' => 'about', 'label' => '연혁', 'url' => '/p/history'],
            ['menu_code' => 'business', 'parent_code' => null, 'label' => '사업분야', 'url' => '/p/business'],
            ['menu_code' => 'contact', 'parent_code' => null, 'label' => '문의', 'url' => '/p/contact'],
        ]);

        return new MenuRenderer($repo);
    }

    private function column(array $config, string $skin = 'tabs'): BlockColumn
    {
        return BlockColumn::fromArray([
            'domain_id' => 1,
            'content_type' => 'menu',
            'content_kind' => 'CORE',
            'content_skin' => $skin,
            'content_config' => json_encode($config),
        ]);
    }

    public function testCurrentScopeRendersOnlySectionChildrenWithActive(): void
    {
        $_SERVER['REQUEST_URI'] = '/p/history?tab=1';

        $html = $this->renderer()->render($this->column(['scope' => 'current']));

        // 현재 위치(연혁)가 속한 1차(회사소개)의 하위만 탭으로
        $this->assertStringContainsString('인사말', $html);
        $this->assertStringContainsString('연혁', $html);
        $this->assertStringNotContainsString('사업분야', $html);
        $this->assertStringNotContainsString('문의', $html);

        // 현재 항목 강조 (연혁 링크에 is-active)
        $this->assertMatchesRegularExpression('#/p/history[^>]*class="[^"]*is-active#', $html);
        $this->assertDoesNotMatchRegularExpression('#/p/greeting[^>]*class="[^"]*is-active#', $html);
    }

    public function testCurrentScopeHidesWhenSectionHasNoChildren(): void
    {
        // 사업분야는 1차이지만 하위가 없다 → 프론트에서 빈 탭바 대신 출력 생략
        $_SERVER['REQUEST_URI'] = '/p/business';

        $html = $this->renderer()->render($this->column(['scope' => 'current']));

        $this->assertSame('', $html);
    }

    public function testCurrentScopeHidesWhenLocationUnknown(): void
    {
        $_SERVER['REQUEST_URI'] = '/somewhere/else';

        $html = $this->renderer()->render($this->column(['scope' => 'current']));

        $this->assertSame('', $html);
    }

    public function testCurrentScopeMatchesByPathPrefixBoundary(): void
    {
        // /p/history/2020 은 /p/history 의 하위 경로로 일치해야 한다
        $_SERVER['REQUEST_URI'] = '/p/history/2020';

        $html = $this->renderer()->render($this->column(['scope' => 'current']));

        $this->assertMatchesRegularExpression('#/p/history[^>]*class="[^"]*is-active#', $html);
    }

    public function testDefaultScopeKeepsFullTree(): void
    {
        // scope 미지정(기존 동작) — 전체 트리가 그대로 렌더된다
        $_SERVER['REQUEST_URI'] = '/p/history';

        $html = $this->renderer()->render($this->column([], 'basic'));

        $this->assertStringContainsString('회사소개', $html);
        $this->assertStringContainsString('사업분야', $html);
        $this->assertStringContainsString('문의', $html);
    }

    public function testTreeSkinRendersSectionHeadAndOpensActiveTrail(): void
    {
        $_SERVER['REQUEST_URI'] = '/p/greeting';

        $html = $this->renderer()->render($this->column(['scope' => 'current'], 'tree'));

        // LNB 헤더 = 1차 라벨, 현재 항목 active
        $this->assertStringContainsString('회사소개', $html);
        $this->assertMatchesRegularExpression('#/p/greeting[^>]*class="[^"]*is-active#', $html);
        $this->assertStringNotContainsString('사업분야', $html);
    }
}
