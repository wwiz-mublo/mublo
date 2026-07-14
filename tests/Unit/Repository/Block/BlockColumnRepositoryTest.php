<?php

namespace Tests\Unit\Repository\Block;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\QueryBuilder;
use Mublo\Repository\Block\BlockColumnRepository;
use PHPUnit\Framework\TestCase;

class BlockColumnRepositoryTest extends TestCase
{
    public function testFindAllByRowForDomainRequiresBothReferences(): void
    {
        $whereCalls = [];
        $query = $this->createMock(QueryBuilder::class);
        $query->method('where')->willReturnCallback(
            function (string $field, string $operator, mixed $value) use (&$whereCalls, $query): QueryBuilder {
                $whereCalls[] = [$field, $operator, $value];
                return $query;
            }
        );
        $query->method('orderBy')->willReturnSelf();
        $query->method('get')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('table')->with('block_columns')->willReturn($query);

        $this->assertSame([], (new BlockColumnRepository($db))->findAllByRowForDomain(10, 2));
        $this->assertContains(['row_id', '=', 10], $whereCalls);
        $this->assertContains(['domain_id', '=', 2], $whereCalls);
    }

    /**
     * 칸의 순서는 column_index 하나가 결정한다.
     * sort_order 는 읽는 곳이 없는 사본이지만, 생성 경로마다 값이 갈리지 않도록
     * replaceByRow() 가 두 값을 함께 채운다.
     */
    public function testReplaceByRowKeepsColumnIndexAndSortOrderInSync(): void
    {
        $inserted = [];
        $repository = new BlockColumnRepository($this->makeDb($inserted));

        $repository->replaceByRow(10, 1, [
            ['content_type' => 'html'],
            ['content_type' => 'board'],
            ['content_type' => 'banner'],
        ]);

        $this->assertCount(3, $inserted);

        foreach ($inserted as $index => $columnData) {
            $this->assertSame($index, $columnData['column_index']);
            $this->assertSame($index, $columnData['sort_order'], 'sort_order 는 column_index 와 같아야 한다');
        }
    }

    public function testReplaceByRowIgnoresCallerSuppliedOrdering(): void
    {
        $inserted = [];
        $repository = new BlockColumnRepository($this->makeDb($inserted));

        // 호출자가 순서를 지정해도 배열 순서가 이긴다
        $repository->replaceByRow(10, 1, [
            ['content_type' => 'html', 'column_index' => 99, 'sort_order' => 77],
        ]);

        $this->assertSame(0, $inserted[0]['column_index']);
        $this->assertSame(0, $inserted[0]['sort_order']);
    }

    public function testReplaceByRowRenumbersNonSequentialKeys(): void
    {
        $inserted = [];
        $repository = new BlockColumnRepository($this->makeDb($inserted));

        // 삭제된 칸 때문에 키가 비어 있는 배열이 들어와도 0부터 다시 매긴다
        $repository->replaceByRow(10, 1, [
            3 => ['content_type' => 'html'],
            7 => ['content_type' => 'board'],
        ]);

        $this->assertSame([0, 1], array_column($inserted, 'column_index'));
        $this->assertSame([0, 1], array_column($inserted, 'sort_order'));
    }

    public function testReplaceByRowBindsColumnsToRowAndDomain(): void
    {
        $inserted = [];
        $repository = new BlockColumnRepository($this->makeDb($inserted));

        $repository->replaceByRow(10, 7, [['content_type' => 'html', 'row_id' => 999, 'domain_id' => 2]]);

        $this->assertSame(10, $inserted[0]['row_id']);
        $this->assertSame(7, $inserted[0]['domain_id']);
    }

    public function testReplaceByRowEncodesJsonColumns(): void
    {
        $inserted = [];
        $repository = new BlockColumnRepository($this->makeDb($inserted));

        $repository->replaceByRow(10, 1, [[
            'content_type' => 'html',
            'content_config' => ['html' => '<p>한글</p>'],
            'content_items' => [['id' => 1]],
        ]]);

        $this->assertIsString($inserted[0]['content_config']);
        $this->assertSame(['html' => '<p>한글</p>'], json_decode($inserted[0]['content_config'], true));
        $this->assertStringContainsString('한글', $inserted[0]['content_config'], 'JSON_UNESCAPED_UNICODE');
        $this->assertIsString($inserted[0]['content_items']);
    }

    /**
     * @param array<int, array<string, mixed>> $inserted INSERT 페이로드를 받아 담는다
     */
    private function makeDb(array &$inserted): Database
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('delete')->willReturn(0);
        $queryBuilder->method('insert')->willReturnCallback(
            function (array $data) use (&$inserted): int {
                $inserted[] = $data;
                return count($inserted);
            }
        );

        $db = $this->createMock(Database::class);
        $db->method('table')->willReturn($queryBuilder);

        return $db;
    }
}
