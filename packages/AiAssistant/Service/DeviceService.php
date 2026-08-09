<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Service;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Repository\DeviceRepository;
use Mublo\Packages\AiAssistant\Support\Time;
use Mublo\Packages\AiAssistant\Support\Uuid;

final class DeviceService
{
    private const CAPABILITIES = [
        'CALL_COLLECT',
        'SMS_COLLECT',
        'KAKAO_COLLECT',
        'SMS_SEND',
        'KAKAO_SEND',
    ];

    public function __construct(private DeviceRepository $repository)
    {
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $input @return array<string, mixed> */
    public function enroll(array $principal, array $input): array
    {
        $installationId = trim((string) ($input['installation_id'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $publicKey = trim((string) ($input['public_key'] ?? ''));
        $platform = (string) ($input['platform'] ?? '');
        $capabilities = $input['capabilities'] ?? null;

        if (strlen($installationId) < 16 || strlen($installationId) > 128) {
            throw new ApiException('DEVICE_INSTALLATION_ID_INVALID', '기기 설치 ID를 확인해 주세요.', 422);
        }
        if ($name === '' || mb_strlen($name) > 190) {
            throw new ApiException('DEVICE_NAME_INVALID', '기기 이름을 확인해 주세요.', 422);
        }
        if ($platform !== 'ANDROID') {
            throw new ApiException('DEVICE_PLATFORM_UNSUPPORTED', '지원하지 않는 기기 플랫폼입니다.', 422);
        }
        if (strlen($publicKey) < 64 || strlen($publicKey) > 8192) {
            throw new ApiException('DEVICE_PUBLIC_KEY_INVALID', '기기 공개키를 확인해 주세요.', 422);
        }
        if (!is_array($capabilities)) {
            throw new ApiException('DEVICE_CAPABILITIES_INVALID', '기기 기능 목록을 확인해 주세요.', 422);
        }
        $capabilities = array_values(array_unique(array_map('strval', $capabilities)));
        if (array_diff($capabilities, self::CAPABILITIES) !== []) {
            throw new ApiException('DEVICE_CAPABILITIES_INVALID', '지원하지 않는 기기 기능이 포함되어 있습니다.', 422);
        }

        $companyId = (string) $principal['company_id'];
        $installationHash = hash('sha256', $installationId);
        $existing = $this->repository->findByInstallation($companyId, $installationHash);
        if ($existing !== null) {
            if ((string) $existing['status'] !== 'ACTIVE') {
                throw new ApiException('DEVICE_REVOKED', '폐기되거나 분실 처리된 기기입니다.', 403);
            }
            if (!hash_equals(hash('sha256', (string) $existing['public_key']), hash('sha256', $publicKey))) {
                throw new ApiException('DEVICE_KEY_CONFLICT', '같은 설치 ID에 다른 공개키가 등록되어 있습니다.', 409);
            }
            return $this->serialize($existing);
        }

        $now = Time::database();
        $device = [
            'device_id' => Uuid::v4(),
            'company_id' => $companyId,
            'enrolled_by_user_id' => (string) $principal['user_id'],
            'installation_hash' => $installationHash,
            'name' => $name,
            'platform' => $platform,
            'public_key' => $publicKey,
            'fcm_token' => $this->nullableString($input['fcm_token'] ?? null, 4096),
            'capabilities_json' => json_encode($capabilities, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'app_version' => $this->boundedString($input['app_version'] ?? '', 64),
            'os_version' => $this->boundedString($input['os_version'] ?? '', 64),
            'status' => 'ACTIVE',
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->repository->insert($device);

        return $this->serialize($device);
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $input @return array<string, mixed> */
    public function heartbeat(array $principal, string $deviceId, array $input): array
    {
        if (!Uuid::isValid($deviceId)) {
            throw new ApiException('DEVICE_ID_INVALID', '기기 ID 형식이 올바르지 않습니다.', 422);
        }
        $companyId = (string) $principal['company_id'];
        $device = $this->repository->findActive($companyId, $deviceId);
        if ($device === null) {
            throw new ApiException('DEVICE_NOT_FOUND', '기기를 찾을 수 없습니다.', 404);
        }
        $permissions = $input['permissions'] ?? null;
        if (!is_array($permissions)) {
            throw new ApiException('DEVICE_HEALTH_INVALID', '기기 권한 상태를 확인해 주세요.', 422);
        }
        $health = [
            'permissions' => array_map(static fn(mixed $value): bool => (bool) $value, $permissions),
            'battery_optimization_exempt' => (bool) ($input['battery_optimization_exempt'] ?? false),
            'kakao_version' => $this->nullableString($input['kakao_version'] ?? null, 64),
        ];
        $this->repository->updateHeartbeat(
            $companyId,
            $deviceId,
            $this->boundedString($input['app_version'] ?? '', 64),
            $this->boundedString($input['os_version'] ?? '', 64),
            $this->nullableString($input['fcm_token'] ?? null, 4096),
            $health
        );

        $updated = $this->repository->findActive($companyId, $deviceId);
        if ($updated === null) {
            throw new ApiException('DEVICE_NOT_FOUND', '기기를 찾을 수 없습니다.', 404);
        }
        return $this->serialize($updated);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function serialize(array $row): array
    {
        $capabilities = json_decode((string) $row['capabilities_json'], true);
        return [
            'device_id' => (string) $row['device_id'],
            'company_id' => (string) $row['company_id'],
            'status' => (string) $row['status'],
            'capabilities' => is_array($capabilities) ? array_values($capabilities) : [],
            'last_seen_at' => Time::api(isset($row['last_seen_at']) ? (string) $row['last_seen_at'] : null),
            'created_at' => Time::api((string) $row['created_at']),
            'updated_at' => Time::api((string) $row['updated_at']),
        ];
    }

    private function boundedString(mixed $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->boundedString($value, $max);
    }
}
