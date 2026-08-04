<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Contract\Extension;

use Mublo\Packages\Shop\Api\DTO\OrderSnapshot;

/**
 * Shop 확장이 현재 도메인의 주문을 PII 없이 조회하는 안정 API.
 */
interface ShopOrderReaderInterface
{
    public function findAccessibleByOrderNo(string $orderNo, int $domainId): ?OrderSnapshot;
}
