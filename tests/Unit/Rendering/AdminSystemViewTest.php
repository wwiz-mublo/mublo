<?php

namespace Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;

class AdminSystemViewTest extends TestCase
{
    public function testSuperAdminSeesDatabaseBackupButtonAndModal(): void
    {
        $html = $this->render(true);

        $this->assertStringContainsString('id="btn-backup-database"', $html);
        $this->assertStringContainsString('id="databaseBackupModal"', $html);
        $this->assertStringContainsString('/admin/system/backup-database', $html);
        $this->assertStringContainsString('백업 파일은 다운로드가 끝나면 서버에서 자동 삭제됩니다.', $html);
    }

    public function testNonSuperAdminDoesNotSeeDatabaseBackupControls(): void
    {
        $html = $this->render(false);

        $this->assertStringNotContainsString('id="btn-backup-database"', $html);
        $this->assertStringNotContainsString('id="databaseBackupModal"', $html);
    }

    private function render(bool $super): string
    {
        $pageTitle = '시스템 관리';
        $description = '시스템 관리';
        $cacheInfo = ['driver' => 'file', 'path' => '/tmp/cache'];
        $migrationStatuses = [[
            'source' => 'core',
            'name' => 'Core',
            'icon' => 'bi-box',
            'executed' => [],
            'pending' => [],
        ]];
        $totalPending = 0;
        $totalExecuted = 0;
        $tempFileInfo = [
            'editor' => ['count' => 0, 'size_human' => '0 B'],
            'secure' => ['count' => 0, 'size_human' => '0 B'],
            'total' => ['count' => 0, 'size_human' => '0 B'],
        ];
        $extensionLoadFailures = [];
        $resetItems = [];
        $isSuper = $super;
        $csrfToken = 'test-token';

        ob_start();
        include MUBLO_ROOT_PATH . '/views/Admin/System/Index.php';
        return (string) ob_get_clean();
    }
}
