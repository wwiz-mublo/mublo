<?php
declare(strict_types=1);

namespace Mublo\Plugin\Faq\Service;

use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Infrastructure\Database\Database;

class FaqDataResetter implements DataResettableInterface
{
    public function __construct(private Database $db)
    {
    }

    public function getResetCategories(): array
    {
        return [
            new DataResetCategory('faq', 'FAQ', 'FAQ 카테고리와 항목을 모두 삭제합니다.', 'bi-patch-question'),
        ];
    }

    public function reset(string $category, int $domainId): DataResetResult
    {
        if ($category !== 'faq') {
            return new DataResetResult(details: '알 수 없는 카테고리');
        }

        $cleared = 0;
        if ($this->db->tableExists('faq_items')) {
            $this->db->execute('DELETE FROM faq_items WHERE domain_id = ?', [$domainId]);
            $cleared++;
        }
        if ($this->db->tableExists('faq_categories')) {
            $this->db->execute('DELETE FROM faq_categories WHERE domain_id = ?', [$domainId]);
            $cleared++;
        }

        return new DataResetResult($cleared, details: 'FAQ 카테고리 및 항목 삭제');
    }
}
