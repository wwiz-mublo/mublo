<?php

namespace Mublo\Packages\Shop\Api;

use Mublo\Packages\Shop\Api\DTO\OrderSnapshot;
use Mublo\Packages\Shop\Contract\Extension\ShopOrderReaderInterface;
use Mublo\Packages\Shop\Entity\Order;
use Mublo\Packages\Shop\Repository\OrderRepository;

/** @internal ShopProvider가 공개 Contract 뒤에 바인딩하는 구현체 */
final class ShopOrderReader implements ShopOrderReaderInterface
{
    public function __construct(private OrderRepository $orders)
    {
    }

    public function findAccessibleByOrderNo(string $orderNo, int $domainId): ?OrderSnapshot
    {
        $order = $this->orders->find($orderNo);
        if (!$order instanceof Order || $order->getDomainId() !== $domainId) {
            return null;
        }

        return new OrderSnapshot(
            $order->getOrderNo(),
            $order->getDomainId(),
            $order->getMemberId(),
            $order->getOrderStatusRaw() ?? '',
            $order->getTotalPrice(),
            $order->getFinalAmount(),
            $order->getPaymentMethod()->value,
            $order->isComplete(),
            $order->getCreatedAt()
        );
    }
}
