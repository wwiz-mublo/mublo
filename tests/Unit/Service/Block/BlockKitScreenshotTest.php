<?php

namespace Tests\Unit\Service\Block;

use Mublo\Infrastructure\Image\ImageProcessor;
use Mublo\Service\Block\BlockKitScreenshot;
use PHPUnit\Framework\TestCase;

class BlockKitScreenshotTest extends TestCase
{
    private array $tempFiles = [];

    /** @var string[] store() 가 쓰는 임시 storage 루트 */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];

        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    public function testJpegSourceIsConvertedToWebpDataUri(): void
    {
        $this->requireGd(['imagewebp', 'imagejpeg']);

        $service = new BlockKitScreenshot(new ImageProcessor());
        $dataUri = $service->toDataUri($this->makeImage('jpeg', 2000, 1500));

        $this->assertNotNull($dataUri);
        $this->assertStringStartsWith('data:image/webp;base64,', $dataUri, '내보내기는 항상 webp 로 변환한다');

        // 블록 킷 파일에 jpeg 는 등장하지 않는다
        $this->assertStringNotContainsString('data:image/jpeg', $dataUri);
    }

    public function testConvertedScreenshotIsResizedAndStaysUnderSizeCap(): void
    {
        $this->requireGd(['imagewebp', 'imagepng']);

        $service = new BlockKitScreenshot(new ImageProcessor());
        $dataUri = $service->toDataUri($this->makeImage('png', 2400, 1800));

        $this->assertNotNull($dataUri);

        $binary = $service->decode($dataUri);
        $this->assertNotNull($binary);
        $this->assertLessThanOrEqual(BlockKitScreenshot::MAX_BYTES, strlen($binary));

        $info = getimagesizefromstring($binary);
        $this->assertSame(BlockKitScreenshot::WIDTH, $info[0]);
        $this->assertSame(BlockKitScreenshot::HEIGHT, $info[1]);
    }

    public function testMissingSourceDegradesToNull(): void
    {
        $service = new BlockKitScreenshot(new ImageProcessor());

        $this->assertNull($service->toDataUri('/no/such/file.png'));
    }

    public function testSvgDataUriIsRejected(): void
    {
        $service = new BlockKitScreenshot(new ImageProcessor());

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $dataUri = 'data:image/svg+xml;base64,' . base64_encode($svg);

        $this->assertFalse($service->isValidDataUri($dataUri), 'SVG 는 스크립트를 품을 수 있어 받지 않는다');
    }

    public function testJpegDataUriIsRejectedOnImport(): void
    {
        $this->requireGd(['imagejpeg']);

        $service = new BlockKitScreenshot(new ImageProcessor());
        $binary = file_get_contents($this->makeImage('jpeg', 10, 10));

        $this->assertFalse(
            $service->isValidDataUri('data:image/jpeg;base64,' . base64_encode($binary)),
            '가져오기는 webp 와 png 만 허용한다'
        );
    }

    public function testPolyglotPayloadWithImagePrefixIsRejected(): void
    {
        $service = new BlockKitScreenshot(new ImageProcessor());

        // 실제 이미지가 아닌 스크립트를 png 라고 신고하는 경우
        $dataUri = 'data:image/png;base64,' . base64_encode('<?php system($_GET["c"]); ?>');

        $this->assertFalse($service->isValidDataUri($dataUri));
    }

    public function testOversizedDataUriIsRejected(): void
    {
        $service = new BlockKitScreenshot(new ImageProcessor());

        $dataUri = 'data:image/png;base64,' . base64_encode(str_repeat('A', BlockKitScreenshot::MAX_BYTES + 1));

        $this->assertFalse($service->isValidDataUri($dataUri));
    }

    public function testNonDataUriInputIsRejected(): void
    {
        $service = new BlockKitScreenshot(new ImageProcessor());

        $this->assertFalse($service->isValidDataUri(null));
        $this->assertFalse($service->isValidDataUri(''));
        $this->assertFalse($service->isValidDataUri('https://example.com/shot.png'));
    }

    // =========================================================================
    // store() / remove() — 목록 썸네일 (설계 4.7-③)
    // =========================================================================

    /** 굽고 나면 웹에서 읽을 수 있는 경로를 돌려주고, 그 자리에 파일이 있다. */
    public function testStoreWritesThumbnailAndReturnsWebPath(): void
    {
        $this->requireGd(['imagewebp', 'imagepng']);

        $storage = $this->makeStorageDir();
        $service = new BlockKitScreenshot(new ImageProcessor(), $storage);

        $path = $service->store($this->makeDataUri('png'), 3, 42);

        $this->assertSame('/storage/kit-screenshots/D3/42.webp', $path);
        $this->assertFileExists($storage . '/kit-screenshots/D3/42.webp');
    }

    /**
     * decode() 는 getimagesizefromstring() 으로 "이미지처럼 보이는지" 확인할 뿐이다.
     * 이미지 헤더를 가진 폴리글랏은 그 검사를 통과한다. store() 는 GD 로 다시 인코딩하므로
     * 원본 바이트가 한 조각도 남지 않아야 한다.
     */
    public function testStoreReEncodesSoPolyglotPayloadDoesNotSurvive(): void
    {
        $this->requireGd(['imagewebp', 'imagepng']);

        $storage = $this->makeStorageDir();
        $service = new BlockKitScreenshot(new ImageProcessor(), $storage);

        // 진짜 PNG 뒤에 PHP 를 붙인다. getimagesizefromstring() 은 이걸 PNG 로 본다.
        $poisoned = file_get_contents($this->makeImage('png', 40, 30)) . '<?php system($_GET["c"]); ?>';
        $this->assertNotFalse(@getimagesizefromstring($poisoned), '전제: 폴리글랏이 이미지로 인식된다');

        $path = $service->store('data:image/png;base64,' . base64_encode($poisoned), 1, 9);
        $this->assertNotNull($path, '폴리글랏도 GD 가 읽을 수 있으므로 저장 자체는 된다');

        $written = file_get_contents($storage . '/kit-screenshots/D1/9.webp');
        $this->assertStringNotContainsString('<?php', $written, '재인코딩으로 페이로드가 사라져야 한다');
        $this->assertStringNotContainsString('system(', $written);
    }

    /** 스크린샷이 없는 블록 킷은 그냥 없는 것이다. 예외가 아니다. */
    public function testStoreWithoutValidDataUriReturnsNull(): void
    {
        $service = new BlockKitScreenshot(new ImageProcessor(), $this->makeStorageDir());

        $this->assertNull($service->store(null, 1, 1));
        $this->assertNull($service->store('data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=', 1, 1));
    }

    public function testRemoveDeletesTheThumbnail(): void
    {
        $this->requireGd(['imagewebp', 'imagepng']);

        $storage = $this->makeStorageDir();
        $service = new BlockKitScreenshot(new ImageProcessor(), $storage);
        $path = $service->store($this->makeDataUri('png'), 1, 5);

        $this->assertTrue($service->remove($path));
        $this->assertFileDoesNotExist($storage . '/kit-screenshots/D1/5.webp');
    }

    /**
     * screenshot_path 는 우리가 만들지만 DB 는 손으로 고칠 수 있다.
     * 스크린샷 디렉터리 밖을 가리키는 경로로 파일을 지우게 둘 수는 없다.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeRemovePaths')]
    public function testRemoveRefusesPathsOutsideTheScreenshotDirectory(mixed $path): void
    {
        $storage = $this->makeStorageDir();
        $service = new BlockKitScreenshot(new ImageProcessor(), $storage);

        // 지워지면 안 되는 파일을 storage 바로 아래 둔다.
        file_put_contents($storage . '/victim.txt', 'keep me');

        $this->assertFalse($service->remove($path));
        $this->assertFileExists($storage . '/victim.txt');
    }

    /** @return array<string, array{mixed}> */
    public static function unsafeRemovePaths(): array
    {
        return [
            'traversal' => ['/storage/kit-screenshots/../victim.txt'],
            'deep traversal' => ['/storage/kit-screenshots/D1/../../victim.txt'],
            'other storage dir' => ['/storage/victim.txt'],
            'absolute path' => ['/etc/passwd'],
            'no prefix' => ['victim.txt'],
            'null' => [null],
            'empty' => [''],
        ];
    }

    private function makeStorageDir(): string
    {
        $dir = sys_get_temp_dir() . '/mublo-kit-storage-' . uniqid('', true);
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function makeDataUri(string $format): string
    {
        $binary = file_get_contents($this->makeImage($format, 60, 45));

        return 'data:image/' . $format . ';base64,' . base64_encode($binary);
    }

    /**
     * @param string[] $functions
     */
    private function requireGd(array $functions): void
    {
        foreach ($functions as $function) {
            if (!function_exists($function)) {
                $this->markTestSkipped("GD {$function}() 미지원 환경");
            }
        }
    }

    private function makeImage(string $format, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 32, 96, 160));

        $path = tempnam(sys_get_temp_dir(), 'mublo-shot-src');
        $this->tempFiles[] = $path;

        match ($format) {
            'jpeg' => imagejpeg($image, $path, 90),
            'png' => imagepng($image, $path),
        };
        imagedestroy($image);

        return $path;
    }
}
