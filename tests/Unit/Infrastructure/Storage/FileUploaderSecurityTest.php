<?php

namespace Tests\Unit\Infrastructure\Storage;

use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Infrastructure\Storage\SecureFileService;
use PHPUnit\Framework\TestCase;

class FileUploaderSecurityTest extends TestCase
{
    private ?string $tempRoot = null;
    private ?string $previousRemoteAddr = null;

    protected function tearDown(): void
    {
        if ($this->previousRemoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->previousRemoteAddr;
        }

        if ($this->tempRoot !== null && is_dir($this->tempRoot)) {
            $this->removeTree($this->tempRoot);
        }

        parent::tearDown();
    }

    public function testFileUploaderRejectsTraversalSubdirectory(): void
    {
        $uploader = new FileUploader();
        $method = new \ReflectionMethod($uploader, 'normalizeSubdirectory');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);

        $method->invoke($uploader, '../outside');
    }

    public function testFileUploaderNormalizesSafeSubdirectory(): void
    {
        $uploader = new FileUploader();
        $method = new \ReflectionMethod($uploader, 'normalizeSubdirectory');
        $method->setAccessible(true);

        $this->assertSame('block/images', $method->invoke($uploader, '/block/images/'));
    }

    public function testSecureFileServiceRejectsTraversalDownloadPath(): void
    {
        $service = new SecureFileService(sys_get_temp_dir(), 'test-secret');

        $this->expectException(\InvalidArgumentException::class);

        $service->generateDownloadUrl('../secret.txt');
    }

    public function testSecureFileServiceRejectsUnsafeCategoryOnMoveFinal(): void
    {
        $service = new SecureFileService(sys_get_temp_dir(), 'test-secret');

        $this->expectException(\InvalidArgumentException::class);

        $service->moveFinal('temp/D1/file.pdf', 1, '../orders', '1');
    }

    public function testSecureFileTokenUsesProvidedClientIpForBinding(): void
    {
        $basePath = $this->makeTempRoot();
        $fileDir = $basePath . '/D1/member-fields/123';
        mkdir($fileDir, 0777, true);
        $filePath = $fileDir . '/profile.pdf';
        file_put_contents($filePath, 'test');

        $this->previousRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '10.0.0.10';

        $service = new SecureFileService($basePath, 'test-secret');
        $url = $service->generateDownloadUrl(
            'D1/member-fields/123/profile.pdf',
            3600,
            [
                'bind_ip' => true,
                'client_ip' => '198.51.100.20',
            ]
        );

        $token = basename($url);

        $this->assertNull($service->resolveToken($token));
        $this->assertNotNull($service->resolveToken($token, '198.51.100.20'));
        $this->assertNull($service->resolveToken($token, '203.0.113.99'));
    }

    private function makeTempRoot(): string
    {
        $this->tempRoot = sys_get_temp_dir() . '/mublo-secure-' . bin2hex(random_bytes(6));
        mkdir($this->tempRoot, 0777, true);

        return $this->tempRoot;
    }

    private function removeTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child)) {
                $this->removeTree($child);
            } elseif (is_file($child)) {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
