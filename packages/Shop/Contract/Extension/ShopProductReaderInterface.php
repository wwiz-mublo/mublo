<?php

namespace Mublo\Packages\Shop\Contract\Extension;

use Mublo\Packages\Shop\Api\DTO\ProductSnapshot;

/**
 * Shop 확장이 현재 도메인의 상품을 조회하는 안정 API.
 */
interface ShopProductReaderInterface
{
    public function findAccessibleById(int $goodsId, int $domainId): ?ProductSnapshot;
}
