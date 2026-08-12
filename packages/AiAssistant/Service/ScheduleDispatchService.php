<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Service;

use Mublo\Packages\AiAssistant\Contract\SchedulePushGatewayInterface;
use Mublo\Packages\AiAssistant\Repository\MessageScheduleRepository;

final class ScheduleDispatchService
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private MessageScheduleRepository $repository,
        private SchedulePushGatewayInterface $push
    ) {
    }

    /** @return array{claimed:int,pushed:int,retried:int,dead:int,skipped:int} */
    public function runDue(int $limit = 50): array
    {
        $summary = ['claimed' => 0, 'pushed' => 0, 'retried' => 0, 'dead' => 0, 'skipped' => 0];
        foreach ($this->repository->claimDue($limit, 90) as $row) {
            $summary['claimed']++;
            $attempt = (int) $row['attempt_count'] + 1;
            $expired = strtotime((string) $row['expires_at'] . ' UTC');
            if (in_array((string) $row['schedule_status'], ['CANCELED', 'SENT', 'FAILED'], true)
                || $expired === false || $expired <= time()
            ) {
                $this->repository->markPushFailed(
                    (int) $row['outbox_id'], (string) $row['lease_token'],
                    '일정이 종료되었거나 유효시간이 지났습니다.', time(), true
                );
                $summary['skipped']++;
                continue;
            }
            if ($attempt > self::MAX_ATTEMPTS) {
                $this->repository->markPushFailed(
                    (int) $row['outbox_id'], (string) $row['lease_token'],
                    '단말의 일정 수신 확인이 없어 재시도를 종료했습니다.', time(), true
                );
                $summary['dead']++;
                continue;
            }
            $result = $this->push->send((string) ($row['fcm_token'] ?? ''), [
                'schema_version' => 'schedule-dispatch-command-v1',
                'action' => 'dispatch_schedule',
                'schedule_id' => (string) $row['schedule_id'],
                'dispatch_id' => (string) $row['dispatch_id'],
                'dispatch_no' => (string) $row['outbox_id'],
                'revision' => (string) $row['revision'],
            ]);
            if ($result['success']) {
                $this->repository->markPushAccepted(
                    (int) $row['outbox_id'], (string) $row['lease_token'],
                    $result['message_id'], time() + min(300, 45 * $attempt)
                );
                $summary['pushed']++;
                continue;
            }
            $terminal = $result['token_invalid'] || $attempt >= self::MAX_ATTEMPTS;
            $this->repository->markPushFailed(
                (int) $row['outbox_id'], (string) $row['lease_token'],
                $result['error_code'] . ': ' . $result['error'],
                time() + min(900, 30 * (2 ** min(5, $attempt))), $terminal
            );
            $summary[$terminal ? 'dead' : 'retried']++;
        }
        return $summary;
    }
}
