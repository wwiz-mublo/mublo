<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Service;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Repository\CustomerDirectoryRepository;
use Mublo\Packages\AiAssistant\Repository\MessagingPolicyRepository;
use Mublo\Packages\AiAssistant\Repository\MessagingDispatchRepository;
use Mublo\Packages\AiAssistant\Support\Time;
use Mublo\Packages\AiAssistant\Support\Uuid;

final class MessagingPolicyService
{
    private const CHANNELS = ['SMS', 'KAKAO'];
    private const PURPOSES = ['MARKETING', 'SERVICE_NOTICE'];
    private const STATUSES = ['CONSENTED', 'REVOKED', 'NOT_REQUIRED'];
    private const LEGAL_BASES = ['EXPLICIT_CONSENT', 'EXISTING_TRANSACTION', 'CONTRACT_FULFILLMENT', 'MANUAL_REVIEW'];

    public function __construct(
        private CustomerDirectoryRepository $directory,
        private MessagingPolicyRepository $policies,
        private MessagingDispatchRepository $dispatch
    ) {
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $input @return array<string, mixed> */
    public function putPermission(
        array $principal,
        string $phoneId,
        string $channel,
        string $purpose,
        array $input
    ): array {
        $required = ['schema_version', 'customer_phone_id', 'channel', 'purpose', 'status',
            'legal_basis', 'captured_at', 'source', 'version'];
        $allowed = [...$required, 'permission_id', 'expires_at'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $input)) {
                throw new ApiException('CONTACT_PERMISSION_INVALID', '동의 상태 필수 항목이 누락되었습니다.', 422);
            }
        }
        if (array_diff(array_keys($input), $allowed) !== []
            || $input['schema_version'] !== 'contact-permission-v1'
            || !Uuid::isValid($phoneId)
            || $input['customer_phone_id'] !== $phoneId
            || $input['channel'] !== $channel
            || $input['purpose'] !== $purpose
            || !in_array($channel, self::CHANNELS, true)
            || !in_array($purpose, self::PURPOSES, true)
            || !in_array($input['status'], self::STATUSES, true)
            || !in_array($input['legal_basis'], self::LEGAL_BASES, true)
            || !is_int($input['version'])
            || $input['version'] < 1
            || !is_string($input['source'])
            || trim($input['source']) === ''
            || strlen($input['source']) > 128
            || strtotime((string) $input['captured_at']) === false
            || (isset($input['permission_id']) && !Uuid::isValid((string) $input['permission_id']))
            || (array_key_exists('expires_at', $input) && $input['expires_at'] !== null
                && strtotime((string) $input['expires_at']) === false)
        ) {
            throw new ApiException('CONTACT_PERMISSION_INVALID', '동의 상태 형식 또는 요청 범위가 올바르지 않습니다.', 422);
        }
        if ($purpose === 'MARKETING' && $input['status'] === 'NOT_REQUIRED') {
            throw new ApiException('CONTACT_PERMISSION_INVALID', '마케팅 발송에는 동의 불필요 상태를 사용할 수 없습니다.', 422);
        }
        $companyId = (string) $principal['company_id'];
        $phone = $this->directory->findPhone($companyId, $phoneId);
        if ($phone === null || $phone['deleted_at'] !== null || $phone['customer_deleted_at'] !== null) {
            throw new ApiException('CUSTOMER_PHONE_NOT_REGISTERED', '등록된 활성 고객 전화번호만 동의 상태를 설정할 수 있습니다.', 422);
        }
        $input['expires_at'] = $input['expires_at'] ?? null;
        return $this->policies->save($companyId, $input);
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $input @return array<string, mixed> */
    public function eligibility(array $principal, array $input): array
    {
        $fields = ['schema_version', 'customer_id', 'customer_phone_id', 'channel', 'message_class'];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $input)) {
                throw new ApiException('MESSAGING_ELIGIBILITY_INVALID', '발송 검증 필수 항목이 누락되었습니다.', 422);
            }
        }
        if (array_diff(array_keys($input), $fields) !== []
            || $input['schema_version'] !== 'messaging-eligibility-v1'
            || !Uuid::isValid((string) $input['customer_id'])
            || !Uuid::isValid((string) $input['customer_phone_id'])
            || !in_array($input['channel'], self::CHANNELS, true)
            || !in_array($input['message_class'], ['TRANSACTIONAL', 'MARKETING'], true)
        ) {
            throw new ApiException('MESSAGING_ELIGIBILITY_INVALID', '발송 검증 요청 형식이 올바르지 않습니다.', 422);
        }

        $companyId = (string) $principal['company_id'];
        $phone = $this->directory->requireManagedPhone(
            $companyId,
            (string) $input['customer_id'],
            (string) $input['customer_phone_id']
        );
        return $this->policyDecision($companyId, $input, $phone);
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $input @return array<string, mixed> */
    public function appendSuppressionEvent(array $principal, array $input): array
    {
        $this->requireRole($principal, ['OWNER', 'MANAGER', 'STAFF']);
        $fields = ['schema_version', 'event_id', 'customer_phone_id', 'channel', 'action',
            'reason', 'occurred_at', 'source', 'version'];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $input)) {
                throw new ApiException('SUPPRESSION_EVENT_INVALID', '수신거부 이벤트 필수 항목이 누락되었습니다.', 422);
            }
        }
        if (array_diff(array_keys($input), $fields) !== []
            || $input['schema_version'] !== 'suppression-event-v1'
            || !Uuid::isValid((string) $input['event_id'])
            || !Uuid::isValid((string) $input['customer_phone_id'])
            || !in_array($input['channel'], self::CHANNELS, true)
            || !in_array($input['action'], ['SUPPRESS', 'LIFT'], true)
            || preg_match('/^[A-Z0-9_]{3,64}$/', (string) $input['reason']) !== 1
            || !is_string($input['source'])
            || trim($input['source']) === ''
            || strlen($input['source']) > 128
            || strtotime((string) $input['occurred_at']) === false
            || !is_int($input['version'])
            || $input['version'] < 1
        ) {
            throw new ApiException('SUPPRESSION_EVENT_INVALID', '수신거부 이벤트 형식이 올바르지 않습니다.', 422);
        }

        $companyId = (string) $principal['company_id'];
        $phone = $this->directory->findPhone($companyId, (string) $input['customer_phone_id']);
        if ($phone === null) {
            throw new ApiException('CUSTOMER_PHONE_NOT_REGISTERED', '등록 이력이 있는 고객 전화번호만 처리할 수 있습니다.', 422);
        }
        $current = $this->policies->findSuppression(
            $companyId,
            (string) $phone['phone_lookup_token'],
            (string) $input['channel']
        );
        if ($current !== null && (int) $input['version'] <= (int) $current['suppression_version']) {
            throw new ApiException(
                'SUPPRESSION_VERSION_CONFLICT',
                '수신거부 이벤트 version은 서버 version보다 커야 합니다.',
                409,
                ['server_version' => (int) $current['suppression_version']]
            );
        }
        return $this->policies->appendSuppressionEvent($companyId, $phone, $input);
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $input @return array<string, mixed> */
    public function createCampaignSnapshot(
        array $principal,
        string $campaignId,
        array $input
    ): array {
        $this->requireRole($principal, ['OWNER', 'MANAGER']);
        $fields = ['schema_version', 'campaign_id', 'channel', 'message_class', 'content_version', 'recipients'];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $input)) {
                throw new ApiException('CAMPAIGN_SNAPSHOT_INVALID', '캠페인 snapshot 필수 항목이 누락되었습니다.', 422);
            }
        }
        if (array_diff(array_keys($input), $fields) !== []
            || $input['schema_version'] !== 'campaign-recipient-snapshot-v1'
            || !Uuid::isValid($campaignId)
            || $input['campaign_id'] !== $campaignId
            || !in_array($input['channel'], self::CHANNELS, true)
            || !in_array($input['message_class'], ['TRANSACTIONAL', 'MARKETING'], true)
            || !is_int($input['content_version'])
            || $input['content_version'] < 1
            || !is_array($input['recipients'])
            || $input['recipients'] === []
            || count($input['recipients']) > 500
        ) {
            throw new ApiException('CAMPAIGN_SNAPSHOT_INVALID', '캠페인 snapshot 형식이 올바르지 않습니다.', 422);
        }

        $companyId = (string) $principal['company_id'];
        if ($this->policies->hasCampaignSnapshot($companyId, $campaignId)) {
            throw new ApiException('CAMPAIGN_SNAPSHOT_IMMUTABLE', '이미 생성된 캠페인 snapshot은 변경할 수 없습니다.', 409);
        }

        $phones = [];
        $seenPhoneIds = [];
        foreach (array_values($input['recipients']) as $index => $recipient) {
            if (!is_array($recipient)
                || array_diff(array_keys($recipient), ['customer_id', 'customer_phone_id']) !== []
                || array_diff(['customer_id', 'customer_phone_id'], array_keys($recipient)) !== []
                || !Uuid::isValid((string) ($recipient['customer_id'] ?? ''))
                || !Uuid::isValid((string) ($recipient['customer_phone_id'] ?? ''))
            ) {
                throw new ApiException('CAMPAIGN_RECIPIENT_INVALID', '캠페인 수신자 형식이 올바르지 않습니다.', 422, ['index' => $index]);
            }
            $phoneId = (string) $recipient['customer_phone_id'];
            if (isset($seenPhoneIds[$phoneId])) {
                throw new ApiException('CAMPAIGN_RECIPIENT_DUPLICATE', '캠페인에 같은 전화번호를 중복 지정할 수 없습니다.', 422, ['index' => $index]);
            }
            $seenPhoneIds[$phoneId] = true;
            $phone = $this->directory->findPhone($companyId, $phoneId);
            if ($phone === null || $phone['deleted_at'] !== null || $phone['customer_deleted_at'] !== null) {
                throw new ApiException('CUSTOMER_PHONE_NOT_REGISTERED', '캠페인에는 등록된 활성 고객 전화번호만 사용할 수 있습니다.', 422, ['index' => $index]);
            }
            if (!hash_equals((string) $recipient['customer_id'], (string) $phone['customer_id'])) {
                throw new ApiException('CUSTOMER_PHONE_SCOPE_MISMATCH', '캠페인 전화번호가 요청 고객에게 속하지 않습니다.', 422, ['index' => $index]);
            }
            $phones[$phoneId] = $phone;
        }

        $decisions = [];
        foreach (array_values($input['recipients']) as $recipient) {
            $phoneId = (string) $recipient['customer_phone_id'];
            $decisionInput = [
                'customer_id' => $recipient['customer_id'],
                'customer_phone_id' => $phoneId,
                'channel' => $input['channel'],
                'message_class' => $input['message_class'],
            ];
            $decisions[] = $this->policyDecision($companyId, $decisionInput, $phones[$phoneId]);
        }
        $snapshotBatchId = Uuid::v4();
        $this->policies->createCampaignSnapshot($companyId, $snapshotBatchId, $input, $decisions);
        $eligibleCount = count(array_filter($decisions, static fn(array $decision): bool => (bool) $decision['eligible']));
        return [
            'snapshot_id' => $snapshotBatchId,
            'campaign_id' => $campaignId,
            'channel' => $input['channel'],
            'message_class' => $input['message_class'],
            'content_version' => $input['content_version'],
            'recipient_count' => count($decisions),
            'eligible_count' => $eligibleCount,
            'excluded_count' => count($decisions) - $eligibleCount,
            'recipients' => $decisions,
            'created_at' => Time::api(Time::database()),
        ];
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $input @return array<string, mixed> */
    public function putCampaignPolicy(array $principal, string $campaignId, array $input): array
    {
        $this->requireRole($principal, ['OWNER', 'MANAGER']);
        $fields = ['schema_version', 'campaign_id', 'channel', 'message_class', 'content_version',
            'approved_content_version', 'timezone', 'quiet_hours_start', 'quiet_hours_end',
            'per_recipient_daily_limit', 'version'];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $input)) {
                throw new ApiException('CAMPAIGN_POLICY_INVALID', '캠페인 정책 필수 항목이 누락되었습니다.', 422);
            }
        }
        $approvedVersion = $input['approved_content_version'];
        $quietStart = $input['quiet_hours_start'];
        $quietEnd = $input['quiet_hours_end'];
        if (array_diff(array_keys($input), $fields) !== []
            || $input['schema_version'] !== 'campaign-dispatch-policy-v1'
            || !Uuid::isValid($campaignId)
            || $input['campaign_id'] !== $campaignId
            || !in_array($input['channel'], self::CHANNELS, true)
            || !in_array($input['message_class'], ['TRANSACTIONAL', 'MARKETING'], true)
            || !is_int($input['content_version'])
            || $input['content_version'] < 1
            || ($approvedVersion !== null && (!is_int($approvedVersion)
                || $approvedVersion < 1 || $approvedVersion > $input['content_version']))
            || !is_string($input['timezone'])
            || strlen($input['timezone']) > 64
            || !$this->validTimezone($input['timezone'])
            || !$this->validQuietHours($quietStart, $quietEnd)
            || !is_int($input['per_recipient_daily_limit'])
            || $input['per_recipient_daily_limit'] < 1
            || $input['per_recipient_daily_limit'] > 100
            || !is_int($input['version'])
            || $input['version'] < 1
        ) {
            throw new ApiException('CAMPAIGN_POLICY_INVALID', '캠페인 정책 형식이 올바르지 않습니다.', 422);
        }
        return $this->dispatch->savePolicy((string) $principal['company_id'], $input);
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $input @return array<string, mixed> */
    public function createDispatchPreflight(array $principal, string $campaignId, array $input): array
    {
        $this->requireRole($principal, ['OWNER', 'MANAGER']);
        $fields = ['schema_version', 'preflight_id', 'campaign_id', 'snapshot_id', 'content_version'];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $input)) {
                throw new ApiException('DISPATCH_PREFLIGHT_INVALID', 'dispatch preflight 필수 항목이 누락되었습니다.', 422);
            }
        }
        if (array_diff(array_keys($input), $fields) !== []
            || $input['schema_version'] !== 'campaign-dispatch-preflight-v1'
            || !Uuid::isValid((string) $input['preflight_id'])
            || !Uuid::isValid($campaignId)
            || $input['campaign_id'] !== $campaignId
            || !Uuid::isValid((string) $input['snapshot_id'])
            || !is_int($input['content_version'])
            || $input['content_version'] < 1
        ) {
            throw new ApiException('DISPATCH_PREFLIGHT_INVALID', 'dispatch preflight 형식이 올바르지 않습니다.', 422);
        }

        $companyId = (string) $principal['company_id'];
        $policy = $this->dispatch->findPolicy($companyId, $campaignId);
        if ($policy === null) {
            throw new ApiException('CAMPAIGN_POLICY_NOT_FOUND', '캠페인 발송 정책을 찾을 수 없습니다.', 422);
        }
        $snapshotRows = $this->dispatch->snapshotRows(
            $companyId,
            $campaignId,
            (string) $input['snapshot_id']
        );
        if ($snapshotRows === []) {
            throw new ApiException('CAMPAIGN_SNAPSHOT_NOT_FOUND', '캠페인 수신자 snapshot을 찾을 수 없습니다.', 422);
        }
        if ($this->dispatch->hasReservations($companyId, $campaignId, (int) $input['content_version'])) {
            throw new ApiException(
                'DISPATCH_PREFLIGHT_ALREADY_EXISTS',
                '같은 캠페인과 본문 version의 dispatch preflight가 이미 존재합니다.',
                409
            );
        }
        $firstSnapshot = $snapshotRows[0];
        if ((string) $firstSnapshot['channel'] !== (string) $policy['channel']
            || (string) $firstSnapshot['message_class'] !== (string) $policy['message_class']
        ) {
            throw new ApiException('CAMPAIGN_POLICY_SCOPE_MISMATCH', '캠페인 정책과 snapshot 범위가 일치하지 않습니다.', 422);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        [$dayStartUtc, $dayEndUtc] = $this->utcDayBounds($now, (string) $policy['timezone']);
        $globalReasons = [];
        if ((int) $policy['content_version'] !== (int) $input['content_version']
            || (int) $firstSnapshot['content_version'] !== (int) $input['content_version']
            || $policy['approved_content_version'] === null
            || (int) $policy['approved_content_version'] !== (int) $input['content_version']
        ) {
            $globalReasons[] = 'CONTENT_VERSION_NOT_APPROVED';
        }
        if ($this->isQuietHours($now, $policy)) {
            $globalReasons[] = 'QUIET_HOURS';
        }

        $decisions = [];
        foreach ($snapshotRows as $snapshot) {
            $reasons = $globalReasons;
            $snapshotReasons = json_decode((string) $snapshot['reason_codes_json'], true);
            if ((int) $snapshot['eligible'] !== 1) {
                $reasons[] = 'SNAPSHOT_INELIGIBLE';
                if (is_array($snapshotReasons)) {
                    foreach ($snapshotReasons as $reason) {
                        if (is_string($reason)) {
                            $reasons[] = $reason;
                        }
                    }
                }
            }

            $phoneId = (string) $snapshot['customer_phone_id'];
            $customerId = (string) $snapshot['customer_id'];
            $phone = $this->directory->findPhone($companyId, $phoneId);
            $permissionVersion = null;
            $suppressionVersion = null;
            if ($phone === null || $phone['deleted_at'] !== null || $phone['customer_deleted_at'] !== null) {
                $reasons[] = 'CUSTOMER_PHONE_NOT_REGISTERED';
            } elseif (!hash_equals($customerId, (string) $phone['customer_id'])) {
                $reasons[] = 'CUSTOMER_PHONE_SCOPE_MISMATCH';
            } else {
                $current = $this->policyDecision($companyId, [
                    'customer_id' => $customerId,
                    'customer_phone_id' => $phoneId,
                    'channel' => $policy['channel'],
                    'message_class' => $policy['message_class'],
                ], $phone);
                foreach ($current['reasons'] as $reason) {
                    $reasons[] = $reason;
                }
                $permissionVersion = $current['permission_version'];
                $suppressionVersion = $current['suppression_version'];
            }

            if ($this->dispatch->readyCount(
                $companyId,
                $phoneId,
                (string) $policy['channel'],
                $dayStartUtc,
                $dayEndUtc
            ) >= (int) $policy['per_recipient_daily_limit']) {
                $reasons[] = 'DAILY_FREQUENCY_LIMIT';
            }
            $latestInteraction = $this->dispatch->latestInteractionAt($companyId, $customerId);
            if ($latestInteraction !== null
                && strtotime($latestInteraction . ' UTC') > strtotime((string) $snapshot['created_at'] . ' UTC')
            ) {
                $reasons[] = 'STALE_AFTER_INTERACTION';
            }
            $reasons = array_values(array_unique($reasons));
            $decisions[] = [
                'customer_id' => $customerId,
                'customer_phone_id' => $phoneId,
                'channel' => (string) $policy['channel'],
                'message_class' => (string) $policy['message_class'],
                'status' => $reasons === [] ? 'READY' : 'BLOCKED',
                'reasons' => $reasons,
                'permission_version' => $permissionVersion,
                'suppression_version' => $suppressionVersion,
            ];
        }

        $decisions = $this->dispatch->createReservations(
            $companyId,
            (string) $input['preflight_id'],
            $campaignId,
            (string) $input['snapshot_id'],
            (int) $input['content_version'],
            $decisions,
            (int) $policy['per_recipient_daily_limit'],
            $dayStartUtc,
            $dayEndUtc
        );
        $readyCount = count(array_filter(
            $decisions,
            static fn(array $decision): bool => $decision['status'] === 'READY'
        ));
        return [
            'preflight_id' => $input['preflight_id'],
            'campaign_id' => $campaignId,
            'snapshot_id' => $input['snapshot_id'],
            'content_version' => $input['content_version'],
            'recipient_count' => count($decisions),
            'ready_count' => $readyCount,
            'blocked_count' => count($decisions) - $readyCount,
            'reservations' => $decisions,
            'evaluated_at' => $now->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $phone @return array<string, mixed> */
    private function policyDecision(string $companyId, array $input, array $phone): array
    {
        $reasons = [];
        if ((string) $phone['management_status'] !== 'MANAGED'
            || (string) $phone['customer_management_status'] !== 'MANAGED'
        ) {
            $reasons[] = 'CUSTOMER_PHONE_NOT_ELIGIBLE';
        }
        $suppression = $this->policies->findSuppression(
            $companyId,
            (string) $phone['phone_lookup_token'],
            (string) $input['channel']
        );
        if ($suppression !== null && $suppression['lifted_at'] === null) {
            $reasons[] = 'SUPPRESSED';
        }
        $purpose = $input['message_class'] === 'MARKETING' ? 'MARKETING' : 'SERVICE_NOTICE';
        $permission = $this->policies->findPermission(
            $companyId,
            (string) $input['customer_phone_id'],
            (string) $input['channel'],
            $purpose
        );
        $allowedStatuses = $purpose === 'MARKETING' ? ['CONSENTED'] : ['CONSENTED', 'NOT_REQUIRED'];
        if ($permission === null || !in_array((string) $permission['status'], $allowedStatuses, true)) {
            $reasons[] = $permission !== null && $permission['status'] === 'REVOKED'
                ? 'PERMISSION_REVOKED'
                : 'PERMISSION_REQUIRED';
        } elseif ($permission['expires_at'] !== null
            && strtotime((string) $permission['expires_at'] . ' UTC') < time()
        ) {
            $reasons[] = 'PERMISSION_EXPIRED';
        }

        return [
            'eligible' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
            'customer_id' => $input['customer_id'],
            'customer_phone_id' => $input['customer_phone_id'],
            'channel' => $input['channel'],
            'message_class' => $input['message_class'],
            'permission_version' => $permission === null ? null : (int) $permission['permission_version'],
            'suppression_version' => $suppression === null ? null : (int) $suppression['suppression_version'],
            'checked_at' => Time::api(Time::database()),
        ];
    }

    private function validTimezone(string $timezone): bool
    {
        return in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true);
    }

    private function validQuietHours(mixed $start, mixed $end): bool
    {
        if ($start === null && $end === null) {
            return true;
        }
        if (!is_string($start) || !is_string($end) || $start === $end) {
            return false;
        }
        return preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $start) === 1
            && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $end) === 1;
    }

    /** @param array<string, mixed> $policy */
    private function isQuietHours(\DateTimeImmutable $nowUtc, array $policy): bool
    {
        if ($policy['quiet_hours_start'] === null || $policy['quiet_hours_end'] === null) {
            return false;
        }
        $local = $nowUtc->setTimezone(new \DateTimeZone((string) $policy['timezone']));
        $minute = ((int) $local->format('H') * 60) + (int) $local->format('i');
        $start = $this->minuteOfDay((string) $policy['quiet_hours_start']);
        $end = $this->minuteOfDay((string) $policy['quiet_hours_end']);
        return $start < $end
            ? $minute >= $start && $minute < $end
            : $minute >= $start || $minute < $end;
    }

    /** @return array{string, string} */
    private function utcDayBounds(\DateTimeImmutable $nowUtc, string $timezone): array
    {
        $zone = new \DateTimeZone($timezone);
        $start = $nowUtc->setTimezone($zone)->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
        $end = $start->setTimezone($zone)->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'));
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    private function minuteOfDay(string $value): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $value));
        return ($hour * 60) + $minute;
    }

    /** @param array<string, mixed> $principal @param list<string> $roles */
    private function requireRole(array $principal, array $roles): void
    {
        if (!in_array((string) ($principal['role'] ?? ''), $roles, true)) {
            throw new ApiException('ROLE_FORBIDDEN', '이 작업을 수행할 권한이 없습니다.', 403);
        }
    }
}
