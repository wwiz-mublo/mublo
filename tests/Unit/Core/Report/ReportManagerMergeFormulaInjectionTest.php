<?php

namespace Tests\Unit\Core\Report;

use Mublo\Core\Report\Audit\ReportAuditLogger;
use Mublo\Core\Report\Contract\PermissionGateInterface;
use Mublo\Core\Report\Contract\ReportDefinitionInterface;
use Mublo\Core\Report\Contract\RowProviderInterface;
use Mublo\Core\Report\Document\ColumnDefinition;
use Mublo\Core\Report\Document\ReportDocument;
use Mublo\Core\Report\Document\Section\TableSection;
use Mublo\Core\Report\Engine\ReportDefinitionRegistry;
use Mublo\Core\Report\Engine\ReportManager;
use Mublo\Core\Report\Engine\ReportRendererResolver;
use Mublo\Core\Report\Store\ReportFileStore;
use PHPUnit\Framework\TestCase;

final class ReportManagerMergeFormulaInjectionTest extends TestCase
{
    public function testChunkMergeSanitizesHeadersAndRows(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mublo-report-merge-');
        $this->assertNotFalse($file);

        try {
            $definitions = new ReportDefinitionRegistry();
            $definitions->register($this->definition());

            $store = $this->createMock(ReportFileStore::class);
            $store->method('createExportPath')->with('csv')->willReturn($file);
            $store->method('loadChunk')->with('chunk-1')->willReturn([
                ['name' => '=CMD()', 'amount' => -100],
            ]);
            $store->method('registerMergedFile')->willReturn([
                'fileId' => 'rep-test',
                'downloadUrl' => '/admin/report/files/rep-test',
                'expiresAt' => '2026-07-19T00:00:00+09:00',
                'sizeBytes' => 1,
            ]);

            $manager = new ReportManager(
                $definitions,
                $this->createMock(ReportRendererResolver::class),
                $this->createMock(PermissionGateInterface::class),
                $store,
                $this->createMock(ReportAuditLogger::class)
            );

            $result = $manager->mergeChunks('formula-test', [], ['chunk-1'], 'csv', 'test', 7, 'reports');
            $this->assertTrue($result->isSuccess(), $result->getMessage());

            $fp = fopen($file, 'rb');
            $this->assertNotFalse($fp);
            fread($fp, 3);
            $header = fgetcsv($fp, null, ',', '"', '\\');
            $row = fgetcsv($fp, null, ',', '"', '\\');
            fclose($fp);

            $this->assertSame(["'=Name", 'Amount'], $header);
            $this->assertSame(["'=CMD()", '-100'], $row);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function definition(): ReportDefinitionInterface
    {
        return new class implements ReportDefinitionInterface {
            public function name(): string { return 'formula-test'; }

            public function build(array $filters): ReportDocument
            {
                $provider = new class implements RowProviderInterface {
                    public function rows(): iterable { return []; }
                    public function totalCount(): ?int { return 0; }
                    public function isRewindable(): bool { return true; }
                    public function getChunk(int $offset, int $limit): array { return []; }
                };

                return ReportDocument::create('Formula merge')->addSection(new TableSection([
                    new ColumnDefinition('name', '=Name'),
                    new ColumnDefinition('amount', 'Amount', 'number'),
                ], $provider));
            }
        };
    }
}
