<?php

namespace Tests\Unit\Infrastructure\Storage;

use Mublo\Infrastructure\Storage\FileUploader;
use PHPUnit\Framework\TestCase;

/**
 * FileUploader::adoptFile 하드닝 검증.
 *
 * adoptFile은 클라이언트가 제어하는 temp 메타(extension)로 호출될 수 있으므로,
 * 그 값을 신뢰하지 않고 코어 allowlist + 실제 finfo MIME 일치를 강제해야 한다.
 * (확장자 위조 폴리글롯으로 웹루트에 실행/HTML 파일이 편입되는 저장형 XSS·RCE 차단)
 */
class FileUploaderAdoptFileTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/mublo_adopt_' . uniqid();
        mkdir($this->baseDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // 남은 파일 정리 (best-effort)
        if (is_dir($this->baseDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->baseDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->baseDir);
        }
    }

    private function makeSource(string $name, string $content): string
    {
        $path = $this->baseDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    private function validPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    public function testRejectsNonAllowlistedExtensionEvenWhenClientClaimsIt(): void
    {
        // HTML 폴리글롯을 avatar로 편입 시도 — allowlist 밖(html)이므로 거부되어야 함
        $src = $this->makeSource('poly', '<script>alert(document.cookie)</script>');
        $result = (new FileUploader($this->baseDir))->adoptFile($src, 1, [
            'extension'    => 'html',
            'subdirectory' => 'avatar',
            'include_date' => false,
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertFileExists($src); // 이동되지 않고 원본 그대로
    }

    public function testRejectsExtensionContentMismatch(): void
    {
        // 확장자는 jpg라 주장하지만 실제 내용은 이미지가 아님 → finfo 불일치로 거부
        $src = $this->makeSource('fake', 'this is not an image');
        $result = (new FileUploader($this->baseDir))->adoptFile($src, 1, [
            'extension'    => 'jpg',
            'subdirectory' => 'avatar',
            'include_date' => false,
        ]);

        $this->assertTrue($result->isFailure());
    }

    public function testRejectsDangerousExtension(): void
    {
        $src = $this->makeSource('shell', '<?php system($_GET[0]); ?>');
        $result = (new FileUploader($this->baseDir))->adoptFile($src, 1, [
            'extension'    => 'pht',
            'subdirectory' => 'avatar',
            'include_date' => false,
        ]);

        $this->assertTrue($result->isFailure());
    }

    public function testAcceptsRealImage(): void
    {
        $src = $this->makeSource('real', $this->validPng());
        $result = (new FileUploader($this->baseDir))->adoptFile($src, 1, [
            'extension'    => 'png',
            'subdirectory' => 'avatar',
            'include_date' => false,
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('image/png', $result->getMimeType());
        $this->assertFileDoesNotExist($src); // 원본은 이동됨
    }
}
