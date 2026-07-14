<?php

namespace Mublo\Plugin\Popup\Service;

use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Infrastructure\Database\Database;

class PopupDataResetter implements DataResettableInterface
{
    public function __construct(private Database $db)
    {
    }

    public function getResetCategories(): array
    {
        return [
            new DataResetCategory('popups', '팝업', '등록된 팝업을 모두 삭제합니다.', 'bi-window-stack'),
        ];
    }

    public function reset(string $category, int $domainId): DataResetResult
    {
        if ($category !== 'popups') {
            return new DataResetResult(details: '알 수 없는 카테고리');
        }

        $cleared = 0;
        foreach (['popups', 'plugin_popup_configs'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->execute("DELETE FROM {$table} WHERE domain_id = ?", [$domainId]);
                $cleared++;
            }
        }

        return new DataResetResult($cleared, details: '팝업 및 팝업 설정 삭제');
    }
}
