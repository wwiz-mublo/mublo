<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Repository;

use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Mublo\Infrastructure\Database\Database;

final class SaasRepository
{
    public function __construct(private Database $db, private SensitiveValueCodecInterface $codec)
    {
    }

    /** @return array<string, mixed>|null */
    public function principal(int $frameworkDomainId, string $loginId): ?array
    {
        return $this->db->selectOne(
            "SELECT c.company_id, c.name AS company_name, u.user_id, u.login_id, u.nickname, u.role
               FROM ai_companies c
               JOIN ai_company_users u ON u.company_id = c.company_id
              WHERE c.framework_domain_id = ? AND c.status = 'ACTIVE'
                AND u.login_id = ? AND u.status = 'ACTIVE' LIMIT 1",
            [$frameworkDomainId, $loginId]
        );
    }

    /** @return array<string, int> */
    public function summary(string $companyId): array
    {
        $customer = $this->db->selectOne(
            "SELECT COUNT(*) AS total FROM ai_customer_directory
              WHERE company_id = ? AND management_status = 'MANAGED' AND deleted_at IS NULL",
            [$companyId]
        );
        $runs = $this->db->selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status = 'INSUFFICIENT_DATA' THEN 1 ELSE 0 END) AS insufficient,
                    SUM(CASE WHEN status IN ('ACTION_REQUIRED', 'FAILED_FINAL') THEN 1 ELSE 0 END) AS action_required,
                    SUM(CASE WHEN status NOT IN ('COMPLETED', 'INSUFFICIENT_DATA', 'FAILED_FINAL', 'CANCELLED') THEN 1 ELSE 0 END) AS active
               FROM ai_analysis_runs WHERE company_id = ?",
            [$companyId]
        );
        $devices = $this->db->selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END) AS active
               FROM ai_devices WHERE company_id = ?",
            [$companyId]
        );
        return [
            'customers' => (int) ($customer['total'] ?? 0),
            'analysis_total' => (int) ($runs['total'] ?? 0),
            'analysis_completed' => (int) ($runs['completed'] ?? 0),
            'analysis_insufficient' => (int) ($runs['insufficient'] ?? 0),
            'analysis_action_required' => (int) ($runs['action_required'] ?? 0),
            'analysis_active' => (int) ($runs['active'] ?? 0),
            'devices' => (int) ($devices['total'] ?? 0),
            'active_devices' => (int) ($devices['active'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recentBatches(string $companyId, int $limit = 10): array
    {
        $rows = $this->db->select(
            'SELECT b.*, COUNT(r.run_id) AS run_count,
                    SUM(CASE WHEN r.status IN (\'COMPLETED\', \'INSUFFICIENT_DATA\', \'FAILED_FINAL\', \'CANCELLED\') THEN 1 ELSE 0 END) AS terminal_count,
                    SUM(CASE WHEN r.status IN (\'ACTION_REQUIRED\', \'FAILED_FINAL\') THEN 1 ELSE 0 END) AS action_count
               FROM ai_analysis_batches b
               LEFT JOIN ai_analysis_runs r ON r.batch_id = b.batch_id
              WHERE b.company_id = ? GROUP BY b.batch_id ORDER BY b.created_at DESC LIMIT ' . max(1, min(50, $limit)),
            [$companyId]
        );
        foreach ($rows as &$row) {
            $terminal = (int) ($row['terminal_count'] ?? 0);
            $total = (int) ($row['run_count'] ?? 0);
            $row['display_status'] = (int) ($row['action_count'] ?? 0) > 0
                ? 'ACTION_REQUIRED' : ($total > 0 && $terminal === $total ? 'COMPLETED' : 'IN_PROGRESS');
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function recentCustomers(string $companyId, int $limit = 10): array
    {
        $rows = $this->db->select(
            'SELECT c.customer_id, c.display_name_ciphertext, c.management_status, c.updated_at,
                    r.status AS analysis_status, r.analysis_id, r.updated_at AS analysis_updated_at
               FROM ai_customer_directory c
               LEFT JOIN ai_analysis_runs r ON r.run_id = (
                    SELECT r2.run_id FROM ai_analysis_runs r2
                     WHERE r2.company_id = c.company_id AND r2.customer_id = c.customer_id
                     ORDER BY r2.created_at DESC LIMIT 1
               )
              WHERE c.company_id = ? AND c.deleted_at IS NULL
              ORDER BY CASE WHEN r.status IN (\'ACTION_REQUIRED\', \'FAILED_FINAL\') THEN 0 ELSE 1 END,
                       c.updated_at DESC LIMIT ' . max(1, min(50, $limit)),
            [$companyId]
        );
        foreach ($rows as &$row) {
            $name = $this->codec->decrypt((string) $row['display_name_ciphertext']);
            $row['display_name'] = $name ?? '복호화할 수 없는 고객';
            unset($row['display_name_ciphertext']);
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function workers(): array
    {
        return $this->db->select('SELECT * FROM ai_worker_heartbeats ORDER BY received_at DESC');
    }
}
