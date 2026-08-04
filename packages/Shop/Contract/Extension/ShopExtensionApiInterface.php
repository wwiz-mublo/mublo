<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Contract\Extension;

/**
 * Shop 종속 Plugin이 사용하는 단일 공개 진입점.
 */
interface ShopExtensionApiInterface
{
    public function products(): ShopProductReaderInterface;

    public function orders(): ShopOrderReaderInterface;

    public function commands(): ShopCommandInterface;
}
