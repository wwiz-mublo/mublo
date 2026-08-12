<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\AiAssistant\Support\Time;

final class SubscriptionRepository
{
    public const DEFAULT_PLAN_CODE = 'BASIC';

    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed> */
    public function currentPlan(string $companyId): array
    {
        $row = $this->findCurrentPlan($companyId);
        if ($row !== null) {
            return $row;
        }

        $now = Time::database();
        $this->db->insert(
            'INSERT INTO ai_company_subscriptions
                (company_id, plan_code, status, started_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$companyId, self::DEFAULT_PLAN_CODE, 'ACTIVE', $now, $now, $now]
        );
        return $this->requireCurrentPlan($companyId);
    }

    /**
     * Serializes customer-limit checks for one company while the caller's transaction is active.
     * @return array<string, mixed>
     */
    public function lockCurrentPlan(string $companyId): array
    {
        $this->currentPlan($companyId);
        $this->db->execute(
            'UPDATE ai_company_subscriptions SET updated_at = updated_at WHERE company_id = ?',
            [$companyId]
        );
        return $this->requireCurrentPlan($companyId);
    }

    public function managedCustomerCount(string $companyId): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS managed_count
               FROM ai_customer_directory
              WHERE company_id = ? AND management_status = 'MANAGED' AND deleted_at IS NULL",
            [$companyId]
        );
        return (int) ($row['managed_count'] ?? 0);
    }

    public function isActiveManagedCustomer(string $companyId, string $customerId): bool
    {
        return $this->db->selectOne(
            "SELECT customer_id FROM ai_customer_directory
              WHERE company_id = ? AND customer_id = ?
                AND management_status = 'MANAGED' AND deleted_at IS NULL LIMIT 1",
            [$companyId, $customerId]
        ) !== null;
    }

    /** @return array<string, mixed>|null */
    private function findCurrentPlan(string $companyId): ?array
    {
        return $this->db->selectOne(
            "SELECT p.plan_code, p.name, p.monthly_price_krw, p.customer_limit,
                    s.status AS subscription_status, s.started_at
               FROM ai_company_subscriptions s
               JOIN ai_subscription_plans p ON p.plan_code = s.plan_code
              WHERE s.company_id = ? AND s.status = 'ACTIVE' AND p.status = 'ACTIVE'
              LIMIT 1",
            [$companyId]
        );
    }

    /** @return array<string, mixed> */
    private function requireCurrentPlan(string $companyId): array
    {
        $row = $this->findCurrentPlan($companyId);
        if ($row === null) {
            throw new \RuntimeException('Active subscription plan could not be loaded');
        }
        return $row;
    }
}
