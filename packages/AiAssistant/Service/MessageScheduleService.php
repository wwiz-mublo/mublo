<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Service;

use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Repository\CustomerDirectoryRepository;
use Mublo\Packages\AiAssistant\Repository\DeviceRepository;
use Mublo\Packages\AiAssistant\Repository\MessageScheduleRepository;
use Mublo\Packages\AiAssistant\Support\Time;
use Mublo\Packages\AiAssistant\Support\Uuid;

final class MessageScheduleService
{
    private const CHANNELS = ['DEVICE_SIM', 'DEVICE_KAKAO'];
    private const DEVICE_STATUSES = [
        'RECEIVED', 'QUEUED', 'WAITING_DEVICE_READY', 'SENDING',
        'SENT', 'DELIVERED', 'FAILED', 'CANCELED',
    ];

    public function __construct(
        private MessageScheduleRepository $repository,
        private DeviceRepository $devices,
        private CustomerDirectoryRepository $directory,
        private SensitiveValueCodecInterface $codec
    ) {
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $input @return array<string, mixed> */
    public function create(array $principal, array $input): array
    {
        foreach (['device_id', 'customer_id', 'customer_phone_id', 'channel', 'content', 'scheduled_at'] as $key) {
            if (!array_key_exists($key, $input)) {
                throw new ApiException('SCHEDULE_INVALID', '일정 필수 항목이 누락되었습니다.', 422);
            }
        }
        $companyId = (string) $principal['company_id'];
        $deviceId = trim((string) $input['device_id']);
        $customerId = trim((string) $input['customer_id']);
        $phoneId = trim((string) $input['customer_phone_id']);
        if (!Uuid::isValid($deviceId) || !Uuid::isValid($customerId) || !Uuid::isValid($phoneId)) {
            throw new ApiException('SCHEDULE_INVALID', '일정의 기기·고객 식별자가 올바르지 않습니다.', 422);
        }
        $device = $this->devices->findActive($companyId, $deviceId);
        if ($device === null) {
            throw new ApiException('DEVICE_NOT_ACTIVE', '활성 발신 기기를 찾을 수 없습니다.', 422);
        }
        $channel = strtoupper(trim((string) $input['channel']));
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new ApiException('SCHEDULE_CHANNEL_INVALID', '지원하지 않는 단말 발신 채널입니다.', 422);
        }
        $capabilities = json_decode((string) ($device['capabilities_json'] ?? '[]'), true);
        $requiredCapability = $channel === 'DEVICE_SIM' ? 'SMS_SEND' : 'KAKAO_SEND';
        if (!is_array($capabilities) || !in_array($requiredCapability, $capabilities, true)) {
            throw new ApiException('DEVICE_CAPABILITY_MISSING', '선택한 채널을 발신할 수 없는 기기입니다.', 422);
        }
        $this->directory->requireManagedPhone($companyId, $customerId, $phoneId);

        $content = trim((string) $input['content']);
        $fallback = trim((string) ($input['sms_fallback_content'] ?? ''));
        if ($content === '' || mb_strlen($content) > 20000 || mb_strlen($fallback) > 20000) {
            throw new ApiException('SCHEDULE_CONTENT_INVALID', '메시지 본문 길이가 올바르지 않습니다.', 422);
        }
        $scheduledAt = $this->parseFutureTime((string) $input['scheduled_at']);
        $expiresAt = isset($input['expires_at'])
            ? $this->parseExpiry((string) $input['expires_at'], $scheduledAt)
            : $scheduledAt + 86400;
        $scheduleId = Uuid::v4();
        $dispatchId = Uuid::v4();
        $now = Time::database();
        $outboxId = $this->repository->create([
            'schedule_id' => $scheduleId,
            'company_id' => $companyId,
            'device_id' => $deviceId,
            'customer_id' => $customerId,
            'customer_phone_id' => $phoneId,
            'dispatch_id' => $dispatchId,
            'revision' => 1,
            'channel' => $channel,
            'message_class' => strtoupper((string) ($input['message_class'] ?? 'TRANSACTIONAL')),
            'content_ciphertext' => $this->codec->encrypt($content),
            'fallback_ciphertext' => $fallback === '' ? null : $this->codec->encrypt($fallback),
            'scheduled_at' => Time::database($scheduledAt),
            'expires_at' => Time::database($expiresAt),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return [
            'schedule_id' => $scheduleId,
            'dispatch_id' => $dispatchId,
            'dispatch_no' => $outboxId,
            'revision' => 1,
            'status' => 'APPROVED',
            'scheduled_at' => Time::api(Time::database($scheduledAt)),
            'expires_at' => Time::api(Time::database($expiresAt)),
        ];
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    public function cancel(array $principal, string $scheduleId): array
    {
        if (!Uuid::isValid($scheduleId)) {
            throw new ApiException('SCHEDULE_ID_INVALID', '일정 ID가 올바르지 않습니다.', 422);
        }
        $companyId = (string) $principal['company_id'];
        $row = $this->repository->find($companyId, $scheduleId);
        if ($row === null) throw new ApiException('SCHEDULE_NOT_FOUND', '일정을 찾을 수 없습니다.', 404);
        $this->repository->cancel($companyId, $scheduleId);
        return ['schedule_id' => $scheduleId, 'status' => 'CANCELED'];
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    public function dispatchPayload(
        array $principal,
        string $scheduleId,
        string $deviceId,
        string $dispatchId,
        int $revision
    ): array {
        $row = $this->requireDispatch($principal, $scheduleId, $deviceId, $dispatchId, $revision);
        if (in_array((string) $row['status'], ['CANCELED', 'SENT', 'FAILED'], true)) {
            throw new ApiException('SCHEDULE_NOT_DISPATCHABLE', '이미 종료된 일정입니다.', 409);
        }
        $content = $this->codec->decrypt((string) $row['content_ciphertext']);
        $phone = $this->codec->decrypt((string) $row['phone_ciphertext']);
        $fallback = $row['fallback_ciphertext'] === null
            ? null
            : $this->codec->decrypt((string) $row['fallback_ciphertext']);
        if ($content === null || $phone === null) {
            throw new ApiException('SCHEDULE_DECRYPT_FAILED', '일정 발신 정보를 복호화하지 못했습니다.', 500);
        }
        return [
            'schedule_id' => $scheduleId,
            'dispatch_id' => $dispatchId,
            'dispatch_no' => (int) $row['outbox_id'],
            'revision' => (int) $row['revision'],
            'channel' => (string) $row['channel'],
            'phone' => $phone,
            'content' => $content,
            'sms_fallback_content' => $fallback,
            'scheduled_at' => Time::api((string) $row['scheduled_at']),
            'expires_at' => Time::api((string) $row['expires_at']),
        ];
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $input @return array<string, mixed> */
    public function acknowledge(array $principal, string $scheduleId, array $input): array
    {
        $deviceId = trim((string) ($input['device_id'] ?? ''));
        $dispatchId = trim((string) ($input['dispatch_id'] ?? ''));
        $revision = (int) ($input['revision'] ?? 0);
        $status = strtoupper(trim((string) ($input['status'] ?? '')));
        if (!in_array($status, self::DEVICE_STATUSES, true)) {
            throw new ApiException('SCHEDULE_STATUS_INVALID', '알 수 없는 단말 일정 상태입니다.', 422);
        }
        $this->requireDispatch($principal, $scheduleId, $deviceId, $dispatchId, $revision);
        $error = trim((string) ($input['error_message'] ?? ''));
        $updated = $this->repository->acknowledge(
            (string) $principal['company_id'], $scheduleId, $dispatchId, $revision,
            $status, $error === '' ? null : $error
        );
        if (!$updated) throw new ApiException('SCHEDULE_NOT_FOUND', '일정을 찾을 수 없습니다.', 404);
        return ['schedule_id' => $scheduleId, 'dispatch_id' => $dispatchId, 'status' => $status];
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    private function requireDispatch(
        array $principal,
        string $scheduleId,
        string $deviceId,
        string $dispatchId,
        int $revision
    ): array {
        if (!Uuid::isValid($scheduleId) || !Uuid::isValid($deviceId)
            || !Uuid::isValid($dispatchId) || $revision < 1
        ) {
            throw new ApiException('SCHEDULE_DISPATCH_INVALID', '일정 발신 식별자가 올바르지 않습니다.', 422);
        }
        $row = $this->repository->find((string) $principal['company_id'], $scheduleId);
        if ($row === null
            || !hash_equals($deviceId, (string) $row['device_id'])
            || !hash_equals($dispatchId, (string) $row['dispatch_id'])
            || $revision !== (int) $row['revision']
        ) {
            throw new ApiException('SCHEDULE_NOT_FOUND', '일정 발신 명령을 찾을 수 없습니다.', 404);
        }
        return $row;
    }

    private function parseFutureTime(string $value): int
    {
        $timestamp = strtotime($value);
        if ($timestamp === false || $timestamp < time() - 30) {
            throw new ApiException('SCHEDULE_TIME_INVALID', '발신 시각은 현재 이후여야 합니다.', 422);
        }
        return $timestamp;
    }

    private function parseExpiry(string $value, int $scheduledAt): int
    {
        $timestamp = strtotime($value);
        if ($timestamp === false || $timestamp <= $scheduledAt || $timestamp > $scheduledAt + 604800) {
            throw new ApiException('SCHEDULE_EXPIRY_INVALID', '일정 유효시간이 올바르지 않습니다.', 422);
        }
        return $timestamp;
    }
}
