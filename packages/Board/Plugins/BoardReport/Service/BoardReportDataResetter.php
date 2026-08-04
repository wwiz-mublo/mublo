<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Plugins\BoardReport\Service;

use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Infrastructure\Database\Database;

final class BoardReportDataResetter implements DataResettableInterface
{
    public function __construct(private Database $db) {}
    public function getResetCategories(): array
    {
        return [new DataResetCategory('board_reports', '게시판 신고', '게시글 신고 및 블라인드 이력을 삭제합니다.', 'bi-flag')];
    }
    public function reset(string $category, int $domainId): DataResetResult
    {
        if ($category !== 'board_reports') return new DataResetResult(details: '알 수 없는 카테고리');
        $cleared = 0;
        foreach (['board_report_blinds', 'board_reports'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->execute("DELETE FROM {$table} WHERE domain_id = ?", [$domainId]);
                $cleared++;
            }
        }
        return new DataResetResult($cleared, details: '게시판 신고·블라인드 이력 삭제');
    }
}
