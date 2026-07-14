<?php

namespace Tests\Unit\Service\Block;

use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Service\Block\BlockImageProcessor;
use Mublo\Service\Block\BlockImageMutationPlan;
use Mublo\Service\Block\BlockRowPayloadMapper;
use PHPUnit\Framework\TestCase;

class BlockRowPayloadMapperTest extends TestCase
{
    public function testMapsFormColumnsAndPreservesInvalidJsonForNormalizerDiagnostics(): void
    {
        $mapper = new BlockRowPayloadMapper(
            new BlockImageProcessor($this->createMock(FileUploader::class))
        );
        $errors = [];

        $result = $mapper->mapColumns([
            2 => [
                'width' => '50%',
                'content_type' => 'html',
                'content_config' => '{invalid-json',
                'title_config' => '',
                'ignored' => 'drop-me',
            ],
        ], 3, null, null, new BlockImageMutationPlan(), $errors);

        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]['column_index']);
        $this->assertSame('50%', $result[0]['width']);
        $this->assertSame('CORE', $result[0]['content_kind']);
        $this->assertSame('{invalid-json', $result[0]['content_config']);
        $this->assertArrayNotHasKey('title_config', $result[0]);
        $this->assertArrayNotHasKey('ignored', $result[0]);
        $this->assertSame([], $errors);
    }

    public function testImagePayloadIsDecodedWithoutHttpRequestDependency(): void
    {
        $mapper = new BlockRowPayloadMapper(
            new BlockImageProcessor($this->createMock(FileUploader::class))
        );

        $result = $mapper->mapColumns([[
            'content_type' => 'image',
            'content_items' => '[{"pc_image":"/storage/existing.jpg"}]',
        ]], 1, null, null, new BlockImageMutationPlan());

        $this->assertSame(
            [['pc_image' => '/storage/existing.jpg']],
            $result[0]['content_items']
        );
    }

    public function testStackContentImagesAreProcessedPerContentAndScalarIsSkipped(): void
    {
        $mapper = new BlockRowPayloadMapper(
            new BlockImageProcessor($this->createMock(FileUploader::class))
        );
        $mutation = new BlockImageMutationPlan();
        $errors = [];

        $result = $mapper->mapColumns([[
            'content_mode' => 'stack',
            'content_type' => 'image', // 미러 scalar — 서버 생성이라 처리 대상 아님
            'content_items' => '[{"pc_image":"/storage/mirror.png","pc_del":true}]',
            'contents' => [
                ['content_type' => 'html', 'content_config' => '{}'],
                ['content_type' => 'image', 'content_items' => '[{"pc_image":"/storage/child.png","pc_del":true}]'],
            ],
        ]], 1, null, null, $mutation, $errors, null);

        // 스택 칸의 scalar 는 그대로 통과 — 처리했다면 pc_del 이 반영됐을 것
        $this->assertSame(
            '[{"pc_image":"/storage/mirror.png","pc_del":true}]',
            $result[0]['content_items']
        );

        // 자식 콘텐츠는 콘텐츠 단위로 처리 — pc_del 반영 + 교체 대상 기록
        $this->assertSame([['pc_image' => '']], $result[0]['contents'][1]['content_items']);
        $this->assertContains('/storage/child.png', $mutation->obsoleteImages());
        $this->assertNotContains('/storage/mirror.png', $mutation->obsoleteImages());
        $this->assertSame([], $errors);
    }

    public function testStackContentTitleImagesAreProcessedPerContentAndScalarIsSkipped(): void
    {
        $mapper = new BlockRowPayloadMapper(
            new BlockImageProcessor($this->createMock(FileUploader::class))
        );
        $mutation = new BlockImageMutationPlan();
        $errors = [];

        $result = $mapper->mapColumns([[
            'content_mode' => 'stack',
            'title_config' => '{"pc_image":"/storage/mirror-title.png","pc_image_del":true}', // 미러 scalar
            'contents' => [
                ['content_type' => 'html', 'title_config' => '{"pc_image":"/storage/child-title.png","pc_image_del":true}'],
            ],
        ]], 1, null, null, $mutation, $errors, null, null);

        // 스택 칸의 scalar 제목은 그대로 통과 — 처리했다면 pc_image_del 이 반영됐을 것
        $this->assertSame(
            '{"pc_image":"/storage/mirror-title.png","pc_image_del":true}',
            $result[0]['title_config']
        );

        // 자식 콘텐츠 제목은 콘텐츠 단위로 처리 — 삭제 반영 + 교체 대상 기록
        $this->assertSame(['pc_image' => ''], $result[0]['contents'][0]['title_config']);
        $this->assertContains('/storage/child-title.png', $mutation->obsoleteImages());
        $this->assertNotContains('/storage/mirror-title.png', $mutation->obsoleteImages());
        $this->assertSame([], $errors);
    }
}
