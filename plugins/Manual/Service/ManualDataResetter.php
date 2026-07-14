<?php

namespace Mublo\Plugin\Manual\Service;

use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Contract\DataResetFilesystemInterface;
use Mublo\Infrastructure\Database\Database;

class ManualDataResetter implements DataResettableInterface, DataResetFilesystemInterface
{
    public function __construct(private Database $db)
    {
    }

    public function getResetCategories(): array
    {
        return [
            new DataResetCategory('manual', '매뉴얼', '매뉴얼 책과 페이지를 모두 삭제합니다.', 'bi-book'),
        ];
    }

    public function reset(string $category, int $domainId): DataResetResult
    {
        if ($category !== 'manual') {
            return new DataResetResult(details: '알 수 없는 카테고리');
        }

        $cleared = 0;
        $booksExist = $this->db->tableExists('manual_books');
        if ($booksExist && $this->db->tableExists('manual_pages')) {
            $this->db->execute(
                'DELETE p FROM manual_pages p
                  INNER JOIN manual_books b ON b.book_id = p.book_id
                  WHERE b.domain_id = ?',
                [$domainId]
            );
            $cleared++;
        }

        if ($booksExist) {
            $this->db->execute('DELETE FROM manual_books WHERE domain_id = ?', [$domainId]);
            $cleared++;
        }

        return new DataResetResult(
            $cleared,
            details: '매뉴얼 책 및 페이지 삭제 (파일은 DB 커밋 후 정리)'
        );
    }

    public function resetFiles(string $category, int $domainId): int
    {
        return $category === 'manual' ? $this->deleteManualFiles($domainId) : 0;
    }

    private function deleteManualFiles(int $domainId): int
    {
        if (!defined('MUBLO_PUBLIC_STORAGE_PATH')) {
            return 0;
        }

        $domainBase = realpath(MUBLO_PUBLIC_STORAGE_PATH . '/D' . $domainId);
        $manualPath = realpath(MUBLO_PUBLIC_STORAGE_PATH . '/D' . $domainId . '/manual');
        if ($domainBase === false || $manualPath === false) {
            return 0;
        }

        $domainBase = rtrim($domainBase, '/\\');
        $boundaryBase = DIRECTORY_SEPARATOR === '\\' ? strtolower($domainBase) : $domainBase;
        $boundaryManual = DIRECTORY_SEPARATOR === '\\' ? strtolower($manualPath) : $manualPath;
        if (!str_starts_with($boundaryManual, $boundaryBase . DIRECTORY_SEPARATOR)) {
            return 0;
        }

        $deleted = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($manualPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } elseif (@unlink($item->getPathname())) {
                $deleted++;
            }
        }
        @rmdir($manualPath);

        return $deleted;
    }
}
