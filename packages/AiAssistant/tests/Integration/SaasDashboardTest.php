<?php
declare(strict_types=1);

namespace Tests\AiAssistant\Integration;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Support\Uuid;
use Tests\AiAssistant\DatabaseTestCase;

final class SaasDashboardTest extends DatabaseTestCase
{
    public function testOwnerSeesOnlyMappedCompanyDashboardData(): void
    {
        $principal = $this->principal('saas-owner', 701, 'saas-secret');
        $device = $this->enroll($principal, 'installation-saas-owner-01');
        $customerId = Uuid::v4();
        $this->sync->push($principal, (string) $device['device_id'], [[
            'schema_version' => 'sync-record-v1', 'company_id' => $principal['company_id'],
            'object_type' => 'customer', 'object_id' => $customerId, 'operation' => 'UPSERT',
            'version' => 1, 'updated_at' => '2026-08-09T00:00:00Z', 'deleted_at' => null,
            'payload' => ['display_name' => '운영 고객', 'management_status' => 'MANAGED'],
        ]]);

        $dashboard = $this->saas->dashboard(701, 'owner');
        self::assertSame('OWNER', $dashboard['principal']['role']);
        self::assertSame(1, $dashboard['summary']['customers']);
        self::assertSame('운영 고객', $dashboard['customers'][0]['display_name']);
        self::assertArrayNotHasKey('display_name_ciphertext', $dashboard['customers'][0]);
    }

    public function testStaffCannotOpenCompanyWideOperationsDashboard(): void
    {
        $principal = $this->principal('saas-staff', 702, 'saas-secret');
        $this->db->execute('UPDATE ai_company_users SET role = ? WHERE company_id = ?', ['STAFF', $principal['company_id']]);

        try {
            $this->saas->dashboard(702, 'owner');
            self::fail('STAFF must not see the company-wide operations dashboard');
        } catch (ApiException $exception) {
            self::assertSame('ROLE_FORBIDDEN', $exception->errorCode);
            self::assertSame(403, $exception->statusCode);
        }
    }

    public function testStaffCanOpenMemberWorkspaceWithoutWorkerData(): void
    {
        $principal = $this->principal('workspace-staff', 703, 'workspace-secret');
        $this->db->execute('UPDATE ai_company_users SET role = ? WHERE company_id = ?', ['STAFF', $principal['company_id']]);

        $workspace = $this->saas->workspace(703, 'owner', 'customers');

        self::assertSame('STAFF', $workspace['principal']['role']);
        self::assertSame('customers', $workspace['section']);
        self::assertArrayNotHasKey('workers', $workspace);
        self::assertSame([], $workspace['batches']);
        self::assertSame([], $workspace['devices']);
        self::assertSame([], $workspace['schedules']);
    }

    public function testWorkspaceFallsBackToDashboardForUnknownSection(): void
    {
        $this->principal('workspace-owner', 704, 'workspace-secret');

        $workspace = $this->saas->workspace(704, 'owner', 'unknown');

        self::assertSame('dashboard', $workspace['section']);
        self::assertSame('OWNER', $workspace['principal']['role']);
    }

    public function testPlatformDashboardExposesOnlyAggregateCompanyMetadata(): void
    {
        $principal = $this->principal('platform-company', 705, 'platform-secret');
        $this->enroll($principal, 'installation-platform-company-01');

        $platform = $this->saas->platform('dashboard');

        self::assertSame(1, $platform['summary']['companies']);
        self::assertSame(1, $platform['summary']['active_devices']);
        self::assertSame('platform-company', $platform['companies'][0]['slug']);
        self::assertArrayNotHasKey('password_hash', $platform['companies'][0]);
        self::assertArrayNotHasKey('fcm_token', $platform['companies'][0]);
    }
}
