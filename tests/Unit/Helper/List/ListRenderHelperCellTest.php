<?php

namespace Tests\Unit\Helper\List;

use Mublo\Helper\List\ListRenderHelper;
use PHPUnit\Framework\TestCase;

/**
 * renderCell 의 셀 값 타입 방어 계약.
 *
 * 이 헬퍼는 strict_types 파일이라, DB 드라이버가 int 컬럼을 네이티브 int 로
 * 돌려주면 htmlspecialchars(int) 가 TypeError 로 죽는다 — 메뉴 관리 목록의
 * item_id(번호) 컬럼에서 실제로 터진 회귀다 (2026-08-02). 셀 렌더는 값 타입이
 * 무엇이든 문자열을 돌려줘야 한다.
 */
class ListRenderHelperCellTest extends TestCase
{
    private ListRenderHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new ListRenderHelper();
    }

    public function testTextCellAcceptsIntValue(): void
    {
        // 메뉴 목록 크래시의 최소 재현: text(기본) 컬럼에 int 90
        $out = $this->helper->renderCell(['item_id' => 90], ['key' => 'item_id']);

        $this->assertSame('90', $out);
    }

    public function testTextCellAcceptsFloatAndNull(): void
    {
        $this->assertSame('1.5', $this->helper->renderCell(['v' => 1.5], ['key' => 'v']));
        $this->assertSame('', $this->helper->renderCell(['v' => null], ['key' => 'v']));
        $this->assertSame('', $this->helper->renderCell([], ['key' => 'missing']));
    }

    public function testTextCellStillEscapesStrings(): void
    {
        $out = $this->helper->renderCell(['v' => '<b>x</b>'], ['key' => 'v']);

        $this->assertSame('&lt;b&gt;x&lt;/b&gt;', $out);
    }

    public function testTextCellEscapesQuotesForAttributeContexts(): void
    {
        // ENT_QUOTES 정렬 확인 — 홑따옴표도 이스케이프되어야 속성 자리에서도 안전하다
        $out = $this->helper->renderCell(['v' => "a'b\"c"], ['key' => 'v']);

        $this->assertStringNotContainsString("'", $out);
        $this->assertStringNotContainsString('"', $out);
    }

    public function testHtmlCellAcceptsIntValue(): void
    {
        // html 분기도 반환 타입 선언(string) 때문에 int 면 똑같이 죽었다
        $this->assertSame('90', $this->helper->renderCell(['v' => 90], ['key' => 'v', 'type' => 'html']));
    }

    public function testHtmlCellKeepsMarkupUnescaped(): void
    {
        $html = '<span class="badge">on</span>';

        $this->assertSame($html, $this->helper->renderCell(['v' => $html], ['key' => 'v', 'type' => 'html']));
    }

    public function testLinkAndImageCellsAcceptIntValue(): void
    {
        // 비현실적이지만 같은 결함 클래스 — int 가 와도 죽지 않아야 한다
        $link = $this->helper->renderCell(['v' => 12345], ['key' => 'v', 'type' => 'link']);
        $this->assertStringContainsString('href="12345"', $link);

        $img = $this->helper->renderCell(['v' => 12345], ['key' => 'v', 'type' => 'image']);
        $this->assertStringContainsString('src="12345"', $img);
    }
}
