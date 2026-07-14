<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Infrastructure\Cache\CacheInterface;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\Repository\ActionExecutionRepository;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Service\Member\FieldEncryptionService;

/** 운영자 설정 Action의 실행 이력 및 공유호스팅 친화적인 요청 기반 재시도 큐. */
class ActionExecutionService
{
    private const ENCRYPTED_FIELDS = [
        'orderer_name', 'orderer_phone', 'orderer_email', 'recipient_name',
        'recipient_phone', 'shipping_zip', 'shipping_address1', 'shipping_address2',
    ];

    public function __construct(
        private ActionExecutionRepository $repository,
        private ActionTypeRegistry $registry,
        private OrderRepository $orderRepository,
        private ?FieldEncryptionService $encryptionService = null,
        private ?CacheInterface $cache = null,
        private ?Logger $logger = null,
    ) {}

    public function dispatch(array $config, OrderStatusChangedEvent $event): void
    {
        $type = (string) ($config['type'] ?? '');
        $actionId = (string) ($config['action_id'] ?? hash('sha256', json_encode($config)));
        $domainId = (int) ($event->getOrder()['domain_id'] ?? 0);
        $occurrence = $event->getEventId()
            ?? implode('|', [$event->getPrevStateId(), $event->getNewStateId()]);
        $key = hash('sha256', implode('|', [$domainId, $event->getOrderNo(), $occurrence, $actionId]));

        $existing = $this->repository->findByKey($key);
        if ($existing !== null) {
            return;
        }

        try {
            $executionId = $this->repository->create([
                'execution_key' => $key,
                'delivery_id' => bin2hex(random_bytes(16)),
                'domain_id' => $domainId,
                'order_no' => $event->getOrderNo(),
                'state_id' => $event->getNewStateId(),
                'action_id' => $actionId,
                'action_type' => $type,
                'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE),
                'event_json' => json_encode([
                    'prev_state_id' => $event->getPrevStateId(),
                    'new_state_id' => $event->getNewStateId(),
                    'prev_label' => $event->getPrevLabel(),
                    'new_label' => $event->getNewLabel(),
                    'prev_action' => $event->getPrevAction()?->value,
                    'new_action' => $event->getNewAction()?->value,
                    'event_id' => $event->getEventId(),
                    'claim' => $event->getOrder()['_claim'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
                'status' => 'PENDING',
                'attempts' => 0,
                'max_attempts' => $type === 'webhook' ? 5 : 3,
                'next_attempt_at' => null,
            ]);
        } catch (\Throwable $e) {
            // 동시 요청의 UNIQUE 충돌이면 먼저 생성된 실행이 담당한다. 그 외 DB 오류를
            // 중복으로 오인해 Action을 조용히 유실하지 않는다.
            try {
                if ($this->repository->findByKey($key) !== null) {
                    return;
                }
            } catch (\Throwable) {
                // 최초 예외가 실제 원인이므로 그대로 전달한다.
            }
            throw $e;
        }

        // 웹훅은 다음 요청의 짧은 큐 스윕에서 실행해 주문 처리 응답을 막지 않는다.
        if ($type !== 'webhook') {
            $this->run($executionId, $event);
        }
    }

    public function maybeRunDue(int $domainId, int $limit = 3): void
    {
        // 프로세스 종료·타임아웃으로 남은 RUNNING을 먼저 회수한다.
        $this->repository->recoverStaleRunning($domainId);

        $cacheKey = "shop:action_retry:{$domainId}";
        if ($this->cache?->has($cacheKey)) {
            return;
        }
        $this->cache?->set($cacheKey, 1, 30);

        foreach ($this->repository->findDueIds($domainId, $limit) as $executionId) {
            $this->run($executionId);
        }
    }

    private function run(int $executionId, ?OrderStatusChangedEvent $event = null): void
    {
        if (!$this->repository->claim($executionId)) {
            return;
        }
        $row = $this->repository->find($executionId);
        if ($row === null) {
            return;
        }

        try {
            $config = json_decode((string) $row['config_json'], true, 512, JSON_THROW_ON_ERROR);
            if ((string) $row['action_type'] === 'webhook') {
                $config['_delivery_id'] = (string) ($row['delivery_id'] ?? '');
            }
            $event ??= $this->rebuildEvent($row);
            $this->registry->getHandler((string) $row['action_type'])->execute($config, $event);
            $this->repository->markSuccess($executionId);
        } catch (\Throwable $e) {
            $this->repository->markFailed($executionId, (int) ($row['attempts'] ?? 1), $e->getMessage());
            $this->logger?->warning('Shop Action 실행 실패(재시도 예정)', [
                'execution_id' => $executionId,
                'order_no' => $row['order_no'] ?? '',
                'action_type' => $row['action_type'] ?? '',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function rebuildEvent(array $row): OrderStatusChangedEvent
    {
        $order = $this->orderRepository->find((string) $row['order_no']);
        if ($order === null || (int) ($order->toArray()['domain_id'] ?? 0) !== (int) $row['domain_id']) {
            throw new \RuntimeException('Action 재시도 대상 주문을 찾을 수 없습니다.');
        }
        $orderData = $order->toArray();
        if ($this->encryptionService !== null) {
            foreach (self::ENCRYPTED_FIELDS as $field) {
                if (!empty($orderData[$field])) {
                    $orderData[$field] = $this->encryptionService->decrypt((string) $orderData[$field]) ?? $orderData[$field];
                }
            }
        }
        $meta = json_decode((string) $row['event_json'], true, 512, JSON_THROW_ON_ERROR);
        if (is_array($meta['claim'] ?? null)) {
            $orderData['_claim'] = $meta['claim'];
        }

        return new OrderStatusChangedEvent(
            (string) $row['order_no'],
            (string) ($meta['prev_state_id'] ?? ''),
            (string) ($meta['new_state_id'] ?? ''),
            (string) ($meta['prev_label'] ?? ''),
            (string) ($meta['new_label'] ?? ''),
            OrderAction::tryFrom((string) ($meta['prev_action'] ?? '')),
            OrderAction::tryFrom((string) ($meta['new_action'] ?? '')),
            $orderData,
            isset($meta['event_id']) ? (string) $meta['event_id'] : null,
        );
    }
}
