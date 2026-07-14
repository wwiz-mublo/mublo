<?php

namespace Mublo\Packages\Shop\Action;

use Mublo\Contract\Balance\BalanceGatewayInterface;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Infrastructure\Log\Logger;

/**
 * PointActionHandler
 *
 * 포인트 적립 액션
 *
 * BalanceGatewayInterface를 통해 주문인(회원)에게 포인트를 자동 지급한다.
 * 비회원 주문은 스킵. idempotency_key로 중복 지급 방지.
 */
class PointActionHandler implements ActionHandlerInterface
{
    private BalanceGatewayInterface $balanceManager;

    public function __construct(BalanceGatewayInterface $balanceManager, private ?Logger $logger = null)
    {
        $this->balanceManager = $balanceManager;
    }

    public function execute(array $config, OrderStatusChangedEvent $event): void
    {
        $order = $event->getOrder();
        $memberId = (int) ($order['member_id'] ?? 0);

        // 비회원이면 스킵
        if ($memberId <= 0) {
            return;
        }

        $amount = $this->calculateAmount($config, $order);
        if ($amount <= 0) {
            return;
        }

        $reason = $config['reason'] ?? '주문 포인트 지급';
        $orderNo = $event->getOrderNo();

        $result = $this->balanceManager->adjust([
            'domain_id' => (int) ($order['domain_id'] ?? 1),
            'member_id' => $memberId,
            'amount' => $amount,
            'source_type' => 'package',
            'source_name' => 'Shop',
            'action' => 'order_point_grant',
            'message' => $reason,
            'reference_type' => 'shop_order',
            'reference_id' => $orderNo,
            'idempotency_key' => $this->idempotencyKey($orderNo, $event->getNewStateId(), $config),
        ]);

        if ($result->isFailure()) {
            $this->logger?->warning('OrderStateAction:point 지급 실패', [
                'order_no' => $orderNo,
                'member_id' => $memberId,
                'amount' => $amount,
                'error' => $result->getMessage(),
            ]);
            throw new \RuntimeException($result->getMessage());
        } else {
            $this->logger?->info('OrderStateAction:point 지급 성공', [
                'order_no' => $orderNo,
                'member_id' => $memberId,
                'amount' => $amount,
            ]);
        }
    }

    private function idempotencyKey(string $orderNo, string $stateId, array $config): string
    {
        $actionId = (string) ($config['action_id'] ?? hash('sha256', json_encode($config)));
        return 'shop_point_grant_' . hash('sha256', "{$orderNo}|{$stateId}|{$actionId}");
    }

    /**
     * 지급 금액 계산
     */
    private function calculateAmount(array $config, array $order): int
    {
        $amountType = $config['amount_type'] ?? 'fixed';
        $value = (int) ($config['amount'] ?? 0);

        if ($value <= 0) {
            return 0;
        }

        if ($amountType === 'percent') {
            $totalAmount = (int) ($order['total_amount'] ?? 0);
            return (int) floor($totalAmount * $value / 100);
        }

        return $value;
    }

    public function getType(): string
    {
        return 'point';
    }

    public function getLabel(): string
    {
        return '포인트 적립';
    }

    public function getDescription(): string
    {
        return '주문인(회원)에게 포인트를 자동 지급합니다. 정액 또는 주문금액 비율로 설정할 수 있습니다.';
    }

    public function allowDuplicate(): bool
    {
        return false;
    }

    public function getSchema(): array
    {
        return [
            'required' => ['amount_type', 'amount'],
            'fields' => [
                'amount_type' => [
                    'type' => 'select',
                    'label' => '적립 방식',
                    'options' => [
                        'fixed' => '정액 (원)',
                        'percent' => '비율 (%)',
                    ],
                ],
                'amount' => [
                    'type' => 'number',
                    'label' => '적립량',
                    'placeholder' => '예: 500',
                ],
                'reason' => [
                    'type' => 'text',
                    'label' => '적립 사유',
                    'placeholder' => '예: 구매확정 적립',
                ],
            ],
        ];
    }
}
