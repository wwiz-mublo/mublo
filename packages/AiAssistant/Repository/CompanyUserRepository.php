<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\AiAssistant\Support\Time;

final class CompanyUserRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findCompany(?string $slug, ?int $frameworkDomainId): ?array
    {
        if ($slug !== null && $slug !== '') {
            return $this->db->selectOne(
                "SELECT * FROM ai_companies WHERE slug = ? AND status = 'ACTIVE' LIMIT 1",
                [$slug]
            );
        }
        if ($frameworkDomainId === null) {
            return null;
        }

        return $this->db->selectOne(
            "SELECT * FROM ai_companies WHERE framework_domain_id = ? AND status = 'ACTIVE' LIMIT 1",
            [$frameworkDomainId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findActiveUser(string $companyId, string $loginId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM ai_company_users
              WHERE company_id = ? AND login_id = ? AND status = 'ACTIVE'
              LIMIT 1",
            [$companyId, $loginId]
        );
    }

    public function provision(
        string $companyId,
        ?int $frameworkDomainId,
        string $slug,
        string $companyName,
        string $userId,
        string $loginId,
        string $nickname,
        string $passwordHash
    ): void {
        $now = Time::database();
        $this->db->transaction(function () use (
            $companyId,
            $frameworkDomainId,
            $slug,
            $companyName,
            $userId,
            $loginId,
            $nickname,
            $passwordHash,
            $now
        ): void {
            $this->db->insert(
                'INSERT INTO ai_companies
                    (company_id, framework_domain_id, slug, name, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$companyId, $frameworkDomainId, $slug, $companyName, 'ACTIVE', $now, $now]
            );
            $this->db->insert(
                'INSERT INTO ai_company_users
                    (user_id, company_id, login_id, nickname, password_hash, role, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$userId, $companyId, $loginId, $nickname, $passwordHash, 'OWNER', 'ACTIVE', $now, $now]
            );
            $this->db->insert(
                'INSERT INTO ai_company_subscriptions
                    (company_id, plan_code, status, started_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$companyId, 'BASIC', 'ACTIVE', $now, $now, $now]
            );
        });
    }
}
