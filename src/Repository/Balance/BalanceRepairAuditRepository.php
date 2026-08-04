<?php
declare(strict_types=1);
namespace Mublo\Repository\Balance;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;

/**
 * BalanceRepairAuditRepository
 *
 * Balance snapshot repair audit trail.
 *
 * This repository records administrative snapshot repairs only. It must not
 * affect the balance ledger sum.
 */
class BalanceRepairAuditRepository
{
    protected string $table = 'balance_repair_audits';
    protected Database $db;

    public function __construct(?Database $db = null)
    {
        // Database 에는 getInstance() 가 없다. 연결은 DatabaseManager 가 소유한다.
        $this->db = $db ?? DatabaseManager::getInstance()->connect();
    }

    /**
     * Create a repair audit row.
     */
    public function create(array $data): int
    {
        if (!isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        $this->db->table($this->table)->insert($data);

        return (int) $this->db->lastInsertId();
    }
}
