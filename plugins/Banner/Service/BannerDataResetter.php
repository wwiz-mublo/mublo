<?php
declare(strict_types=1);

namespace Mublo\Plugin\Banner\Service;

use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Contract\DataResetFilesystemInterface;
use Mublo\Infrastructure\Database\Database;

class BannerDataResetter implements DataResettableInterface, DataResetFilesystemInterface
{
    public function __construct(private Database $db)
    {
    }

    public function getResetCategories(): array
    {
        return [
            new DataResetCategory('banners', '배너', '등록된 배너를 모두 삭제합니다.', 'bi-images'),
        ];
    }

    public function reset(string $category, int $domainId): DataResetResult
    {
        if ($category !== 'banners') {
            return new DataResetResult(details: '알 수 없는 카테고리');
        }

        $cleared = 0;
        if ($this->db->tableExists('banners')) {
            $this->db->execute('DELETE FROM banners WHERE domain_id = ?', [$domainId]);
            $cleared++;
        }

        return new DataResetResult($cleared, details: '배너 데이터 삭제 (이미지는 DB 커밋 후 정리)');
    }

    public function resetFiles(string $category, int $domainId): int
    {
        if ($category !== 'banners') {
            return 0;
        }

        $bannerDir = MUBLO_PUBLIC_STORAGE_PATH . '/D' . $domainId . '/banner';
        if (is_dir($bannerDir)) {
            return $this->deleteDirectoryRecursive($bannerDir);
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
