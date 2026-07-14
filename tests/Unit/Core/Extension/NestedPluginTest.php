<?php
namespace Tests\Unit\Core\Extension;

use Mublo\Core\Extension\NestedPlugin;
use PHPUnit\Framework\TestCase;

/**
 * 패키지 종속 플러그인 식별 규약 (NestedPlugin)
 *
 * 규약이 코어 여러 곳(스캔·로드·라우팅·에셋)에서 쓰이므로,
 * 표기 해석이 흔들리면 조용히 넓게 깨진다 — 여기서 고정한다.
 */
class NestedPluginTest extends TestCase
{
    public function test_isNested_는_슬래시_포함_이름만_참(): void
    {
        $this->assertTrue(NestedPlugin::isNested('Board/BoardReport'));
        $this->assertFalse(NestedPlugin::isNested('Banner'));
        $this->assertFalse(NestedPlugin::isNested(''));
    }

    public function test_parts_는_두_세그먼트만_허용(): void
    {
        $this->assertSame(['Board', 'BoardReport'], NestedPlugin::parts('Board/BoardReport'));
        $this->assertNull(NestedPlugin::parts('Banner'));
        $this->assertNull(NestedPlugin::parts('A/B/C'));
        $this->assertNull(NestedPlugin::parts('/B'));
        $this->assertNull(NestedPlugin::parts('A/'));
    }

    public function test_dir_는_호스트_패키지의_발견_응답을_따른다(): void
    {
        // Board 는 PluginHostInterface + 표준 trait — Plugins/ 규약 경로가 나온다
        $this->assertSame(
            MUBLO_PACKAGE_PATH . '/Board/' . NestedPlugin::SUBDIR . '/BoardReport',
            NestedPlugin::dir('Board/BoardReport')
        );
        $this->assertNull(NestedPlugin::dir('Banner'));
        // 호스트 패키지라도 답하지 않은 플러그인은 존재하지 않는다
        $this->assertNull(NestedPlugin::dir('Board/NoSuchPlugin'));
    }

    public function test_providerClass_는_호스트_패키지의_발견_응답을_따른다(): void
    {
        $this->assertSame(
            'Mublo\\Packages\\Board\\Plugins\\BoardReport\\BoardReportProvider',
            NestedPlugin::providerClass('Board/BoardReport')
        );
        $this->assertNull(NestedPlugin::providerClass('Banner'));
    }

    public function test_parentPackage_와_basename(): void
    {
        $this->assertSame('Board', NestedPlugin::parentPackage('Board/BoardReport'));
        $this->assertNull(NestedPlugin::parentPackage('Banner'));
        $this->assertSame('BoardReport', NestedPlugin::basename('Board/BoardReport'));
        $this->assertSame('Banner', NestedPlugin::basename('Banner'));
    }

    public function test_url_key_왕복(): void
    {
        $this->assertSame('Board.BoardReport', NestedPlugin::toUrlKey('Board/BoardReport'));
        $this->assertSame('Board/BoardReport', NestedPlugin::fromUrlKey('Board.BoardReport'));
        // 독립 플러그인 이름은 그대로 통과
        $this->assertSame('Banner', NestedPlugin::fromUrlKey('Banner'));
    }

    public function test_수용_여부는_패키지_Provider_의_인터페이스_구현이_결정(): void
    {
        // Board 는 PluginHostInterface 를 구현한 플러그인 수용 패키지
        $this->assertNotNull(NestedPlugin::hostProvider('Board'));
        $this->assertArrayHasKey('BoardReport', NestedPlugin::discover('Board'));

        // Shop도 표준 PluginHostTrait을 사용한다. 현재는 README만 있어 발견 결과가 비어 있다.
        $this->assertNotNull(NestedPlugin::hostProvider('Shop'));
        $this->assertSame([], NestedPlugin::discover('Shop'));

        // 존재하지 않는 패키지
        $this->assertNull(NestedPlugin::hostProvider('NoSuchPackage'));
    }

    public function test_BoardReport_manifest_는_Board_version_범위를_명시(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(MUBLO_PACKAGE_PATH . '/Board/Plugins/BoardReport/manifest.json'),
            true
        );

        $this->assertSame('Board', $manifest['parent']);
        $this->assertSame('>=1.0.0 <2.0.0', $manifest['requires']['package:Board']);
        $this->assertContains('board.article.read', $manifest['capabilities']);
    }
}
