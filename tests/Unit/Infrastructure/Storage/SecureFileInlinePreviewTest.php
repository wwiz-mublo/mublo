<?php

namespace Tests\Unit\Infrastructure\Storage;

use Mublo\Infrastructure\Storage\SecureFileService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 보안 파일 이미지 미리보기(inline) — 관리자 회원 수정 화면의 사진 확인용.
 *
 * - 래스터 이미지 메타에는 inline 토큰 주소(preview_url)를 따로 준다. 기존 url 은 첨부 그대로.
 * - 내려보낼 때는 실제 파일 MIME 으로 다시 판정해, SVG·HTML 등은 inline 토큰이어도 첨부로 내린다.
 */
#[CoversClass(SecureFileService::class)]
final class SecureFileInlinePreviewTest extends TestCase
{
    private string $basePath;
    private SecureFileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir() . '/mublo_inline_' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/D1/member-fields/10', 0755, true);
        file_put_contents($this->basePath . '/D1/member-fields/10/a.png', 'dummy');
        $this->service = new SecureFileService($this->basePath, 'test-secret-key');
    }

    protected function tearDown(): void
    {
        @unlink($this->basePath . '/D1/member-fields/10/a.png');
        @rmdir($this->basePath . '/D1/member-fields/10');
        @rmdir($this->basePath . '/D1/member-fields');
        @rmdir($this->basePath . '/D1');
        @rmdir($this->basePath);
        parent::tearDown();
    }

    private function meta(string $mime): string
    {
        return (string) json_encode([
            'disk'          => 'secure',
            'relative_path' => 'D1/member-fields/10/a.png',
            'stored_name'   => 'a.png',
            'original_name' => '사진.png',
            'size'          => 5,
            'mime_type'     => $mime,
            'extension'     => 'png',
        ]);
    }

    private function tokenOf(string $url): string
    {
        return substr($url, strlen('/download/'));
    }

    #[Test]
    public function imageMetaGetsInlinePreviewUrlWhileUrlStaysAttachment(): void
    {
        $result = $this->service->parseMetaWithUrl($this->meta('image/png'));

        $this->assertNotNull($result);
        $this->assertArrayHasKey('preview_url', $result);

        $download = $this->service->resolveToken($this->tokenOf($result['url']), '127.0.0.1');
        $preview = $this->service->resolveToken($this->tokenOf($result['preview_url']), '127.0.0.1');

        $this->assertSame('attachment', $download['disposition']);
        $this->assertSame('inline', $preview['disposition']);
        $this->assertSame('사진.png', $preview['filename']);
    }

    #[Test]
    public function nonImageMetaHasNoPreviewUrl(): void
    {
        foreach (['application/pdf', 'image/svg+xml', 'text/html', ''] as $mime) {
            $result = $this->service->parseMetaWithUrl($this->meta($mime));
            $this->assertArrayNotHasKey('preview_url', $result, $mime);
        }
    }

    /**
     * @return array<string, array{string, ?string, string}>
     */
    public static function dispositionCases(): array
    {
        return [
            'jpeg inline'          => ['inline', 'image/jpeg', 'inline'],
            'png inline'           => ['inline', 'image/png', 'inline'],
            'gif inline'           => ['inline', 'image/gif', 'inline'],
            'webp inline'          => ['inline', 'image/webp', 'inline'],
            'charset suffix'       => ['inline', 'IMAGE/PNG; charset=binary', 'inline'],
            'svg forced attach'    => ['inline', 'image/svg+xml', 'attachment'],
            'html forced attach'   => ['inline', 'text/html', 'attachment'],
            'pdf forced attach'    => ['inline', 'application/pdf', 'attachment'],
            'unknown forced'       => ['inline', null, 'attachment'],
            'attachment stays'     => ['attachment', 'image/png', 'attachment'],
        ];
    }

    #[Test]
    #[DataProvider('dispositionCases')]
    public function serveDispositionAllowsInlineOnlyForRasterImages(string $requested, ?string $mime, string $expected): void
    {
        $this->assertSame($expected, SecureFileService::resolveServeDisposition($requested, $mime));
    }
}
