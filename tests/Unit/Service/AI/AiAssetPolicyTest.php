<?php
namespace Tests\Unit\Service\AI;

use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Service\AI\AiAssetPolicy;
use PHPUnit\Framework\TestCase;

class AiAssetPolicyTest extends TestCase
{
    public function testRejectsFileOutsideAiAllowlistBeforeProviderCall(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getExtension')->willReturn('svg');
        $file->method('getMimeType')->willReturn('image/svg+xml');
        $file->method('getSize')->willReturn(100);

        $this->expectException(\InvalidArgumentException::class);
        (new AiAssetPolicy())->assertUpload($file);
    }

    public function testAcceptsRealRasterImageAndReturnsDimensions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mublo-ai-');
        $image = imagecreatetruecolor(8, 6);
        imagepng($image, $path);
        imagedestroy($image);
        try {
            $file = $this->createMock(UploadedFile::class);
            $file->method('isValid')->willReturn(true);
            $file->method('getExtension')->willReturn('png');
            $file->method('getMimeType')->willReturn('image/png');
            $file->method('getSize')->willReturn((int) filesize($path));
            $file->method('getTmpName')->willReturn($path);

            $result = (new AiAssetPolicy())->assertUpload($file);
            $this->assertTrue($result['is_image']);
            $this->assertSame(['width' => 8, 'height' => 6], $result['metadata']);
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsEncryptedPdf(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mublo-ai-');
        file_put_contents($path, "%PDF-1.7\n1 0 obj <</Encrypt 2 0 R>>\n");
        try {
            $file = $this->createMock(UploadedFile::class);
            $file->method('isValid')->willReturn(true);
            $file->method('getExtension')->willReturn('pdf');
            $file->method('getMimeType')->willReturn('application/pdf');
            $file->method('getSize')->willReturn((int) filesize($path));
            $file->method('getTmpName')->willReturn($path);

            $this->expectException(\InvalidArgumentException::class);
            (new AiAssetPolicy())->assertUpload($file);
        } finally {
            @unlink($path);
        }
    }
}
