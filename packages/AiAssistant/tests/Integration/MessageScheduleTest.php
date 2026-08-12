<?php
declare(strict_types=1);

namespace Tests\AiAssistant\Integration;

use Mublo\Packages\AiAssistant\Contract\SchedulePushGatewayInterface;
use Mublo\Packages\AiAssistant\Repository\MessageScheduleRepository;
use Mublo\Packages\AiAssistant\Service\ScheduleDispatchService;
use Mublo\Packages\AiAssistant\Support\Uuid;
use Tests\AiAssistant\DatabaseTestCase;

final class MessageScheduleTest extends DatabaseTestCase
{
    public function testDueSchedulePushesIdentifierOnlyAndStopsAfterDeviceAck(): void
    {
        $principal = $this->principal('schedule-company', 701, 'schedule-company-secret');
        $device = $this->enroll($principal, 'installation-schedule-company-1');
        [$customerId, $phoneId] = $this->managedCustomerAndPhone($principal, (string) $device['device_id']);
        $created = $this->schedules->create($principal, [
            'device_id' => $device['device_id'],
            'customer_id' => $customerId,
            'customer_phone_id' => $phoneId,
            'channel' => 'DEVICE_KAKAO',
            'content' => '예약된 카카오 메시지',
            'scheduled_at' => gmdate('c', time() + 1),
        ]);
        $this->db->execute(
            'UPDATE ai_schedule_dispatch_outbox SET available_at = ? WHERE outbox_id = ?',
            [gmdate('Y-m-d H:i:s', time() - 1), $created['dispatch_no']]
        );
        $push = new class implements SchedulePushGatewayInterface {
            /** @var array<string, string|int|bool> */
            public array $data = [];
            public function send(string $fcmToken, array $data): array
            {
                $this->data = $data;
                return [
                    'success' => true, 'message_id' => 'projects/test/messages/1',
                    'error_code' => '', 'token_invalid' => false, 'error' => '',
                ];
            }
        };
        $dispatcher = new ScheduleDispatchService(new MessageScheduleRepository($this->db), $push);
        $summary = $dispatcher->runDue();
        self::assertSame(1, $summary['pushed']);
        self::assertSame('dispatch_schedule', $push->data['action']);
        self::assertArrayNotHasKey('content', $push->data);

        $payload = $this->schedules->dispatchPayload(
            $principal,
            (string) $created['schedule_id'],
            (string) $device['device_id'],
            (string) $created['dispatch_id'],
            1
        );
        self::assertSame('예약된 카카오 메시지', $payload['content']);
        self::assertSame('+821012345678', $payload['phone']);

        $this->schedules->acknowledge($principal, (string) $created['schedule_id'], [
            'device_id' => $device['device_id'],
            'dispatch_id' => $created['dispatch_id'],
            'revision' => 1,
            'status' => 'WAITING_DEVICE_READY',
        ]);
        $outbox = $this->db->selectOne(
            'SELECT status FROM ai_schedule_dispatch_outbox WHERE outbox_id = ?',
            [$created['dispatch_no']]
        );
        self::assertSame('ACKED', $outbox['status']);
        self::assertSame(0, $dispatcher->runDue()['claimed']);
    }

    public function testCancelPreventsDispatch(): void
    {
        $principal = $this->principal('cancel-company', 702, 'cancel-company-secret');
        $device = $this->enroll($principal, 'installation-cancel-company-1');
        [$customerId, $phoneId] = $this->managedCustomerAndPhone($principal, (string) $device['device_id']);
        $created = $this->schedules->create($principal, [
            'device_id' => $device['device_id'],
            'customer_id' => $customerId,
            'customer_phone_id' => $phoneId,
            'channel' => 'DEVICE_SIM',
            'content' => '취소할 메시지',
            'scheduled_at' => gmdate('c', time() + 60),
        ]);
        $this->schedules->cancel($principal, (string) $created['schedule_id']);
        $row = $this->db->selectOne(
            'SELECT s.status, o.status AS outbox_status FROM ai_message_schedules s
             JOIN ai_schedule_dispatch_outbox o ON o.schedule_id = s.schedule_id
             WHERE s.schedule_id = ?',
            [$created['schedule_id']]
        );
        self::assertSame('CANCELED', $row['status']);
        self::assertSame('CANCELED', $row['outbox_status']);
    }

    public function testDispatchStopsWakingDeviceAfterMaximumUnacknowledgedPushes(): void
    {
        $principal = $this->principal('retry-company', 703, 'retry-company-secret');
        $device = $this->enroll($principal, 'installation-retry-company-1');
        [$customerId, $phoneId] = $this->managedCustomerAndPhone($principal, (string) $device['device_id']);
        $created = $this->schedules->create($principal, [
            'device_id' => $device['device_id'],
            'customer_id' => $customerId,
            'customer_phone_id' => $phoneId,
            'channel' => 'DEVICE_SIM',
            'content' => '재시도 제한 확인',
            'scheduled_at' => gmdate('c', time() + 1),
        ]);
        $this->db->execute(
            "UPDATE ai_schedule_dispatch_outbox
                SET attempt_count = 5, available_at = ?, status = 'RETRY'
              WHERE outbox_id = ?",
            [gmdate('Y-m-d H:i:s', time() - 1), $created['dispatch_no']]
        );
        $push = new class implements SchedulePushGatewayInterface {
            public int $calls = 0;
            public function send(string $fcmToken, array $data): array
            {
                $this->calls++;
                return [
                    'success' => true, 'message_id' => 'should-not-send',
                    'error_code' => '', 'token_invalid' => false, 'error' => '',
                ];
            }
        };

        $summary = (new ScheduleDispatchService(
            new MessageScheduleRepository($this->db),
            $push
        ))->runDue();

        self::assertSame(0, $push->calls);
        self::assertSame(1, $summary['dead']);
        $row = $this->db->selectOne(
            'SELECT s.status, o.status AS outbox_status FROM ai_message_schedules s
             JOIN ai_schedule_dispatch_outbox o ON o.schedule_id = s.schedule_id
             WHERE s.schedule_id = ?',
            [$created['schedule_id']]
        );
        self::assertSame('FAILED', $row['status']);
        self::assertSame('DEAD', $row['outbox_status']);
    }

    /** @param array<string, mixed> $principal @return array{string,string} */
    private function managedCustomerAndPhone(array $principal, string $deviceId): array
    {
        $customerId = Uuid::v4();
        $phoneId = Uuid::v4();
        $now = gmdate('c');
        $records = [
            [
                'schema_version' => 'sync-record-v1', 'object_type' => 'customer',
                'company_id' => $principal['company_id'],
                'object_id' => $customerId, 'operation' => 'UPSERT', 'version' => 1,
                'updated_at' => $now, 'deleted_at' => null,
                'payload' => ['company_id' => $principal['company_id'], 'display_name' => '예약 고객', 'management_status' => 'MANAGED'],
            ],
            [
                'schema_version' => 'sync-record-v1', 'object_type' => 'customer_phone',
                'company_id' => $principal['company_id'],
                'object_id' => $phoneId, 'operation' => 'UPSERT', 'version' => 1,
                'updated_at' => $now, 'deleted_at' => null,
                'payload' => [
                    'company_id' => $principal['company_id'], 'customer_id' => $customerId,
                    'normalized_phone' => '+821012345678', 'management_status' => 'MANAGED', 'is_primary' => true,
                ],
            ],
        ];
        $this->sync->push($principal, $deviceId, $records);
        return [$customerId, $phoneId];
    }
}
