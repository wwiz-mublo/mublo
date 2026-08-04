<?php

namespace Tests\Unit\Core\Rendering;

use Mublo\Core\Rendering\FrontViewRenderer;
use Mublo\Enum\Block\LayoutType;
use PHPUnit\Framework\TestCase;

/**
 * 레이아웃 결정 우선순위 회귀 고정.
 *
 * 우선순위: 블록페이지 > 메뉴 오버라이드 > 스킨 힌트 > 헤더없음(full) > 도메인 기본.
 * 이 순서가 깨지면 "사이트는 우측 사이드바인데 이 메뉴만 전체" 같은 운영자 설정이 무력화된다.
 */
class FrontViewRendererLayoutPrecedenceTest extends TestCase
{
    /**
     * 1. 블록페이지 최우선 — pageConfig 에 layout_type 이 있으면
     *    메뉴 오버라이드가 있어도 절대 덮지 않는다.
     */
    public function testBlockPageLayoutWinsOverMenuOverride(): void
    {
        $result = FrontViewRenderer::applyLayoutPrecedence(
            ['layout_type' => LayoutType::RIGHT->value],          // 블록페이지가 정한 값
            ['layout_type' => LayoutType::FULL->value],           // 메뉴 오버라이드(무시돼야 함)
            'left',                                                // 스킨 힌트(무시돼야 함)
            true
        );

        $this->assertSame(LayoutType::RIGHT->value, $result['layout_type']);
    }

    /**
     * 2. 메뉴 오버라이드가 스킨 힌트를 이긴다 — 운영자 명시 지정 우선.
     *    사이드바 키까지 병합돼야 한다.
     */
    public function testMenuOverrideWinsOverSkinHintAndMergesSidebarKeys(): void
    {
        $result = FrontViewRenderer::applyLayoutPrecedence(
            [],
            ['layout_type' => LayoutType::RIGHT->value, 'sidebar_right_width' => 320],
            'full',                                                // 스킨 힌트(져야 함)
            true
        );

        $this->assertSame(LayoutType::RIGHT->value, $result['layout_type']);
        $this->assertSame(320, $result['sidebar_right_width']);
    }

    /**
     * 3. 메뉴 오버라이드가 없으면 스킨 힌트가 도메인 기본을 이긴다.
     */
    public function testSkinHintAppliesWhenNoMenuOverride(): void
    {
        $result = FrontViewRenderer::applyLayoutPrecedence([], null, 'both', true);

        $this->assertSame(LayoutType::BOTH->value, $result['layout_type']);
    }

    /**
     * 4. 오버라이드도 힌트도 없고 헤더를 끈 화면(로그인·가입)은 full 로 강제.
     */
    public function testHeaderlessForcesFull(): void
    {
        $result = FrontViewRenderer::applyLayoutPrecedence([], null, null, false);

        $this->assertSame(LayoutType::FULL->value, $result['layout_type']);
    }

    /**
     * 5. 아무것도 정해지지 않으면 layout_type 을 비운 채 둬
     *    LayoutManager 가 도메인(siteConfig) 기본을 쓰게 한다.
     */
    public function testFallsThroughToDomainDefault(): void
    {
        $result = FrontViewRenderer::applyLayoutPrecedence([], null, null, true);

        $this->assertArrayNotHasKey('layout_type', $result);
    }

    /**
     * 빈 문자열 스킨 힌트는 힌트 없음으로 취급 — 도메인 기본으로 떨어진다.
     */
    public function testEmptyStringHintIsIgnored(): void
    {
        $result = FrontViewRenderer::applyLayoutPrecedence([], null, '', true);

        $this->assertArrayNotHasKey('layout_type', $result);
    }
}
