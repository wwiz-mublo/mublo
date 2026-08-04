<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Api;

use Mublo\Packages\Shop\Contract\Extension\ShopCommandInterface;
use Mublo\Packages\Shop\Contract\Extension\ShopExtensionApiInterface;
use Mublo\Packages\Shop\Contract\Extension\ShopOrderReaderInterface;
use Mublo\Packages\Shop\Contract\Extension\ShopProductReaderInterface;

/** @internal 공개 Contract의 기본 조합 구현체 */
final class ShopExtensionApi implements ShopExtensionApiInterface
{
    public function __construct(
        private ShopProductReaderInterface $products,
        private ShopOrderReaderInterface $orders,
        private ShopCommandInterface $commands
    ) {
    }

    public function products(): ShopProductReaderInterface
    {
        return $this->products;
    }

    public function orders(): ShopOrderReaderInterface
    {
        return $this->orders;
    }

    public function commands(): ShopCommandInterface
    {
        return $this->commands;
    }
}
