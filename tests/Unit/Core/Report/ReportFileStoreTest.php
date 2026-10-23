<?php

namespace Tests\Unit\Core\Report;

use Mublo\Core\Report\Exception\ReportOutputException;
use Mublo\Core\Report\Store\ReportFileStore;
use PHPUnit\Framework\TestCase;

class ReportFileStoreTest extends TestCase
{
    private string $storagePath;
    private ReportFileStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'mublo-report-store-' . bin2hex(random_bytes(8));
        $this->store = new ReportFileStore($this->storagePath);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                if ($entry->isDir()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($this->storagePath);
        }

        parent::tearDown();
    }

    public function testInjectedStoragePathCreatesIsolatedDirectories(): void
    {
        $this->assertDirectoryExists($this->storagePath . '/report/exports');
        $this->assertDirectoryExists($this->storagePath . '/report/chunks');
        $this->assertDirectoryExists($this->storagePath . '/report/meta');

        $path = $this->store->createExportPath('csv');
        $this->assertStringStartsWith($this->storagePath . '/report/exports/rep_', $path);
        $this->assertMatchesRegularExpression('/_[a-f0-9]{32}\.csv$/', $path);
    }

    public function testChunkReferenceHas128BitsAndIsBoundToDomain(): void
    {
        $rows = [['member_id' => 1, 'name' => '홍길동']];
        $ref = $this->store->saveChunk($rows, 7);

        $this->assertMatchesRegularExpression(
            '/^tmp_chunk_\d{8}_\d{6}_[a-f0-9]{32}$/',
            $ref
        );
        $this->assertSame($rows, $this->store->loadChunk($ref, 7));

        $this->expectException(ReportOutputException::class);
        $this->store->loadChunk($ref, 8);
    }

    public function testMalformedChunkReferenceIsRejectedBeforePathLookup(): void
    {
        $this->expectException(ReportOutputException::class);
        $this->store->loadChunk('../tmp_chunk_20260719_120000_' . str_repeat('a', 32), 7);
    }

    public function testFileIdHas128BitsAndMetadataResolves(): void
    {
        $path = $this->store->createExportPath('csv');
        file_put_contents($path, "id,name\n1,test\n");

        $registered = $this->store->registerMergedFile($path, 'members.csv', 7, 'member.list');

        $this->assertMatchesRegularExpression(
            '/^rep_\d{8}_\d{6}_[a-f0-9]{32}$/',
            $registered['fileId']
        );
        $this->assertSame([
            'file_path' => $path,
            'filename' => 'members.csv',
            'domain_id' => 7,
            'menu_code' => 'member.list',
        ], $this->store->resolveMergedFile($registered['fileId']));
    }

    public function testLegacyUnexpiredFileCannotBeResolvedAndIsDiscarded(): void
    {
        $path = $this->store->createExportPath('csv');
        file_put_contents($path, "orderer_name\nexample\n");
        $registered = $this->store->registerMergedFile($path, 'orders.csv', 7, 'NOT_A_MENU');
        $metaPath = $this->storagePath . '/report/meta/' . $registered['fileId'] . '.json';
        $meta = json_decode(file_get_contents($metaPath), true);
        unset($meta['version']); // 패치 전 형식
        file_put_contents($metaPath, json_encode($meta));

        $this->assertGreaterThan(time(), $meta['expires_at']);
        $this->assertNull($this->store->resolveMergedFile($registered['fileId']));
        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($metaPath);
    }

    public function testCleanupDiscardsLegacyFilesBeforeExpiryButKeepsCurrentFiles(): void
    {
        $legacy = $this->store->createExportPath('csv');
        file_put_contents($legacy, 'legacy');
        $registered = $this->store->registerMergedFile($legacy, 'old.csv', 7, 'NOT_A_MENU');
        $metaPath = $this->storagePath . '/report/meta/' . $registered['fileId'] . '.json';
        $meta = json_decode(file_get_contents($metaPath), true);
        unset($meta['version']);
        file_put_contents($metaPath, json_encode($meta));

        $current = $this->store->createExportPath('csv');
        file_put_contents($current, 'current');
        $new = $this->store->registerMergedFile($current, 'new.csv', 7, 'K_Shop_005');
        $this->assertSame(1, $this->store->cleanupExpired());
        $this->assertFileDoesNotExist($legacy);
        $this->assertFileDoesNotExist($metaPath);
        $this->assertNotNull($this->store->resolveMergedFile($new['fileId']));
    }

    public function testSiblingDirectoryWithSamePrefixIsOutsideBoundary(): void
    {
        $sibling = $this->storagePath . '/report/exports-evil';
        mkdir($sibling, 0755, true);
        $path = $sibling . '/stolen.csv';
        file_put_contents($path, 'outside');

        $this->expectException(ReportOutputException::class);
        $this->store->registerMergedFile($path, 'stolen.csv', 7, 'member.list');
    }

    public function testExpiredTamperedMetadataCannotDeleteOutsideExportDirectory(): void
    {
        $exportPath = $this->store->createExportPath('csv');
        file_put_contents($exportPath, 'inside');
        $registered = $this->store->registerMergedFile($exportPath, 'inside.csv', 7, 'member.list');

        $outsidePath = $this->storagePath . '/do-not-delete.txt';
        file_put_contents($outsidePath, 'outside');
        $metaPath = $this->storagePath . '/report/meta/' . $registered['fileId'] . '.json';
        file_put_contents($metaPath, json_encode([
            'file_path' => $outsidePath,
            'expires_at' => time() - 1,
        ]));

        $this->assertNull($this->store->resolveMergedFile($registered['fileId']));
        $this->assertFileExists($outsidePath);
        $this->assertFileDoesNotExist($metaPath);
    }

    public function testDiscardExportDeletesOnlyFilesInsideExportDirectory(): void
    {
        $exportPath = $this->store->createExportPath('csv');
        file_put_contents($exportPath, 'partial');
        $outsidePath = $this->storagePath . '/outside.csv';
        file_put_contents($outsidePath, 'keep');

        $this->store->discardExport($exportPath);
        $this->store->discardExport($outsidePath);

        $this->assertFileDoesNotExist($exportPath);
        $this->assertFileExists($outsidePath);
    }

    public function testCleanupRemovesOldUnregisteredExportButKeepsRegisteredExport(): void
    {
        $orphan = $this->store->createExportPath('csv');
        file_put_contents($orphan, 'orphan');
        touch($orphan, time() - 7200);

        $registered = $this->store->createExportPath('csv');
        file_put_contents($registered, 'registered');
        $this->store->registerMergedFile($registered, 'registered.csv', 7, 'member.list');
        touch($registered, time() - 7200);

        $removed = $this->store->cleanupExpired();

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($orphan);
        $this->assertFileExists($registered);
    }
}
