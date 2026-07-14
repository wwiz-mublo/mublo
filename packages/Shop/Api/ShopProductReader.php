<?php

namespace Mublo\Packages\Shop\Api;

use Mublo\Packages\Shop\Api\DTO\ProductSnapshot;
use Mublo\Packages\Shop\Contract\Extension\ShopProductReaderInterface;
use Mublo\Packages\Shop\Entity\Product;
use Mublo\Packages\Shop\Repository\ProductRepository;

/** @internal ShopProvider가 공개 Contract 뒤에 바인딩하는 구현체 */
final class ShopProductReader implements ShopProductReaderInterface
{
    public function __construct(private ProductRepository $products)
    {
    }

    public function findAccessibleById(int $goodsId, int $domainId): ?ProductSnapshot
    {
        $product = $this->products->findInDomain($domainId, $goodsId);
        if (!$product instanceof Product) {
            return null;
        }

        return new ProductSnapshot(
            $product->getGoodsId(),
            $product->getDomainId(),
            $product->getItemCode(),
            $product->getGoodsName(),
            $product->getGoodsSlug(),
            $product->getCategoryCode(),
            $product->getDisplayPrice(),
            $product->getStockQuantity(),
            $product->isActive(),
            $product->getCreatedAt()
        );
    }
}
