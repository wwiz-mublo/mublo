<?php

namespace Tests\Unit\Service\Frame;

use PHPUnit\Framework\TestCase;
use Mublo\Entity\Domain\Domain;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Repository\Frame\DomainFrameOverrideRepository;
use Mublo\Service\Domain\DomainResolver;
use Mublo\Service\Frame\DomainFrameService;

/**
 * 도메인 프레임 편집 서비스 테스트 — 게시 플래그·마커 스트립·되돌리기
 */
class DomainFrameServiceTest extends TestCase
{
    private function service(
        ?DomainFrameOverrideRepository $overrides = null,
        ?DomainRepository $domains = null,
        ?DomainResolver $resolver = null
    ): DomainFrameService {
        // 트랜잭션 목: 콜백을 그대로 실행 (예외는 전파 = 롤백 후 재던짐과 동일 관측)
        $db = $this->createMock(\Mublo\Infrastructure\Database\Database::class);
        $db->method('transaction')->willReturnCallback(fn (callable $cb) => $cb());

        return new DomainFrameService(
            $overrides ?? $this->createMock(DomainFrameOverrideRepository::class),
            $domains ?? $this->createMock(DomainRepository::class),
            $resolver ?? $this->createMock(DomainResolver::class),
            $db
        );
    }

    private function domainMock(array $themeConfig): Domain
    {
        $domain = $this->createMock(Domain::class);
        $domain->method('getThemeConfig')->willReturn($themeConfig);
        $domain->method('getDomain')->willReturn('demo.test');

        return $domain;
    }

    // ---- 마커 스트립 (§3.2 — 중복 주입 방지) ----

    public function testStripAssetMarkersRemovesMubloMarkers(): void
    {
        $html = "<header>\n<!-- MUBLO_CSS -->\n<!-- MUBLO_CSS_component -->\n<!-- MUBLO_JS -->\n<!-- 일반 주석 -->\n</header>";

        $stripped = DomainFrameService::stripAssetMarkers($html);

        $this->assertStringNotContainsString('MUBLO_CSS', $stripped);
        $this->assertStringNotContainsString('MUBLO_JS', $stripped);
        $this->assertStringContainsString('<!-- 일반 주석 -->', $stripped, '일반 주석은 보존');
        $this->assertStringContainsString('<header>', $stripped);
    }

    // ---- 플래그 읽기 ----

    public function testPublishedPartsReadsFlagBucket(): void
    {
        $this->assertSame([], DomainFrameService::publishedParts([]));
        $this->assertSame([], DomainFrameService::publishedParts(['frame_edit' => ['parts' => 'oops']]));
        $this->assertSame(
            ['header'],
            DomainFrameService::publishedParts(['frame_edit' => ['parts' => ['header', 'sidebar']]]),
            '알 수 없는 파트는 걸러진다'
        );
    }

    // ---- 시드 로드 (§3.6) ----

    public function testLoadSeedPrefersCurrentSkinAndFallsBackToBasic(): void
    {
        // basic 스킨은 공식 시드를 동봉한다
        $seed = DomainFrameService::loadSeed('basic', 'header');
        $this->assertSame('basic', $seed['skin']);
        $this->assertStringContainsString('{{menu_main}}', $seed['html']);

        // 시드 없는 스킨은 basic으로 폴백하고, skin 값으로 폴백 사실을 알린다
        $fallback = DomainFrameService::loadSeed('no-such-skin', 'footer');
        $this->assertSame('basic', $fallback['skin']);
        $this->assertStringContainsString('{{business_info}}', $fallback['html']);
    }

    public function testLoadSeedRejectsTraversalSkinAndUnknownPart(): void
    {
        $fallback = DomainFrameService::loadSeed('../basic', 'header');
        $this->assertSame('basic', $fallback['skin']);
        $this->assertStringContainsString('{{menu_main}}', $fallback['html']);

        $this->assertSame(
            ['html' => '', 'skin' => ''],
            DomainFrameService::loadSeed('basic', '../header')
        );
    }

    // ---- 초안 저장 ----

    public function testSaveDraftStripsMarkersBeforePersisting(): void
    {
        $overrides = $this->createMock(DomainFrameOverrideRepository::class);
        $overrides->expects($this->once())
            ->method('saveDraft')
            ->with(1, 'header', '<header></header>', '', '', 'basic', 7);

        $this->service($overrides)->saveDraft(1, 'header', '<header><!-- MUBLO_CSS --></header>', '', '', 'basic', 7);
    }

    public function testInvalidPartIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->saveDraft(1, 'sidebar', '<div></div>');
    }

    // ---- 게시 ----

    public function testPublishPromotesDraftAndTurnsFlagOn(): void
    {
        $overrides = $this->createMock(DomainFrameOverrideRepository::class);
        $overrides->method('publish')->willReturn(true);

        $domains = $this->createMock(DomainRepository::class);
        $domains->method('find')->willReturn($this->domainMock(['skin' => 'basic']));
        $domains->expects($this->once())
            ->method('updateThemeConfig')
            ->with(1, ['skin' => 'basic', 'frame_edit' => ['parts' => ['header']]])
            ->willReturn(true);

        $resolver = $this->createMock(DomainResolver::class);
        $resolver->expects($this->once())->method('invalidate')->with('demo.test');

        $result = $this->service($overrides, $domains, $resolver)->publish(1, 'header');

        $this->assertTrue($result->isSuccess());
    }

    public function testPublishFailsAtomicallyWhenFlagSaveFails(): void
    {
        $overrides = $this->createMock(DomainFrameOverrideRepository::class);
        $overrides->method('publish')->willReturn(true);

        $domains = $this->createMock(DomainRepository::class);
        $domains->method('find')->willReturn($this->domainMock(['skin' => 'basic']));
        $domains->method('updateThemeConfig')->willReturn(false); // 플래그 저장 실패

        $resolver = $this->createMock(DomainResolver::class);
        $resolver->expects($this->never())->method('invalidate'); // 커밋 전 실패 — 무효화 없음

        $prevErrorLog = (string) ini_get('error_log');
        ini_set('error_log', tempnam(sys_get_temp_dir(), 'mublo-test-log'));
        try {
            $result = $this->service($overrides, $domains, $resolver)->publish(1, 'header');
        } finally {
            ini_set('error_log', $prevErrorLog);
        }

        $this->assertFalse($result->isSuccess(), '플래그 저장 실패 시 게시 전체가 실패(롤백)해야 한다');
    }

    public function testPublishWithoutDraftFailsAndLeavesFlagUntouched(): void
    {
        $overrides = $this->createMock(DomainFrameOverrideRepository::class);
        $overrides->method('publish')->willReturn(false);

        $domains = $this->createMock(DomainRepository::class);
        $domains->expects($this->never())->method('updateThemeConfig');

        $result = $this->service($overrides, $domains)->publish(1, 'header');

        $this->assertFalse($result->isSuccess());
    }

    // ---- 스킨으로 되돌리기 (§3.7 탈출구 — 비파괴) ----

    public function testRevertToSkinUnpublishesButKeepsContent(): void
    {
        $overrides = $this->createMock(DomainFrameOverrideRepository::class);
        $overrides->expects($this->once())->method('unpublish')->with(1, 'header');
        $overrides->expects($this->never())->method('delete'); // 편집 내용은 절대 삭제하지 않는다

        $domains = $this->createMock(DomainRepository::class);
        $domains->method('find')->willReturn(
            $this->domainMock(['skin' => 'basic', 'frame_edit' => ['parts' => ['header']]])
        );
        // 마지막 파트가 빠지면 버킷째 제거된다
        $domains->expects($this->once())
            ->method('updateThemeConfig')
            ->with(1, ['skin' => 'basic'])
            ->willReturn(true);

        $resolver = $this->createMock(DomainResolver::class);
        $resolver->expects($this->once())->method('invalidate');

        $result = $this->service($overrides, $domains, $resolver)->revertToSkin(1, 'header');

        $this->assertTrue($result->isSuccess());
    }

    public function testRevertKeepsOtherPartsInFlag(): void
    {
        $overrides = $this->createMock(DomainFrameOverrideRepository::class);

        $domains = $this->createMock(DomainRepository::class);
        $domains->method('find')->willReturn(
            $this->domainMock(['frame_edit' => ['parts' => ['header', 'footer']]])
        );
        $domains->expects($this->once())
            ->method('updateThemeConfig')
            ->with(1, ['frame_edit' => ['parts' => ['footer']]])
            ->willReturn(true);

        $this->service($overrides, $domains)->revertToSkin(1, 'header');
    }

    // ---- 크기 제한 ----

    public function testSaveDraftRejectsOversizedContent(): void
    {
        $overrides = $this->createMock(DomainFrameOverrideRepository::class);
        $overrides->expects($this->never())->method('saveDraft');

        $huge = str_repeat('a', DomainFrameService::MAX_HTML_CHARS + 1);
        $result = $this->service($overrides)->saveDraft(1, 'header', $huge);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('허용 크기', $result->getMessage());
    }
}
