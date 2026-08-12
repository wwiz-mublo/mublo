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
    public function devices(string $companyId, int $limit = 20): array
    {
        return $this->db->select(
            'SELECT device_id, name, platform, app_version, os_version, status,
                    CASE WHEN fcm_token IS NULL OR fcm_token = \'\' THEN 0 ELSE 1 END AS fcm_ready,
                    last_seen_at, updated_at
               FROM ai_devices
              WHERE company_id = ?
              ORDER BY CASE WHEN status = \'ACTIVE\' THEN 0 ELSE 1 END,
                       COALESCE(last_seen_at, created_at) DESC
              LIMIT ' . max(1, min(50, $limit)),
            [$companyId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function recentSchedules(string $companyId, int $limit = 20): array
    {
        $rows = $this->db->select(
            'SELECT s.schedule_id, s.customer_id, s.channel, s.message_class, s.status,
                    s.device_status, s.scheduled_at, s.expires_at, s.completed_at,
                    s.last_error, s.updated_at, c.display_name_ciphertext,
                    o.status AS outbox_status, o.attempt_count, o.acknowledged_at
               FROM ai_message_schedules s
               JOIN ai_customer_directory c
                 ON c.company_id = s.company_id AND c.customer_id = s.customer_id
               LEFT JOIN ai_schedule_dispatch_outbox o ON o.dispatch_id = s.dispatch_id
              WHERE s.company_id = ?
              ORDER BY s.scheduled_at DESC
              LIMIT ' . max(1, min(50, $limit)),
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

    /** @return array<string, mixed>|null */
    public function subscription(string $companyId): ?array
    {
        return $this->db->selectOne(
            "SELECT p.plan_code, p.name, p.monthly_price_krw, p.customer_limit,
                    s.status AS subscription_status, s.started_at
               FROM ai_company_subscriptions s
               JOIN ai_subscription_plans p ON p.plan_code = s.plan_code
              WHERE s.company_id = ? AND p.status = 'ACTIVE' LIMIT 1",
            [$companyId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function companyUsers(string $companyId): array
    {
        return $this->db->select(
            "SELECT user_id, login_id, nickname, role, status, updated_at
               FROM ai_company_users
              WHERE company_id = ?
              ORDER BY CASE role WHEN 'OWNER' THEN 0 WHEN 'MANAGER' THEN 1 ELSE 2 END,
                       nickname ASC",
            [$companyId]
        );
    }

    /** @return array<string, int> */
    public function platformSummary(): array
    {
        $onlineAfter = gmdate('Y-m-d H:i:s', time() - 600);
        $companies = $this->db->selectOne(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END) AS active
               FROM ai_companies"
        );
        $users = $this->db->selectOne(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END) AS active
               FROM ai_company_users"
        );
        $devices = $this->db->selectOne(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN status = 'ACTIVE' AND last_seen_at >= ? THEN 1 ELSE 0 END) AS online
               FROM ai_devices",
            [$onlineAfter]
        );
        $analysis = $this->db->selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status IN ('ACTION_REQUIRED', 'FAILED_FINAL') THEN 1 ELSE 0 END) AS action_required,
                    SUM(CASE WHEN status NOT IN ('COMPLETED', 'INSUFFICIENT_DATA', 'FAILED_FINAL', 'CANCELLED') THEN 1 ELSE 0 END) AS active
               FROM ai_analysis_runs"
        );
        $delivery = $this->db->selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status IN ('PENDING', 'LEASED', 'SENT') THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'ACKED' THEN 1 ELSE 0 END) AS acknowledged,
                    SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed
               FROM ai_schedule_dispatch_outbox"
        );
        return [
            'companies' => (int) ($companies['total'] ?? 0),
            'active_companies' => (int) ($companies['active'] ?? 0),
            'users' => (int) ($users['total'] ?? 0),
            'active_users' => (int) ($users['active'] ?? 0),
            'devices' => (int) ($devices['total'] ?? 0),
            'active_devices' => (int) ($devices['active'] ?? 0),
            'online_devices' => (int) ($devices['online'] ?? 0),
            'analysis_total' => (int) ($analysis['total'] ?? 0),
            'analysis_active' => (int) ($analysis['active'] ?? 0),
            'analysis_action_required' => (int) ($analysis['action_required'] ?? 0),
            'delivery_total' => (int) ($delivery['total'] ?? 0),
            'delivery_pending' => (int) ($delivery['pending'] ?? 0),
            'delivery_acknowledged' => (int) ($delivery['acknowledged'] ?? 0),
            'delivery_failed' => (int) ($delivery['failed'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function platformCompanies(int $limit = 100): array
    {
        return $this->db->select(
            "SELECT c.company_id, c.slug, c.name, c.status, c.created_at, c.updated_at,
                    s.plan_code, p.name AS plan_name, p.customer_limit,
                    (SELECT COUNT(*) FROM ai_company_users u
                      WHERE u.company_id = c.company_id AND u.status = 'ACTIVE') AS user_count,
                    (SELECT COUNT(*) FROM ai_customer_directory cd
                      WHERE cd.company_id = c.company_id AND cd.management_status = 'MANAGED' AND cd.deleted_at IS NULL) AS customer_count,
                    (SELECT COUNT(*) FROM ai_devices d
                      WHERE d.company_id = c.company_id AND d.status = 'ACTIVE') AS device_count
               FROM ai_companies c
               LEFT JOIN ai_company_subscriptions s ON s.company_id = c.company_id
               LEFT JOIN ai_subscription_plans p ON p.plan_code = s.plan_code
              ORDER BY CASE WHEN c.status = 'ACTIVE' THEN 0 ELSE 1 END, c.created_at DESC
              LIMIT " . max(1, min(200, $limit))
        );
    }

    /** @return list<array<string, mixed>> */
    public function platformDelivery(int $limit = 100): array
    {
        return $this->db->select(
            "SELECT o.outbox_id, o.schedule_id, o.company_id, c.name AS company_name,
                    s.channel, s.status AS schedule_status, s.device_status, s.scheduled_at,
                    o.status AS outbox_status, o.attempt_count, o.available_at,
                    o.acknowledged_at, o.updated_at
               FROM ai_schedule_dispatch_outbox o
               JOIN ai_message_schedules s ON s.schedule_id = o.schedule_id
               JOIN ai_companies c ON c.company_id = o.company_id
              ORDER BY CASE o.status WHEN 'FAILED' THEN 0 WHEN 'PENDING' THEN 1 WHEN 'LEASED' THEN 2 ELSE 3 END,
                       o.updated_at DESC
              LIMIT " . max(1, min(200, $limit))
        );
    }

    /** @return list<array<string, mixed>> */
    public function platformAnalysis(int $limit = 100): array
    {
        return $this->db->select(
            "SELECT b.batch_id, b.company_id, c.name AS company_name, b.purpose, b.status,
                    b.total_customers, b.created_at, b.updated_at,
                    SUM(CASE WHEN r.status IN ('COMPLETED', 'INSUFFICIENT_DATA', 'FAILED_FINAL', 'CANCELLED') THEN 1 ELSE 0 END) AS terminal_count,
                    SUM(CASE WHEN r.status IN ('ACTION_REQUIRED', 'FAILED_FINAL') THEN 1 ELSE 0 END) AS action_count
               FROM ai_analysis_batches b
               JOIN ai_companies c ON c.company_id = b.company_id
               LEFT JOIN ai_analysis_runs r ON r.batch_id = b.batch_id
              GROUP BY b.batch_id, b.company_id, c.name, b.purpose, b.status,
                       b.total_customers, b.created_at, b.updated_at
              ORDER BY action_count DESC, b.updated_at DESC
              LIMIT " . max(1, min(200, $limit))
        );
    }

    /** @return list<array<string, mixed>> */
    public function workers(): array
    {
        return $this->db->select('SELECT * FROM ai_worker_heartbeats ORDER BY received_at DESC');
    }
}
