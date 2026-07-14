<?php

namespace Tests\Unit\Entity\Menu;

use Mublo\Entity\Menu\MenuItem;
use PHPUnit\Framework\TestCase;

/**
 * 메뉴 아이템별 레이아웃 오버라이드 규약 테스트.
 *
 * 핵심 계약: layout_type 이 상속(NULL)이면 getLayoutOverride() 는 null 을 반환해
 * FrontViewRenderer 가 이 메뉴에 대해 아무것도 주입하지 않고 도메인 기본을 쓰게 한다.
 * NULL(상속)과 0/빈값의 구분이 절대 뭉개지면 안 된다.
 */
class MenuItemLayoutOverrideTest extends TestCase
{
    public function testAbsentLayoutMeansInheritNoOverride(): void
    {
        $item = MenuItem::fromArray(['menu_code' => 'abc']);

        $this->assertNull($item->getLayoutType());
        $this->assertNull($item->getLayoutOverride(), '레이아웃 미지정 메뉴는 오버라이드 없음(null)이어야 한다');
    }

    public function testEmptyStringLayoutIsTreatedAsInherit(): void
    {
        // 관리자 폼이 빈 문자열을 보내면 0(전체)이 아니라 상속(NULL)으로 해석돼야 한다.
        $item = MenuItem::fromArray(['menu_code' => 'abc', 'layout_type' => '']);

        $this->assertNull($item->getLayoutType());
        $this->assertNull($item->getLayoutOverride());
    }

    public function testFullLayoutOverrideOmitsSidebarKeys(): void
    {
        // 전체 레이아웃 + 사이드바 값 미지정 → layout_type 만 담고 사이드바 키는 넣지 않는다
        // (넣으면 LayoutManager 에서 사이트 기본 사이드바 폭을 덮어쓰게 된다).
        $item = MenuItem::fromArray(['menu_code' => 'abc', 'layout_type' => 1]);

        $override = $item->getLayoutOverride();
        $this->assertSame(['layout_type' => 1], $override);
    }

    public function testSidebarOverridesAreMergedWhenSet(): void
    {
        $item = MenuItem::fromArray([
            'menu_code' => 'abc',
            'layout_type' => 3,
            'sidebar_right_width' => 320,
            'sidebar_right_mobile' => 1,
        ]);

        $override = $item->getLayoutOverride();
        $this->assertSame(3, $override['layout_type']);
        $this->assertSame(320, $override['sidebar_right_width']);
        $this->assertTrue($override['sidebar_right_mobile']);
        // 미지정 좌측 키는 상속이므로 병합 대상에서 빠져야 한다.
        $this->assertArrayNotHasKey('sidebar_left_width', $override);
        $this->assertArrayNotHasKey('sidebar_left_mobile', $override);
    }

    public function testToArrayPreservesInheritAsNull(): void
    {
        $item = MenuItem::fromArray(['menu_code' => 'abc']);
        $row = $item->toArray();

        $this->assertNull($row['layout_type']);
        $this->assertNull($row['sidebar_left_mobile']);
        $this->assertNull($row['sidebar_right_mobile']);
    }

    public function testToArrayEmitsMobileBoolAsIntWhenSet(): void
    {
        $item = MenuItem::fromArray([
            'menu_code' => 'abc',
            'layout_type' => 2,
            'sidebar_left_mobile' => 1,
        ]);
        $row = $item->toArray();

        $this->assertSame(2, $row['layout_type']);
        $this->assertSame(1, $row['sidebar_left_mobile']);
    }
}
