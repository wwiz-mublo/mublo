<?php

namespace Tests\Unit\Core\Report;

use Mublo\Core\Report\Audit\ReportAuditLogger;
use Mublo\Core\Report\Contract\MenuScopedReportInterface;
use Mublo\Core\Report\Contract\PermissionGateInterface;
use Mublo\Core\Report\Contract\ReportDefinitionInterface;
use Mublo\Core\Report\Document\ReportDocument;
use Mublo\Core\Report\Engine\ReportDefinitionRegistry;
use Mublo\Core\Report\Engine\ReportManager;
use Mublo\Core\Report\Engine\ReportRendererResolver;
use Mublo\Core\Report\Exception\ReportPermissionDeniedException;
use Mublo\Core\Report\Store\ReportFileStore;
use PHPUnit\Framework\TestCase;

/**
 * 다운로드 권한은 거부 목록 방식이라, 요청이 보낸 menuCode 를 믿으면 다운로드가 막힌
 * 관리자도 다른(또는 없는) 메뉴 코드를 보내 판정을 비켜 간다. 메뉴를 정한 리포트는
 * 요청 값과 무관하게 자기 메뉴로 판정해야 한다.
 */
final class ReportManagerMenuScopeTest extends TestCase
{
    public function testScopedReportIsJudgedByItsOwnMenu(): void
    {
        $gate = $this->createMock(PermissionGateInterface::class);
        $gate->expects($this->exactly(3))
            ->method('assertDownloadAllowed')
            ->with(7, '003_001')
            ->willThrowException(new ReportPermissionDeniedException('리포트 다운로드 권한이 없습니다.'));

        $manager = $this->manager($this->scopedDefinition(), $gate);

        $this->assertTrue($manager->generateDownload('scoped', [], 'csv', 7, 'NOT_A_MENU')->isFailure());
        $this->assertTrue($manager->generateChunk('scoped', [], null, 100, 7, '')->isFailure());
        $this->assertTrue($manager->mergeChunks('scoped', [], [], 'csv', 'x', 7, 'K_Shop_005')->isFailure());
    }

    public function testUnscopedReportKeepsRequestedMenu(): void
    {
        $definition = new class implements ReportDefinitionInterface {
            public function name(): string { return 'plain'; }
            public function build(array $filters): ReportDocument { return ReportDocument::create('plain'); }
        };

        $gate = $this->createMock(PermissionGateInterface::class);
        $gate->expects($this->once())
            ->method('assertDownloadAllowed')
            ->with(7, 'K_Shop_005')
            ->willThrowException(new ReportPermissionDeniedException('denied'));

        $this->manager($definition, $gate)->generateDownload('plain', [], 'csv', 7, 'K_Shop_005');
    }

    private function scopedDefinition(): MenuScopedReportInterface
    {
        return new class implements MenuScopedReportInterface {
            public function name(): string { return 'scoped'; }
            public function menuCode(): string { return '003_001'; }
            public function build(array $filters): ReportDocument { return ReportDocument::create('scoped'); }
        };
    }

    private function manager(ReportDefinitionInterface $definition, PermissionGateInterface $gate): ReportManager
    {
        $definitions = new ReportDefinitionRegistry();
        $definitions->register($definition);

        return new ReportManager(
            $definitions,
            $this->createMock(ReportRendererResolver::class),
            $gate,
            $this->createMock(ReportFileStore::class),
            $this->createMock(ReportAuditLogger::class)
        );
    }
}
