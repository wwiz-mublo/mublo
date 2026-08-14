<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Editor;

use Mublo\Service\Editor\OgMetaFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * OgMetaFetcher
 *
 * 이 클래스는 사용자가 준 주소로 서버가 요청을 내보내므로, 검사에서 통과시키는
 * 것과 막는 것이 곧 SSRF 경계다. 네트워크를 타지 않는 두 부분(주소 검사 ·
 * 메타 파싱)을 검증한다.
 */
class OgMetaFetcherTest extends TestCase
{
    private OgMetaFetcher $fetcher;

    protected function setUp(): void
    {
        $this->fetcher = new OgMetaFetcher();
    }

    #[DataProvider('blockedUrls')]
    public function testRejectsUnsafeUrls(string $url): void
    {
        $this->assertNull($this->fetcher->resolve($url), "막아야 할 주소가 통과했다: {$url}");
    }

    /** @return array<string, array{string}> */
    public static function blockedUrls(): array
    {
        return [
            '루프백'          => ['http://127.0.0.1/'],
            '루프백 IPv6'     => ['http://[::1]/'],
            '사설 10'         => ['http://10.0.0.5/'],
            '사설 192.168'    => ['http://192.168.0.1/'],
            '사설 172.16'     => ['http://172.16.0.9/'],
            '링크로컬'        => ['http://169.254.169.254/latest/meta-data/'],
            'IPv4 매핑 IPv6'  => ['http://[::ffff:127.0.0.1]/'],
            'file 스킴'       => ['file:///etc/passwd'],
            'gopher 스킴'     => ['gopher://example.com/'],
            'ftp 스킴'        => ['ftp://example.com/'],
            '스킴 없음'       => ['example.com'],
            '비표준 포트'     => ['http://93.184.216.34:8080/'],
            'SSH 포트'        => ['http://93.184.216.34:22/'],
            '자격증명 포함'   => ['http://user:pass@93.184.216.34/'],
            '호스트 없음'     => ['http:///path'],
            '빈 문자열'       => [''],
        ];
    }

    /** 공인 IP 리터럴은 통과하고, 검사한 IP 가 그대로 접속 대상이 된다 */
    public function testAllowsPublicAddressAndPinsResolvedIp(): void
    {
        $target = $this->fetcher->resolve('https://93.184.216.34/page');

        $this->assertSame(['host' => '93.184.216.34', 'port' => 443, 'ip' => '93.184.216.34'], $target);
    }

    public function testDefaultPortFollowsScheme(): void
    {
        $this->assertSame(80, $this->fetcher->resolve('http://93.184.216.34/')['port']);
        $this->assertSame(443, $this->fetcher->resolve('https://93.184.216.34/')['port']);
    }

    public function testParsesOpenGraphTagsInEitherAttributeOrder(): void
    {
        $html = '<html><head>'
            . '<meta property="og:title" content="제목 &amp; 부제">'
            . '<meta content="설명입니다" property="og:description">'
            . '<meta property="og:image" content="https://cdn.example.com/a.png">'
            . '</head><body>x</body></html>';

        $meta = $this->fetcher->parseMeta($html, 'https://www.example.com/article');

        $this->assertSame('제목 & 부제', $meta['title']);
        $this->assertSame('설명입니다', $meta['description']);
        $this->assertSame('https://cdn.example.com/a.png', $meta['image']);
        $this->assertSame('example.com', $meta['host'], 'www. 는 떼고 보여 준다');
    }

    public function testFallsBackToTitleAndDescriptionTags(): void
    {
        $html = '<html><head><title>  문서 제목  </title>'
            . '<meta name="description" content="일반 설명"></head><body>x</body></html>';

        $meta = $this->fetcher->parseMeta($html, 'https://example.com/');

        $this->assertSame('문서 제목', $meta['title']);
        $this->assertSame('일반 설명', $meta['description']);
    }

    /** 상대 경로 이미지는 같은 호스트로 채우고, 그 외 형태는 버린다 */
    #[DataProvider('imageCases')]
    public function testResolvesImageUrl(string $rawImage, string $expected): void
    {
        $html = '<meta property="og:image" content="' . $rawImage . '">';

        $meta = $this->fetcher->parseMeta($html, 'https://example.com/article');

        $this->assertSame($expected, $meta['image']);
    }

    /** @return array<string, array{string, string}> */
    public static function imageCases(): array
    {
        return [
            '절대 경로'        => ['/img/a.png', 'https://example.com/img/a.png'],
            '절대 URL'         => ['https://cdn.example.com/a.png', 'https://cdn.example.com/a.png'],
            '상대 경로는 버림' => ['img/a.png', ''],
            'data URI 는 버림' => ['data:image/svg+xml;base64,PHN2Zz4=', ''],
            'javascript 는 버림' => ['javascript:alert(1)', ''],
        ];
    }

    /** 응답이 길어도 본문에 담기는 길이는 제한된다 */
    public function testTruncatesLongValues(): void
    {
        $html = '<meta property="og:title" content="' . str_repeat('가', 400) . '">'
            . '<meta property="og:description" content="' . str_repeat('나', 700) . '">';

        $meta = $this->fetcher->parseMeta($html, 'https://example.com/');

        $this->assertSame(300, mb_strlen($meta['title']));
        $this->assertSame(500, mb_strlen($meta['description']));
    }
}
