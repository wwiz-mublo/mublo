<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Api;

use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Contract\Extension\ShopCommandInterface;
use Mublo\Packages\Shop\Entity\Order;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\ProductService;

/** @internal ShopProvider가 공개 Contract 뒤에 바인딩하는 구현체 */
final class ShopCommand implements ShopCommandInterface
{
    public function __construct(
        private ProductService $products,
        private OrderService $orders,
        private OrderRepository $orderRepository
    ) {
    }

    public function deleteProduct(int $goodsId, int $domainId): Result
    {
        return $this->products->delete($goodsId, $domainId);
    }

    public function changeOrderStatus(
        string $orderNo,
        string $newStateId,
        int $domainId,
        string $reason = ''
    ): Result {
        $order = $this->orderRepository->find($orderNo);
        if (!$order instanceof Order || $order->getDomainId() !== $domainId) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }

        return $this->orders->updateStatus(
            $orderNo,
            $newStateId,
            $domainId,
            $reason !== '' ? $reason : 'Shop 종속 플러그인 상태 변경',
            'SYSTEM'
        );
    }
}
