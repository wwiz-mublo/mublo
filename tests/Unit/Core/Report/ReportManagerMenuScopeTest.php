<?php

namespace Tests\Unit\Core\Report;

use Mublo\Core\Report\Audit\ReportAuditLogger;
use Mublo\Core\Report\Contract\MenuScopedReportInterface;
use Mublo\Core\Report\Contract\PermissionGateInterface;
use Mublo\Core\Report\Contract\ReportDefinitionInterface;
use Mublo\Core\Report\Document\ReportDocument;
use Mublo\Core\Report\Data\ArrayRowProvider;
use Mublo\Core\Report\Document\ColumnDefinition;
use Mublo\Core\Report\Document\Section\TableSection;
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

    public function testMergedFileKeepsScopedMenuAndRechecksPermissionOnDownload(): void
    {
        $dir = sys_get_temp_dir() . '/mublo-report-scope-' . bin2hex(random_bytes(8));
        $store = new ReportFileStore($dir);
        try {
            $allowed = true;
            $gate = $this->createMock(PermissionGateInterface::class);
            $gate->expects($this->exactly(4))->method('assertDownloadAllowed')
                ->with(7, '003_001')
                ->willReturnCallback(function () use (&$allowed): void {
                    if (!$allowed) {
                        throw new ReportPermissionDeniedException('denied');
                    }
                });
            $manager = $this->manager($this->scopedDefinition(), $gate, $store);
            $chunk = $manager->generateChunk('scoped', [], null, 100, 7, 'NOT_A_MENU');
            $this->assertTrue($chunk->isSuccess(), $chunk->getMessage());
            $merged = $manager->mergeChunks('scoped', [], [$chunk->get('chunkRef')], 'csv', 'test', 7, 'NOT_A_MENU');
            $this->assertTrue($merged->isSuccess(), $merged->getMessage());
            $fileId = $merged->get('fileId');
            $this->assertSame('003_001', $store->resolveMergedFile($fileId)['menu_code']);
            $this->assertTrue($manager->resolveMergedFile($fileId, 7)->isSuccess());
            $allowed = false;
            $this->assertTrue($manager->resolveMergedFile($fileId, 7)->isFailure());
            $this->assertTrue($manager->resolveMergedFile($fileId, 8)->isFailure());

            // 이전 배포에서 만든 파일은 잘못된 메뉴 코드로 권한 검사를 하기 전에 거부한다.
            $metaPath = $dir . '/report/meta/' . $fileId . '.json';
            $meta = json_decode(file_get_contents($metaPath), true);
            unset($meta['version']);
            $meta['menu_code'] = 'NOT_A_MENU';
            file_put_contents($metaPath, json_encode($meta));
            $this->assertTrue($manager->resolveMergedFile($fileId, 7)->isFailure());
        } finally {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
    }

    private function scopedDefinition(): MenuScopedReportInterface
    {
        return new class implements MenuScopedReportInterface {
            public function name(): string { return 'scoped'; }
            public function menuCode(): string { return '003_001'; }
            public function build(array $filters): ReportDocument {
                return ReportDocument::create('scoped')->addSection(new TableSection(
                    [new ColumnDefinition('name', 'Name')],
                    new ArrayRowProvider([['name' => 'test']])
                ));
            }
        };
    }

    private function manager(ReportDefinitionInterface $definition, PermissionGateInterface $gate, ?ReportFileStore $store = null): ReportManager
    {
        $definitions = new ReportDefinitionRegistry();
        $definitions->register($definition);

        return new ReportManager(
            $definitions,
            $this->createMock(ReportRendererResolver::class),
            $gate,
            $store ?? $this->createMock(ReportFileStore::class),
            $this->createMock(ReportAuditLogger::class)
        );
    }
}
