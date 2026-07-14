<?php

namespace Mublo\Packages\Shop\Contract\Extension;

use Mublo\Core\Result\Result;

/**
 * Shop 확장이 도메인 검증을 거쳐 변경을 요청하는 안정 API.
 */
interface ShopCommandInterface
{
    public function deleteProduct(int $goodsId, int $domainId): Result;

    public function changeOrderStatus(
        string $orderNo,
        string $newStateId,
        int $domainId,
        string $reason = ''
    ): Result;
}
