<?php

namespace Tests\Unit\Helper\List;

use Mublo\Helper\List\ListColumnBuilder;
use Mublo\Helper\List\ListRenderHelper;
use PHPUnit\Framework\TestCase;

/**
 * 관리자 목록의 '번호' 칸은 DB 고유번호가 아니라 목록 번호다.
 *
 * 다른 사이트에서 회원을 옮겨 오거나 병렬로 넣으면 고유번호는 가입 순서와 어긋나고 빈 번호도 생긴다.
 * 목록 번호는 게시판과 같게 "전체 건수 − 앞 페이지 건수 − 순서" 로 매긴다.
 */
class ListRowNumberTest extends TestCase
{
    private function columns(array $options = ['id_key' => 'member_id']): array
    {
        return (new ListColumnBuilder())
            ->rowNumber('번호', $options)
            ->add('user_id', '아이디')
            ->build();
    }

    /** @return string[] 렌더 결과의 첫 칸 텍스트 */
    private function numbersOf(string $html): array
    {
        preg_match_all('/<tr\s*>\s*<td\s*>\s*(?:<span[^>]*>)?(\d+)/', $html, $m);
        return $m[1];
    }

    private function rows(int $count, int $firstId = 100): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['member_id' => $firstId - $i * 7, 'user_id' => 'u' . $i];
        }
        return $rows;
    }

    public function testNumbersCountDownFromTotalOnFirstPage(): void
    {
        $html = (new ListRenderHelper())
            ->setColumns($this->columns())
            ->setRows($this->rows(3))
            ->setPagination(['totalItems' => 75863, 'currentPage' => 1, 'perPage' => 20])
            ->render();

        $this->assertSame(['75863', '75862', '75861'], $this->numbersOf($html));
    }

    public function testLaterPageSkipsEarlierPages(): void
    {
        $html = (new ListRenderHelper())
            ->setColumns($this->columns())
            ->setRows($this->rows(2))
            ->setPagination(['totalItems' => 45, 'currentPage' => 3, 'perPage' => 20])
            ->render();

        // 1~2쪽 40건을 지나 3쪽은 5, 4
        $this->assertSame(['5', '4'], $this->numbersOf($html));
    }

    public function testWithoutPaginationNumbersFromOne(): void
    {
        $html = (new ListRenderHelper())
            ->setColumns($this->columns())
            ->setRows($this->rows(3))
            ->render();

        $this->assertSame(['1', '2', '3'], $this->numbersOf($html));
    }

    public function testInconsistentPaginationFallsBackToSequence(): void
    {
        // 페이지당 건수를 모르는 2쪽, 전체 건수보다 행이 많은 경우 — 0 이하 번호를 내지 않는다
        $noPerPage = (new ListRenderHelper())
            ->setColumns($this->columns())
            ->setRows($this->rows(2))
            ->setPagination(['totalItems' => 30, 'currentPage' => 2])
            ->render();
        $tooFew = (new ListRenderHelper())
            ->setColumns($this->columns())
            ->setRows($this->rows(3))
            ->setPagination(['totalItems' => 2, 'currentPage' => 1, 'perPage' => 20])
            ->render();

        $this->assertSame(['1', '2'], $this->numbersOf($noPerPage));
        $this->assertSame(['1', '2', '3'], $this->numbersOf($tooFew));
    }

    public function testPaginationDoesNotLeakIntoNextList(): void
    {
        // 뷰는 요청 안에서 헬퍼 인스턴스 하나를 여러 목록이 같이 쓴다
        $helper = new ListRenderHelper();
        $helper->setColumns($this->columns())->setRows($this->rows(1))
            ->setPagination(['totalItems' => 500, 'currentPage' => 1, 'perPage' => 20])
            ->render();

        $second = $helper->setRows($this->rows(2))->render();

        $this->assertSame(['1', '2'], $this->numbersOf($second));
    }

    public function testDatabaseIdIsKeptAsTooltip(): void
    {
        $html = (new ListRenderHelper())
            ->setColumns($this->columns())
            ->setRows([['member_id' => 6, 'user_id' => 'a']])
            ->render();

        $this->assertStringContainsString('<span title="ID 6">1</span>', $html);
    }

    public function testNoTooltipWithoutIdKey(): void
    {
        $html = (new ListRenderHelper())
            ->setColumns($this->columns([]))
            ->setRows([['member_id' => 6, 'user_id' => 'a']])
            ->render();

        $this->assertStringNotContainsString('title="ID', $html);
        $this->assertSame(['1'], $this->numbersOf($html));
    }

    public function testRowNumberColumnIsNeverSortable(): void
    {
        $col = (new ListColumnBuilder())->rowNumber('번호', ['sortable' => true])->build()[0];

        $this->assertFalse($col['sortable']);
        $this->assertSame('row_number', $col['type']);

        $helper = (new ListRenderHelper())->setSort('member_id', 'DESC');
        $this->assertNull($helper->sortLink($col));
    }

    public function testListsWithoutRowNumberColumnAreUntouched(): void
    {
        $helper = new ListRenderHelper();
        $html = $helper
            ->setColumns((new ListColumnBuilder())->add('user_id', '아이디')->build())
            ->setRows([['user_id' => 'a']])
            ->setPagination(['totalItems' => 10, 'currentPage' => 1, 'perPage' => 20])
            ->render();

        $this->assertStringNotContainsString(ListRenderHelper::ROW_NUMBER_KEY, $html);
        $this->assertStringContainsString('<td>a</td>', preg_replace('/\s+/', '', $html));
    }
}
