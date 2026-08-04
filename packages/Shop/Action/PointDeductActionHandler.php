<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Action;

use Mublo\Contract\Balance\BalanceGatewayInterface;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Infrastructure\Log\Logger;

/**
 * PointDeductActionHandler
 *
 * 주문 취소/반품 등 상태 진입 시 지급된 포인트를 자동 회수한다.
 * 전액 환수: 해당 주문으로 지급된 포인트 전액 차감
 * 정액/비율: 설정 금액만큼 차감
 */
class PointDeductActionHandler implements ActionHandlerInterface
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

        $orderNo = $event->getOrderNo();
        $deductType = $config['deduct_type'] ?? 'all';

        $amount = $this->calculateDeductAmount($deductType, $config, $order, $memberId);
        if ($amount <= 0) {
            return;
        }

        $reason = $config['reason'] ?? '주문 포인트 환수';

        $result = $this->balanceManager->adjust([
            'domain_id' => (int) ($order['domain_id'] ?? 1),
            'member_id' => $memberId,
            'amount' => -$amount, // 음수 = 차감
            'source_type' => 'package',
            'source_name' => 'Shop',
            'action' => 'order_point_deduct',
            'message' => $reason,
            'reference_type' => 'shop_order',
            'reference_id' => $orderNo,
            'idempotency_key' => $this->idempotencyKey($orderNo, $event->getNewStateId(), $config),
        ]);

        if ($result->isFailure()) {
            $this->logger?->warning('OrderStateAction:point_deduct 환수 실패', [
                'order_no' => $orderNo,
                'member_id' => $memberId,
                'amount' => $amount,
                'error' => $result->getMessage(),
            ]);
            throw new \RuntimeException($result->getMessage());
        } else {
            $this->logger?->info('OrderStateAction:point_deduct 환수 성공', [
                'order_no' => $orderNo,
                'member_id' => $memberId,
                'amount' => $amount,
            ]);
        }
    }

    private function idempotencyKey(string $orderNo, string $stateId, array $config): string
    {
        $actionId = (string) ($config['action_id'] ?? hash('sha256', json_encode($config)));
        return 'shop_point_deduct_' . hash('sha256', "{$orderNo}|{$stateId}|{$actionId}");
    }

    /**
     * 환수 금액 계산
     */
    private function calculateDeductAmount(string $type, array $config, array $order, int $memberId): int
    {
        if ($type === 'all') {
            return $this->findGrantedAmount($memberId, $order);
        }

        $value = (int) ($config['amount'] ?? 0);
        if ($value <= 0) {
            return 0;
        }

        if ($type === 'percent') {
            $totalAmount = (int) ($order['total_amount'] ?? 0);
            return (int) floor($totalAmount * $value / 100);
        }

        return $value;
    }

    /**
     * 해당 주문으로 지급된 포인트 금액 조회 (원장 SUM 집계)
     */
    private function findGrantedAmount(int $memberId, array $order): int
    {
        $orderNo = (string) ($order['order_no'] ?? '');
        $domainId = (int) ($order['domain_id'] ?? 1);
        if ($orderNo === '') {
            return 0;
        }

        return $this->balanceManager->sumGrantedByReference(
            $memberId,
            $domainId,
            'order_point_grant',
            'shop_order',
            $orderNo
        );
    }

    public function getType(): string
    {
        return 'point_deduct';
    }

    public function getLabel(): string
    {
        return '포인트 환수';
    }

    public function getDescription(): string
    {
        return '주문인(회원)에게 지급된 포인트를 자동 회수합니다. 전액 환수 또는 정액/비율로 설정할 수 있습니다.';
    }

    public function allowDuplicate(): bool
    {
        return false;
    }

    public function getSchema(): array
    {
        return [
            'required' => ['deduct_type'],
            'fields' => [
                'deduct_type' => [
                    'label' => '환수 방식',
                    'type' => 'select',
                    'options' => [
                        'all' => '전액 환수',
                        'fixed' => '정액 (원)',
                        'percent' => '비율 (%)',
                    ],
                ],
                'amount' => [
                    'label' => '금액/비율',
                    'type' => 'number',
                    'placeholder' => '전액 환수 시 비워두세요',
                ],
                'reason' => [
                    'label' => '환수 사유',
                    'type' => 'text',
                    'placeholder' => '포인트 환수 사유',
                ],
            ],
        ];
    }
}
