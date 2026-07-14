<?php

namespace Tests\Unit\Service\Block;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Service\Block\BlockColumnPayloadNormalizer;
use Mublo\Service\Block\BlockColumnWriteContext;
use Mublo\Service\Block\BlockContentSanitizer;
use Mublo\Service\Block\BlockSkinService;
use PHPUnit\Framework\TestCase;

/**
 * 스택 payload 정규화 (계획 6.3, 13.2).
 */
class BlockContentStackNormalizerTest extends TestCase
{
    private BlockColumnPayloadNormalizer $normalizer;

    protected function setUp(): void
    {
        BlockRegistry::reset();
        BlockRegistry::hasContentType('html');
        $this->normalizer = new BlockColumnPayloadNormalizer(
            new BlockContentSanitizer(),
            new BlockSkinService()
        );
    }

    protected function tearDown(): void
    {
        BlockRegistry::reset();
    }

    private function ctx(): BlockColumnWriteContext
    {
        return BlockColumnWriteContext::interactive(1, allowRawJs: true);
    }

    public function testStackColumnNormalizesContentsAndDropsLegacyFields(): void
    {
        $result = $this->normalizer->normalize([
            'content_mode' => 'stack',
            'pc_content_gap' => 16,
            'content_type' => 'html', // 스택 칸의 레거시 제출값 — 무시 (미러는 서버 생성)
            'contents' => [
                ['content_type' => 'html', 'content_config' => ['html' => '<p>a</p>']],
                ['content_type' => 'html', 'content_config' => ['html' => '<p>b</p>'], 'is_active' => 0],
            ],
        ], $this->ctx());

        $this->assertTrue($result->isOk());
        $column = $result->getNormalizedColumns()[0];

        $this->assertSame('stack', $column['content_mode']);
        $this->assertSame(16, $column['pc_content_gap']);
        $this->assertArrayNotHasKey('content_type', $column);
        $this->assertCount(2, $column['contents']);
        // 동일 타입 반복 허용 (계획 2.1)
        $this->assertSame('html', $column['contents'][0]['content_type']);
        $this->assertSame('html', $column['contents'][1]['content_type']);
        $this->assertSame(0, $column['contents'][1]['is_active']);
    }

    public function testStackWithoutContentsIsRejected(): void
    {
        $result = $this->normalizer->normalize([
            'content_mode' => 'stack',
            'contents' => [],
        ], $this->ctx());

        $this->assertFalse($result->isOk());
        $this->assertSame('stack_requires_contents', $result->getErrors()[0]['code']);
    }

    public function testUnknownContentModeIsRejected(): void
    {
        $result = $this->normalizer->normalize([
            'content_mode' => 'composite',
        ], $this->ctx());

        $this->assertFalse($result->isOk());
        $this->assertSame('invalid_content_mode', $result->getErrors()[0]['code']);
    }

    public function testContentsWithoutStackModeIsRejected(): void
    {
        $result = $this->normalizer->normalize([
            'contents' => [['content_type' => 'html']],
        ], $this->ctx());

        $this->assertFalse($result->isOk());
        $this->assertSame('contents_without_stack_mode', $result->getErrors()[0]['code']);
    }

    public function testDuplicateContentIdIsRejected(): void
    {
        $result = $this->normalizer->normalize([
            'content_mode' => 'stack',
            'contents' => [
                ['content_id' => 31, 'content_type' => 'html'],
                ['content_id' => 31, 'content_type' => 'html'],
            ],
        ], $this->ctx());

        $this->assertFalse($result->isOk());
        $codes = array_column($result->getErrors(), 'code');
        $this->assertContains('duplicate_content_id', $codes);
    }

    public function testDuplicateColumnIdAcrossPayloadIsRejected(): void
    {
        $result = $this->normalizer->normalizeMany([
            ['column_id' => 12, 'content_type' => 'html'],
            ['column_id' => 12, 'content_type' => 'html'],
        ], $this->ctx());

        $this->assertFalse($result->isOk());
        $codes = array_column($result->getErrors(), 'code');
        $this->assertContains('duplicate_column_id', $codes);
    }

    public function testGapIsClampedWithWarning(): void
    {
        $result = $this->normalizer->normalize([
            'content_mode' => 'stack',
            'pc_content_gap' => 9999,
            'mobile_content_gap' => -3,
            'contents' => [['content_type' => 'html']],
        ], $this->ctx());

        $this->assertTrue($result->isOk());
        $column = $result->getNormalizedColumns()[0];
        $this->assertSame(200, $column['pc_content_gap']);
        $this->assertSame(0, $column['mobile_content_gap']);
        $this->assertCount(2, $result->getWarnings());
    }

    public function testTooManyContentsIsRejected(): void
    {
        $contents = array_fill(0, BlockColumnPayloadNormalizer::MAX_CONTENTS + 1, ['content_type' => 'html']);

        $result = $this->normalizer->normalize([
            'content_mode' => 'stack',
            'contents' => $contents,
        ], $this->ctx());

        $this->assertFalse($result->isOk());
        $this->assertSame('too_many_contents', $result->getErrors()[0]['code']);
    }

    public function testStackContentErrorsCarryContentIndex(): void
    {
        $result = $this->normalizer->normalize([
            'content_mode' => 'stack',
            'contents' => [
                ['content_type' => 'html'],
                ['content_type' => 'missing_ext', 'content_kind' => 'PLUGIN'],
            ],
        ], $this->ctx());

        $this->assertFalse($result->isOk());
        $error = $result->getErrors()[0];
        // 오류 위치가 "몇 번째 칸 / 몇 번째 콘텐츠 / 어떤 필드"인지 지목 (계획 8.4)
        $this->assertSame('unregistered_content_type', $error['code']);
        $this->assertSame(1, $error['content_index']);
        $this->assertSame('contents.1.content_type', $error['field']);
    }

    public function testExistingContentIdPreservesUninstalledType(): void
    {
        // 확장 제거 후에도 content_id 를 가진 기존 콘텐츠는 원형 보존 —
        // 아니면 간격·순서만 바꿔도 행 저장 전체가 실패한다. 위조 content_id 는
        // 저장 계층 syncForColumn 의 소유권 예외가 거부한다.
        $result = $this->normalizer->normalize([
            'content_mode' => 'stack',
            'contents' => [
                ['content_type' => 'html', 'content_config' => ['html' => '<p>a</p>']],
                [
                    'content_id' => 31,
                    'content_type' => 'missing_ext',
                    'content_kind' => 'PLUGIN',
                    'content_config' => ['foo' => 'bar'],
                ],
            ],
        ], $this->ctx());

        $this->assertTrue($result->isOk());
        $warning = $result->getWarnings()[0];
        $this->assertSame('unresolved_extension', $warning['code']);
        $this->assertSame('contents.1.content_type', $warning['field']);

        $contents = $result->getNormalizedColumns()[0]['contents'];
        $this->assertSame(31, $contents[1]['content_id']);
        $this->assertSame('missing_ext', $contents[1]['content_type']);
        $this->assertSame(['foo' => 'bar'], $contents[1]['content_config']);
    }

    public function testLegacySinglePayloadResultIsUnchanged(): void
    {
        // 기존 single payload 결과 불변 (계획 13.2 최우선 항목)
        $result = $this->normalizer->normalize([
            'width' => '50%',
            'content_type' => 'html',
            'content_skin' => 'basic',
            'content_config' => ['html' => '<p>hi</p>'],
            'is_active' => 1,
        ], $this->ctx());

        $this->assertTrue($result->isOk());
        $column = $result->getNormalizedColumns()[0];
        $this->assertSame('html', $column['content_type']);
        $this->assertSame('CORE', $column['content_kind']);
        $this->assertSame('basic', $column['content_skin']);
        $this->assertArrayNotHasKey('content_mode', $column);
        $this->assertArrayNotHasKey('contents', $column);
        $this->assertArrayNotHasKey('column_id', $column);
    }
}
