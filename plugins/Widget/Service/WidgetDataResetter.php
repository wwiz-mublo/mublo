<?php

namespace Mublo\Plugin\Widget\Service;

use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Infrastructure\Database\Database;

final class WidgetDataResetter implements DataResettableInterface
{
    public function __construct(private Database $db) {}
    public function getResetCategories(): array
    {
        return [new DataResetCategory('widgets', '위젯', '등록된 위젯 항목을 삭제합니다. (위젯 설정 보존)', 'bi-grid-1x2')];
    }
    public function reset(string $category, int $domainId): DataResetResult
    {
        if ($category !== 'widgets') return new DataResetResult(details: '알 수 없는 카테고리');
        if (!$this->db->tableExists('plugin_widget_items')) return new DataResetResult(details: '위젯 테이블 없음');
        $this->db->execute('DELETE FROM plugin_widget_items WHERE domain_id = ?', [$domainId]);
        return new DataResetResult(1, details: '위젯 항목 삭제 (설정 보존)');
    }
}
