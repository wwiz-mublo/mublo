<?php

namespace Tests\Unit\Service\Block;

use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Infrastructure\Storage\UploadResult;
use Mublo\Service\Block\BlockImageProcessor;
use Mublo\Service\Block\BlockImageMutationPlan;
use PHPUnit\Framework\TestCase;

class BlockImageProcessorTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/mublo-block-image-' . bin2hex(random_bytes(6));
        mkdir($this->tempRoot . '/public/storage', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempRoot);
    }

    public function testColumnUploadFailureKeepsOldImageAndReportsExactError(): void
    {
        $uploadedFile = $this->createMock(UploadedFile::class);
        $uploadedFile->method('isValid')->willReturn(true);

        $uploader = $this->createMock(FileUploader::class);
        $uploader->expects($this->once())
            ->method('upload')
            ->with($uploadedFile, 7, [
                'subdirectory' => 'block/images',
                'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            ])
            ->willReturn(UploadResult::failure('용량 초과'));

        $processor = $this->processor($uploader, $uploadedFile);
        $mutation = new BlockImageMutationPlan();
        $errors = [];
        $result = $processor->processColumnImages(
            1,
            [['pc_has_file' => 1, 'pc_image' => '/storage/D7/old.jpg']],
            7,
            $this->nestedFiles(),
            $mutation,
            $errors
        );

        $this->assertSame('/storage/D7/old.jpg', $result[0]['pc_image']);
        $this->assertArrayNotHasKey('pc_has_file', $result[0]);
        $this->assertSame(['2번 칸 1번 이미지(PC): 용량 초과'], $errors);
    }

    public function testSuccessfulColumnUploadReplacesAndDeletesOldStorageFile(): void
    {
        $oldDirectory = $this->tempRoot . '/public/storage/D7/block/images';
        mkdir($oldDirectory, 0777, true);
        file_put_contents($oldDirectory . '/old.jpg', 'old');

        $uploadedFile = $this->createMock(UploadedFile::class);
        $uploadedFile->method('isValid')->willReturn(true);

        $uploader = $this->createMock(FileUploader::class);
        $uploader->method('upload')->willReturn(UploadResult::success([
            'relative_path' => 'D7/block/images/2026/07',
            'stored_name' => 'new.jpg',
        ]));

        $processor = $this->processor($uploader, $uploadedFile);
        $mutation = new BlockImageMutationPlan();
        $errors = [];
        $result = $processor->processColumnImages(
            0,
            [['pc_has_file' => 1, 'pc_image' => '/storage/D7/block/images/old.jpg']],
            7,
            $this->nestedFiles(),
            $mutation,
            $errors
        );

        $this->assertSame('/storage/D7/block/images/2026/07/new.jpg', $result[0]['pc_image']);
        $this->assertFileExists($oldDirectory . '/old.jpg', 'DB 저장 전에는 기존 파일을 유지해야 한다');
        $processor->commit($mutation);
        $this->assertFileDoesNotExist($oldDirectory . '/old.jpg');
        $this->assertSame([], $errors);
    }

    public function testBackgroundMappingKeepsExistingImageAndRemovesFormOnlyFields(): void
    {
        $processor = new BlockImageProcessor(
            $this->createMock(FileUploader::class),
            $this->tempRoot . '/public',
            $this->tempRoot . '/public/storage'
        );

        $result = $processor->processBackgroundConfig([
            'name' => 'hero',
            'bg_color' => '#fff',
            'bg_gradient' => 'linear-gradient(red, blue)',
            'bg_image_old' => '/storage/D7/bg.jpg',
            'bg_size' => 'contain',
            'bg_position' => 'top center',
            'bg_repeat' => 'repeat-x',
            'bg_attachment' => 'fixed',
        ], 7, null, new BlockImageMutationPlan());

        $this->assertSame('hero', $result['name']);
        $this->assertSame([
            'color' => '#fff',
            'gradient' => 'linear-gradient(red, blue)',
            'image' => '/storage/D7/bg.jpg',
            'size' => 'contain',
            'position' => 'top center',
            'repeat' => 'repeat-x',
            'attachment' => 'fixed',
        ], $result['background_config']);
        $this->assertArrayNotHasKey('bg_image_old', $result);
        $this->assertArrayNotHasKey('bg_color', $result);
    }

    public function testTitleDeleteRemovesOnlyStorageFileAndClearsConfig(): void
    {
        $titleDirectory = $this->tempRoot . '/public/storage/D7/block/titles';
        mkdir($titleDirectory, 0777, true);
        file_put_contents($titleDirectory . '/old.jpg', 'old');

        $processor = new BlockImageProcessor(
            $this->createMock(FileUploader::class),
            $this->tempRoot . '/public',
            $this->tempRoot . '/public/storage'
        );

        $mutation = new BlockImageMutationPlan();
        $result = $processor->processTitleImages(0, [
            'pc_image' => '/storage/D7/block/titles/old.jpg',
            'pc_image_del' => 1,
        ], 7, null, $mutation);

        $this->assertSame('', $result['pc_image']);
        $this->assertArrayNotHasKey('pc_image_del', $result);
        $this->assertFileExists($titleDirectory . '/old.jpg', '삭제는 저장 성공까지 지연되어야 한다');
        $processor->commit($mutation);
        $this->assertFileDoesNotExist($titleDirectory . '/old.jpg');
    }

    public function testTitleUploadFailureKeepsOldFileAndReportsError(): void
    {
        $titleDirectory = $this->tempRoot . '/public/storage/D7/block/titles';
        mkdir($titleDirectory, 0777, true);
        file_put_contents($titleDirectory . '/old.jpg', 'old');

        $uploadedFile = $this->createMock(UploadedFile::class);
        $uploadedFile->method('isValid')->willReturn(true);
        $uploader = $this->createMock(FileUploader::class);
        $uploader->method('upload')->willReturn(UploadResult::failure('저장 실패'));

        $processor = $this->processor($uploader, $uploadedFile);
        $mutation = new BlockImageMutationPlan();
        $errors = [];
        $result = $processor->processTitleImages(1, [
            'pc_image' => '/storage/D7/block/titles/old.jpg',
            'pc_image_has_file' => 1,
        ], 7, ['name' => []], $mutation, $errors);

        $this->assertSame('/storage/D7/block/titles/old.jpg', $result['pc_image']);
        $this->assertArrayNotHasKey('pc_image_has_file', $result);
        $this->assertFileExists($titleDirectory . '/old.jpg');
        $this->assertSame(['2번 칸 제목 이미지(PC): 저장 실패'], $errors);
    }

    public function testBackgroundUploadFailureKeepsOldFileAndReportsError(): void
    {
        $backgroundDirectory = $this->tempRoot . '/public/storage/D7/block/bg';
        mkdir($backgroundDirectory, 0777, true);
        file_put_contents($backgroundDirectory . '/old.jpg', 'old');

        $uploadedFile = $this->createMock(UploadedFile::class);
        $uploadedFile->method('isValid')->willReturn(true);
        $uploader = $this->createMock(FileUploader::class);
        $uploader->method('upload')->willReturn(UploadResult::failure('디스크 오류'));

        $processor = $this->processor($uploader, $uploadedFile);
        $mutation = new BlockImageMutationPlan();
        $errors = [];
        $result = $processor->processBackgroundConfig([
            'bg_image_old' => '/storage/D7/block/bg/old.jpg',
            'bg_size' => 'cover',
        ], 7, ['name' => 'new.jpg'], $mutation, $errors);

        $this->assertSame('/storage/D7/block/bg/old.jpg', $result['background_config']['image']);
        $this->assertFileExists($backgroundDirectory . '/old.jpg');
        $this->assertSame(['배경 이미지: 디스크 오류'], $errors);
    }

    public function testInvalidTitleUploadIsReportedWithoutCallingUploader(): void
    {
        $uploadedFile = $this->createMock(UploadedFile::class);
        $uploadedFile->method('isValid')->willReturn(false);
        $uploadedFile->method('getErrorMessage')->willReturn('파일이 일부만 업로드되었습니다.');

        $uploader = $this->createMock(FileUploader::class);
        $uploader->expects($this->never())->method('upload');

        $processor = $this->processor($uploader, $uploadedFile);
        $mutation = new BlockImageMutationPlan();
        $errors = [];
        $result = $processor->processTitleImages(0, [
            'mo_image' => '/storage/D7/block/titles/old.jpg',
            'mo_image_has_file' => 1,
        ], 7, ['name' => []], $mutation, $errors);

        $this->assertSame('/storage/D7/block/titles/old.jpg', $result['mo_image']);
        $this->assertSame(
            ['1번 칸 제목 이미지(모바일): 파일이 일부만 업로드되었습니다.'],
            $errors
        );
    }

    public function testCollectManagedImagesFindsNestedAndInlineStorageUrlsOnce(): void
    {
        $processor = new BlockImageProcessor(
            $this->createMock(FileUploader::class),
            $this->tempRoot . '/public',
            $this->tempRoot . '/public/storage'
        );

        $images = $processor->collectManagedImages(
            ['image' => '/storage/D7/block/bg/hero.webp'],
            ['html' => '<img src="/storage/D7/block/images/a.jpg"><img src="/storage/D7/block/images/a.jpg">'],
            'https://cdn.example.test/external.jpg'
        );

        sort($images);
        $this->assertSame([
            '/storage/D7/block/bg/hero.webp',
            '/storage/D7/block/images/a.jpg',
        ], $images);
    }

    private function processor(FileUploader $uploader, UploadedFile $uploadedFile): BlockImageProcessor
    {
        return new class(
            $uploader,
            $this->tempRoot . '/public',
            $this->tempRoot . '/public/storage',
            $uploadedFile
        ) extends BlockImageProcessor {
            public function __construct(
                FileUploader $uploader,
                string $publicPath,
                string $storagePath,
                private UploadedFile $uploadedFile
            ) {
                parent::__construct($uploader, $publicPath, $storagePath);
            }

            protected function uploadedFileFromParts(
                mixed $name,
                mixed $type,
                mixed $tmpName,
                mixed $error,
                mixed $size
            ): ?UploadedFile {
                return $this->uploadedFile;
            }
        };
    }

    private function nestedFiles(): array
    {
        return [
            'name' => [1 => [0 => ['pc' => 'new.jpg']], 0 => [0 => ['pc' => 'new.jpg']]],
            'type' => [1 => [0 => ['pc' => 'image/jpeg']], 0 => [0 => ['pc' => 'image/jpeg']]],
            'tmp_name' => [1 => [0 => ['pc' => '/tmp/upload']], 0 => [0 => ['pc' => '/tmp/upload']]],
            'error' => [1 => [0 => ['pc' => UPLOAD_ERR_OK]], 0 => [0 => ['pc' => UPLOAD_ERR_OK]]],
            'size' => [1 => [0 => ['pc' => 100]], 0 => [0 => ['pc' => 100]]],
        ];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
