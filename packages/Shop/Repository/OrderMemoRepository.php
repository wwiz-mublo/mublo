<?php

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\BaseRepository;

/**
 * OrderMemoRepository
 *
 * shop_order_memos 테이블 CRUD
 *
 * 책임:
 * - 관리자 메모 생성/조회/삭제
 */
class OrderMemoRepository extends BaseRepository
{
    protected string $table = 'shop_order_memos';
    protected string $primaryKey = 'memo_id';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    /**
     * 주문별 메모 목록 (최신순)
     */
    public function getByOrderNo(string $orderNo): array
    {
        return $this->getDb()->table($this->table)
            ->where('order_no', '=', $orderNo)
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    /**
     * 메모 생성
     */
    public function createMemo(array $data): int
    {
        if (!isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        if (!isset($data['updated_at'])) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        return $this->getDb()->table($this->table)->insert($data);
    }

    /**
     * 메모 단건 조회
     */
    public function findMemo(int $memoId): ?array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('memo_id', '=', $memoId)
            ->limit(1)
            ->get();

        return $rows[0] ?? null;
    }

    /**
     * 메모 삭제
     */
    public function deleteMemo(int $memoId): bool
    {
        $affected = $this->getDb()->table($this->table)
            ->where('memo_id', '=', $memoId)
            ->delete();

        return $affected > 0;
    }
}
