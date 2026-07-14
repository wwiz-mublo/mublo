<?php

namespace Tests\Unit\Infrastructure\Image;

use Mublo\Infrastructure\Image\ImageProcessor;
use PHPUnit\Framework\TestCase;

class ImageProcessorTest extends TestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testResizeToWebpWritesWebpAtRequestedSize(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is not available.');
        }

        $sourcePath = $this->tempPath('source.png');
        $destPath = $this->tempPath('result.webp');

        $image = imagecreatetruecolor(40, 20);
        $color = imagecolorallocate($image, 120, 80, 200);
        imagefill($image, 0, 0, $color);
        imagepng($image, $sourcePath);
        imagedestroy($image);

        $processor = new ImageProcessor();

        $this->assertTrue($processor->resizeToWebp($sourcePath, $destPath, 120, 90));
        $this->assertFileExists($destPath);

        $info = getimagesize($destPath);
        $this->assertSame(120, $info[0]);
        $this->assertSame(90, $info[1]);
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
    }

    private function tempPath(string $name): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('mublo-image-', true) . '-' . $name;
        $this->tempFiles[] = $path;

        return $path;
    }
}
