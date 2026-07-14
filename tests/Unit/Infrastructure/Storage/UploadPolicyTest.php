<?php

namespace Tests\Unit\Infrastructure\Storage;

use Mublo\Infrastructure\Storage\UploadPolicy;
use PHPUnit\Framework\TestCase;

class UploadPolicyTest extends TestCase
{
    public function testAllowlistContainsExpectedSafeTypes(): void
    {
        $allowed = UploadPolicy::allowedExtensions();

        foreach (['jpg', 'png', 'gif', 'webp', 'pdf', 'zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'docx', 'hwp', 'hwpx', 'csv'] as $ext) {
            $this->assertContains($ext, $allowed, "{$ext}는 허용되어야 함");
        }
    }

    public function testArchiveMimesAreAccepted(): void
    {
        $this->assertTrue(UploadPolicy::matches('gz', 'application/gzip'));
        $this->assertTrue(UploadPolicy::matches('tar', 'application/x-tar'));
        $this->assertTrue(UploadPolicy::matches('tgz', 'application/gzip'));
    }

    public function testActiveContentAndExecutablesAreNotAllowed(): void
    {
        $allowed = UploadPolicy::allowedExtensions();

        // svg/html 및 실행/스크립트 확장자는 allowlist에 없어야 한다
        foreach (['svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'php', 'js', 'exe', 'sh'] as $ext) {
            $this->assertNotContains($ext, $allowed, "{$ext}는 허용되면 안 됨");
            $this->assertFalse(UploadPolicy::isAllowedExtension($ext));
        }
    }

    public function testMatchesAcceptsGenuineImage(): void
    {
        $this->assertTrue(UploadPolicy::matches('jpg', 'image/jpeg'));
        $this->assertTrue(UploadPolicy::matches('JPG', 'image/jpeg')); // 대소문자 무관
        $this->assertTrue(UploadPolicy::matches('png', 'image/png'));
    }

    public function testMatchesRejectsForgedExtension(): void
    {
        // svg/html 내용을 이미지 확장자로 위조 → finfo가 실제 MIME을 드러내 거부
        $this->assertFalse(UploadPolicy::matches('jpg', 'image/svg+xml'));
        $this->assertFalse(UploadPolicy::matches('png', 'text/html'));
    }

    public function testMatchesRejectsDisallowedExtensionEvenWithValidMime(): void
    {
        $this->assertFalse(UploadPolicy::matches('svg', 'image/svg+xml'));
        $this->assertFalse(UploadPolicy::matches('html', 'text/html'));
    }

    public function testMatchesFailsClosedOnUnknownMime(): void
    {
        $this->assertFalse(UploadPolicy::matches('jpg', null));
        $this->assertFalse(UploadPolicy::matches('jpg', ''));
    }

    public function testOfficeAndArchiveTolerateRealisticMimes(): void
    {
        // OOXML/HWPX는 zip 컨테이너로 감지됨
        $this->assertTrue(UploadPolicy::matches('docx', 'application/zip'));
        $this->assertTrue(UploadPolicy::matches('hwpx', 'application/zip'));
        // 구형 오피스/HWP는 OLE 또는 octet-stream으로 감지될 수 있음
        $this->assertTrue(UploadPolicy::matches('hwp', 'application/x-ole-storage'));
        $this->assertTrue(UploadPolicy::matches('doc', 'application/msword'));
    }
}
