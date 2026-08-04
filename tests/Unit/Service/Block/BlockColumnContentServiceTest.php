<?php

namespace Tests\Unit\Service\Block;

use Mublo\Entity\Block\BlockColumn;
use Mublo\Entity\Block\BlockColumnContent;
use Mublo\Repository\Block\BlockColumnContentRepository;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Service\Block\BlockColumnContentService;
use PHPUnit\Framework\TestCase;

/**
 * 칸 저장 경로 선택과 상태 전이 (계획 6.2.0, 6.3, 5.3, 5.4).
 *
 * 핵심 계약: 경로 선택은 payload 만 보지 않고 DB 현재 상태를 함께 본다 —
 * DB 가 stack 인 행이 replaceByRow(delete-reinsert)로 가면 FK cascade 로
 * 스택이 증발한다.
 */
class BlockColumnContentServiceTest extends TestCase
{
    private function dbColumn(int $columnId, string $mode = 'single'): BlockColumn
    {
        return BlockColumn::fromArray([
            'column_id' => $columnId,
            'row_id' => 1,
            'domain_id' => 1,
            'content_mode' => $mode,
        ]);
    }

    public function testPortableArraysIncludeInactiveStackContentsInOneBatch(): void
    {
        $columns = $this->createMock(BlockColumnRepository::class);
        $contents = $this->createMock(BlockColumnContentRepository::class);
        $contents->expects($this->once())
            ->method('findByColumnsForDomain')
            ->with([5], 1, true)
            ->willReturn([
                5 => [
                    BlockColumnContent::fromArray([
                        'content_id' => 31,
                        'column_id' => 5,
                        'domain_id' => 1,
                        'content_type' => 'html',
                        'is_active' => 0,
                    ]),
                ],
            ]);

        $portable = (new BlockColumnContentService($columns, $contents))
            ->columnsToPortableArrays([$this->dbColumn(5, 'stack'), $this->dbColumn(6)]);

        $this->assertSame(31, $portable[0]['contents'][0]['content_id']);
        $this->assertFalse($portable[0]['contents'][0]['is_active']);
        $this->assertArrayNotHasKey('contents', $portable[1]);
    }

    public function testSingleOnlyRowKeepsReplacePath(): void
    {
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->method('findAllByRowForDomain')->willReturn([$this->dbColumn(5)]);
        // 기존 경로 유지 + stable 전용 키(column_id/contents) 제거 확인
        $columns->expects($this->once())->method('replaceByRow')
            ->with(1, 1, [['content_type' => 'html', 'width' => '50%']]);
        $columns->expects($this->never())->method('updateColumnForDomain');

        $contents = $this->createMock(BlockColumnContentRepository::class);

        (new BlockColumnContentService($columns, $contents))->saveColumns(1, 1, [
            ['column_id' => 5, 'content_type' => 'html', 'width' => '50%'],
        ]);
    }

    public function testStackPayloadUsesSyncAndMirrorsFirstActiveContent(): void
    {
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->method('findAllByRowForDomain')->willReturn([]);
        $columns->expects($this->never())->method('replaceByRow');

        $capturedInsert = null;
        $columns->method('insertColumn')->willReturnCallback(
            function (int $rowId, int $domainId, array $data) use (&$capturedInsert): int {
                $capturedInsert = $data;
                return 12;
            }
        );

        $contents = $this->createMock(BlockColumnContentRepository::class);
        $contents->expects($this->once())->method('syncForColumn')
            ->with(12, 1, $this->callback(fn(array $c) => count($c) === 2));

        (new BlockColumnContentService($columns, $contents))->saveColumns(1, 1, [
            [
                'content_mode' => 'stack',
                'contents' => [
                    ['content_type' => 'html', 'content_config' => ['html' => '<p>a</p>'], 'is_active' => 0],
                    ['content_type' => 'board', 'content_kind' => 'PACKAGE', 'content_items' => [3], 'is_active' => 1],
                ],
            ],
        ]);

        // 미러 = 정렬상 첫 번째 "활성" 콘텐츠 (비활성 첫 항목이 아니라 board)
        $this->assertSame('board', $capturedInsert['content_type']);
        $this->assertSame('PACKAGE', $capturedInsert['content_kind']);
        $this->assertSame([3], $capturedInsert['content_items']);
    }

    public function testAllInactiveContentsClearLegacyMirror(): void
    {
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->method('findAllByRowForDomain')->willReturn([]);

        $capturedInsert = null;
        $columns->method('insertColumn')->willReturnCallback(
            function (int $rowId, int $domainId, array $data) use (&$capturedInsert): int {
                $capturedInsert = $data;
                return 12;
            }
        );

        $contents = $this->createMock(BlockColumnContentRepository::class);

        (new BlockColumnContentService($columns, $contents))->saveColumns(1, 1, [
            [
                'content_mode' => 'stack',
                'contents' => [
                    ['content_type' => 'html', 'is_active' => 0],
                ],
            ],
        ]);

        // 전 자식 비활성 → 레거시 미러 비움 (구버전이 비활성 콘텐츠를 출력하지 않게, 계획 5.4)
        $this->assertNull($capturedInsert['content_type']);
        $this->assertNull($capturedInsert['content_config']);
    }

    public function testStaleLegacyPayloadOnStackRowIsRejected(): void
    {
        // DB 는 stack 인데 column_id 가 전혀 없는 구형 payload — 위치 추측 갱신 금지 (계획 6.3)
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->method('findAllByRowForDomain')->willReturn([$this->dbColumn(5, 'stack')]);
        $columns->expects($this->never())->method('replaceByRow');
        $columns->expects($this->never())->method('updateColumnForDomain');

        $contents = $this->createMock(BlockColumnContentRepository::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/새로고침/');

        (new BlockColumnContentService($columns, $contents))->saveColumns(1, 1, [
            ['content_type' => 'html'],
        ]);
    }

    public function testLegacyEditWithColumnIdUpdatesFirstActiveContent(): void
    {
        // 검증된 호환 경로 (계획 5.4): column_id 는 있으나 content_mode 없는
        // 레거시 payload → 첫 활성 콘텐츠에 반영, 나머지 보존
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->method('findAllByRowForDomain')->willReturn([$this->dbColumn(5, 'stack')]);
        $columns->expects($this->once())->method('updateColumnForDomain')->willReturn(true);

        $existing = [
            BlockColumnContent::fromArray(['content_id' => 31, 'column_id' => 5, 'domain_id' => 1, 'content_type' => 'html', 'is_active' => 0]),
            BlockColumnContent::fromArray(['content_id' => 32, 'column_id' => 5, 'domain_id' => 1, 'content_type' => 'board', 'is_active' => 1]),
        ];

        $capturedSync = null;
        $contents = $this->createMock(BlockColumnContentRepository::class);
        $contents->method('findByColumnForDomain')->willReturn($existing);
        $contents->expects($this->once())->method('syncForColumn')->willReturnCallback(
            function (int $columnId, int $domainId, array $payload) use (&$capturedSync): array {
                $capturedSync = $payload;
                return [31, 32];
            }
        );

        (new BlockColumnContentService($columns, $contents))->saveColumns(1, 1, [
            ['column_id' => 5, 'content_type' => 'faq', 'content_kind' => 'PLUGIN'],
        ]);

        // 첫 "활성" 콘텐츠(32, board)가 갱신 대상 — 비활성 31 이 아님 (미러와 갱신 대상 일치)
        $this->assertCount(2, $capturedSync);
        $this->assertSame(31, $capturedSync[0]['content_id']);
        $this->assertSame('html', $capturedSync[0]['content_type']); // 보존
        $this->assertSame(32, $capturedSync[1]['content_id']);
        $this->assertSame('faq', $capturedSync[1]['content_type']);  // 반영
    }

    public function testLegacyEditRejectedWhenAllContentsInactive(): void
    {
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->method('findAllByRowForDomain')->willReturn([$this->dbColumn(5, 'stack')]);

        $contents = $this->createMock(BlockColumnContentRepository::class);
        $contents->method('findByColumnForDomain')->willReturn([
            BlockColumnContent::fromArray(['content_id' => 31, 'column_id' => 5, 'domain_id' => 1, 'content_type' => 'html', 'is_active' => 0]),
        ]);

        // 갱신 대상(첫 활성)이 없으면 레거시 갱신 거부 (계획 5.4)
        $this->expectException(\RuntimeException::class);

        (new BlockColumnContentService($columns, $contents))->saveColumns(1, 1, [
            ['column_id' => 5, 'content_type' => 'faq', 'content_kind' => 'PLUGIN'],
        ]);
    }

    public function testExplicitSingleReductionDeletesContents(): void
    {
        // 명시적 축소 (계획 5.3): content_mode=single + 유지 콘텐츠는 레거시 필드로
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->method('findAllByRowForDomain')->willReturn([$this->dbColumn(5, 'stack')]);
        $columns->expects($this->once())->method('updateColumnForDomain')->willReturn(true);

        $contents = $this->createMock(BlockColumnContentRepository::class);
        $contents->expects($this->once())->method('deleteByColumnForDomain')->with(5, 1);
        $contents->expects($this->never())->method('syncForColumn');

        (new BlockColumnContentService($columns, $contents))->saveColumns(1, 1, [
            ['column_id' => 5, 'content_mode' => 'single', 'content_type' => 'html'],
        ]);
    }

    public function testForeignColumnIdIsRejected(): void
    {
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->method('findAllByRowForDomain')->willReturn([$this->dbColumn(5, 'stack')]);

        $contents = $this->createMock(BlockColumnContentRepository::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/소유가 아닙니다/');

        (new BlockColumnContentService($columns, $contents))->saveColumns(1, 1, [
            ['column_id' => 999, 'content_mode' => 'stack', 'contents' => [['content_type' => 'html']]],
        ]);
    }

    public function testUnregisteredTypeChangeViaContentIdIsRejected(): void
    {
        // 미설치 타입 원형 보존은 "기존 그대로"만 — 기존 content_id 로 임의의
        // 미등록 타입을 심는 통로가 되면 안 된다 (계획 6.4 후속)
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->method('findAllByRowForDomain')->willReturn([$this->dbColumn(5, 'stack')]);
        $columns->method('updateColumnForDomain')->willReturn(true);

        $contents = $this->createMock(BlockColumnContentRepository::class);
        $contents->method('findByColumnForDomain')->willReturn([
            BlockColumnContent::fromArray(['content_id' => 31, 'column_id' => 5, 'domain_id' => 1, 'content_type' => 'missing_plugin_a', 'is_active' => 1]),
        ]);
        $contents->expects($this->never())->method('syncForColumn');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/변경할 수 없습니다/');

        (new BlockColumnContentService($columns, $contents))->saveColumns(1, 1, [
            [
                'column_id' => 5,
                'content_mode' => 'stack',
                'contents' => [
                    ['content_id' => 31, 'content_type' => 'arbitrary_unregistered', 'content_kind' => 'PLUGIN'],
                ],
            ],
        ]);
    }

    public function testUnregisteredTypeUnchangedIsPreserved(): void
    {
        // 확장 제거 후 재저장 — 기존과 동일한 미등록 타입은 원형 보존으로 통과
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->method('findAllByRowForDomain')->willReturn([$this->dbColumn(5, 'stack')]);
        $columns->method('updateColumnForDomain')->willReturn(true);

        $contents = $this->createMock(BlockColumnContentRepository::class);
        $contents->method('findByColumnForDomain')->willReturn([
            BlockColumnContent::fromArray(['content_id' => 31, 'column_id' => 5, 'domain_id' => 1, 'content_type' => 'missing_plugin_a', 'is_active' => 1]),
        ]);
        $contents->expects($this->once())->method('syncForColumn')->willReturn([31]);

        (new BlockColumnContentService($columns, $contents))->saveColumns(1, 1, [
            [
                'column_id' => 5,
                'content_mode' => 'stack',
                'contents' => [
                    ['content_id' => 31, 'content_type' => 'missing_plugin_a', 'content_kind' => 'PLUGIN'],
                ],
            ],
        ]);
    }

    public function testMissingColumnsAreDeletedByExcept(): void
    {
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->method('findAllByRowForDomain')->willReturn([
            $this->dbColumn(5, 'stack'),
            $this->dbColumn(6, 'single'),
        ]);
        $columns->method('updateColumnForDomain')->willReturn(true);
        $columns->expects($this->once())->method('deleteByRowExcept')->with(1, 1, [5]);

        $contents = $this->createMock(BlockColumnContentRepository::class);

        (new BlockColumnContentService($columns, $contents))->saveColumns(1, 1, [
            ['column_id' => 5, 'content_mode' => 'stack', 'contents' => [['content_type' => 'html']]],
        ]);
    }
}
