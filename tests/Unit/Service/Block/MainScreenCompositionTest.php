<?php

namespace Tests\Unit\Service\Block;

use Mublo\Service\Block\MainScreenComposition;
use PHPUnit\Framework\TestCase;

/**
 * "메인화면 구성" 슬롯 계산을 검증한다.
 *
 * slots() 는 FrontViewRenderer 의 슬롯 조립 규칙을 한 곳에 모은 것이다
 * (블록 에디터 설계 10.1 — 블록 행 관리 필터와 블록 에디터가 공유한다).
 * 프론트가 실제로 그리는 것과 어긋나면, 관리자가 "메인화면"에서 본 목록과
 * 방문자가 보는 화면이 달라진다. 그래서 레이아웃 타입별 조립 규칙을 못 박아 둔다.
 */
class MainScreenCompositionTest extends TestCase
{
    private function slots(array $siteConfig): array
    {
        return (new MainScreenComposition())->slots($siteConfig);
    }

    /**
     * use_main_layout 이 꺼져 있으면 메인화면은 layout_type=1(전체폭)로 강제된다.
     * 사이트가 사이드바 레이아웃이어도 메인화면엔 사이드바가 조립되지 않는다
     * (IndexController::index 의 규칙).
     */
    public function testMainLayoutOffForcesNoSidebarRegardlessOfSiteLayout(): void
    {
        $slots = $this->slots(['use_main_layout' => false, 'layout_type' => 'three-column']);

        $this->assertNotContains('left', $slots);
        $this->assertNotContains('right', $slots);
        $this->assertContains('index', $slots);
    }

    public function testMainLayoutOffByDefault(): void
    {
        // use_main_layout 키가 아예 없어도 꺼진 것으로 본다.
        $slots = $this->slots(['layout_type' => 'right-sidebar']);

        $this->assertNotContains('right', $slots);
    }

    /** right-sidebar → right 만 조립, left 는 없다. */
    public function testRightSidebarLayoutIncludesOnlyRight(): void
    {
        $slots = $this->slots(['use_main_layout' => true, 'layout_type' => 'right-sidebar']);

        $this->assertContains('right', $slots);
        $this->assertNotContains('left', $slots);
    }

    public function testLeftSidebarLayoutIncludesOnlyLeft(): void
    {
        $slots = $this->slots(['use_main_layout' => true, 'layout_type' => 'left-sidebar']);

        $this->assertContains('left', $slots);
        $this->assertNotContains('right', $slots);
    }

    public function testThreeColumnIncludesBothSidebars(): void
    {
        $slots = $this->slots(['use_main_layout' => true, 'layout_type' => 'three-column']);

        $this->assertContains('left', $slots);
        $this->assertContains('right', $slots);
    }

    public function testFullLayoutHasNoSidebar(): void
    {
        $slots = $this->slots(['use_main_layout' => true, 'layout_type' => 'full']);

        $this->assertNotContains('left', $slots);
        $this->assertNotContains('right', $slots);
    }

    /**
     * 슬롯은 화면 위→아래 조립 순서여야 한다. 목록이 그 순서로 나와야 운영자가
     * 화면 배치를 그대로 읽을 수 있다.
     */
    public function testSlotsAreInTopToBottomAssemblyOrder(): void
    {
        $slots = $this->slots(['use_main_layout' => true, 'layout_type' => 'three-column']);

        $this->assertSame(
            ['topbar', 'subhead', 'left', 'contenthead', 'index', 'contentfoot', 'right', 'subfoot'],
            $slots
        );
    }

    /** topbar 는 헤더 위(최상단)이므로 항상 첫 슬롯이다. */
    public function testTopbarIsFirstSlot(): void
    {
        $this->assertSame('topbar', $this->slots(['use_main_layout' => true, 'layout_type' => 'full'])[0]);
    }

    /** index 본문은 언제나 콘텐츠 헤더 다음, 콘텐츠 푸터 앞이다. */
    public function testIndexSitsBetweenContentHeadAndFoot(): void
    {
        $slots = $this->slots(['use_main_layout' => true, 'layout_type' => 'full']);

        $head = array_search('contenthead', $slots, true);
        $index = array_search('index', $slots, true);
        $foot = array_search('contentfoot', $slots, true);

        $this->assertNotFalse($index);
        $this->assertLessThan($index, $head);
        $this->assertLessThan($foot, $index);
    }

    /** layout_type 이 정수로 저장돼 있어도(문자열이 아닌 경우) 해석한다. */
    public function testIntegerLayoutTypeIsHandled(): void
    {
        $slots = $this->slots(['use_main_layout' => true, 'layout_type' => 3]);

        $this->assertContains('right', $slots);
        $this->assertNotContains('left', $slots);
    }

    /** rendersOnMain() 은 slots() 와 같은 규칙을 따른다. */
    public function testRendersOnMain(): void
    {
        $composition = new MainScreenComposition();
        $config = ['use_main_layout' => true, 'layout_type' => 'right-sidebar'];

        $this->assertTrue($composition->rendersOnMain('right', $config));
        $this->assertFalse($composition->rendersOnMain('left', $config));
        $this->assertTrue($composition->rendersOnMain('index', $config));
    }
}
