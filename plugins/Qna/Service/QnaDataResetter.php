<?php
declare(strict_types=1);

namespace Mublo\Plugin\Qna\Service;

use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Infrastructure\Database\Database;

class QnaDataResetter implements DataResettableInterface
{
    public function __construct(private Database $db)
    {
    }

    public function getResetCategories(): array
    {
        return [
            new DataResetCategory('qna', 'Q&A', 'Q&A 글(질문·답변)과 유형을 모두 삭제합니다.', 'bi-patch-check'),
        ];
    }

    public function reset(string $category, int $domainId): DataResetResult
    {
        if ($category !== 'qna') {
            return new DataResetResult(details: '알 수 없는 카테고리');
        }

        $cleared = 0;
        if ($this->db->tableExists('qna_posts')) {
            $this->db->execute('DELETE FROM qna_posts WHERE domain_id = ? AND parent_id IS NOT NULL', [$domainId]);
            $this->db->execute('DELETE FROM qna_posts WHERE domain_id = ?', [$domainId]);
            $cleared++;
        }
        if ($this->db->tableExists('qna_categories')) {
            $this->db->execute('DELETE FROM qna_categories WHERE domain_id = ?', [$domainId]);
            $cleared++;
        }

        return new DataResetResult($cleared, details: 'Q&A 데이터(질문+답변)/유형 삭제');
    }
}
