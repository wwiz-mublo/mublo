<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\AiAssistant\Support\Time;

final class DeviceRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByInstallation(string $companyId, string $installationHash): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM ai_devices WHERE company_id = ? AND installation_hash = ? LIMIT 1',
            [$companyId, $installationHash]
        );
    }

    /** @return array<string, mixed>|null */
    public function findActive(string $companyId, string $deviceId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM ai_devices
              WHERE company_id = ? AND device_id = ? AND status = 'ACTIVE'
              LIMIT 1",
            [$companyId, $deviceId]
        );
    }

    /** @param array<string, mixed> $device */
    public function insert(array $device): void
    {
        $this->db->insert(
            'INSERT INTO ai_devices
                (device_id, company_id, enrolled_by_user_id, installation_hash, name, platform,
                 public_key, fcm_token, capabilities_json, health_json, app_version, os_version,
                 status, last_seen_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)',
            [
                $device['device_id'],
                $device['company_id'],
                $device['enrolled_by_user_id'],
                $device['installation_hash'],
                $device['name'],
                $device['platform'],
                $device['public_key'],
                $device['fcm_token'],
                $device['capabilities_json'],
                $device['app_version'],
                $device['os_version'],
                'ACTIVE',
                $device['last_seen_at'],
                $device['created_at'],
                $device['updated_at'],
            ]
        );
    }

    /** @param array<string, mixed> $health */
    public function updateHeartbeat(
        string $companyId,
        string $deviceId,
        string $appVersion,
        string $osVersion,
        ?string $fcmToken,
        array $health
    ): bool {
        $now = Time::database();
        return $this->db->execute(
            'UPDATE ai_devices
                SET app_version = ?, os_version = ?, fcm_token = ?, health_json = ?,
                    last_seen_at = ?, updated_at = ?
              WHERE company_id = ? AND device_id = ? AND status = ?',
            [
                $appVersion,
                $osVersion,
                $fcmToken,
                json_encode($health, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $now,
                $now,
                $companyId,
                $deviceId,
                'ACTIVE',
            ]
        ) === 1;
    }
}
