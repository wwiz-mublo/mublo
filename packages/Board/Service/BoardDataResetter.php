<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Service;

use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Contract\DataResetFilesystemInterface;
use Mublo\Contract\Balance\BalanceResetGatewayInterface;
use Mublo\Infrastructure\Database\Database;

class BoardDataResetter implements DataResettableInterface, DataResetFilesystemInterface
{
    private const TABLES = [
        'board_reactions',
        'board_comments',
        'board_attachments',
        'board_links',
        'board_articles',
    ];

    public function __construct(
        private Database $db,
        private BalanceResetGatewayInterface $balanceResetGateway
    )
    {
    }

    public function getResetCategories(): array
    {
        return [
            new DataResetCategory('board', '게시판', '게시글, 댓글, 반응, 첨부파일을 삭제합니다. (게시판 설정/그룹/카테고리는 보존)', 'bi-collection'),
        ];
    }

    public function reset(string $category, int $domainId): DataResetResult
    {
        if ($category !== 'board') {
            return new DataResetResult(details: '알 수 없는 카테고리');
        }

        $cleared = 0;
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (self::TABLES as $table) {
                if ($this->db->tableExists($table)) {
                    $this->db->execute("DELETE FROM {$table} WHERE domain_id = ?", [$domainId]);
                    $cleared++;
                }
            }
        } finally {
            $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
        }

        $pointLogs = $this->balanceResetGateway->resetSource($domainId, 'package', 'Board');

        return new DataResetResult(
            $cleared + ($pointLogs > 0 ? 1 : 0),
            details: "게시글/댓글/반응/첨부파일 삭제 및 Board 포인트 원장 {$pointLogs}건 정합화 (설정/그룹/카테고리 보존)"
        );
    }

    public function resetFiles(string $category, int $domainId): int
    {
        if ($category !== 'board') {
            return 0;
        }

        $boardStoragePath = MUBLO_PUBLIC_STORAGE_PATH . '/D' . $domainId . '/board';
        if (is_dir($boardStoragePath)) {
            return $this->deleteDirectoryRecursive($boardStoragePath);
        }

        return 0;
    }

    private function deleteDirectoryRecursive(string $dir): int
    {
        $count = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
                $count++;
            }
        }
        rmdir($dir);

        return $count;
    }
}
