<?php

namespace Mublo\Contract\Catalog;

use Mublo\Core\Result\Result;

/**
 * CatalogProvisioningInterface
 *
 * 확장이 사이트를 프로그래밍으로 구축할 때 항목 카탈로그를 멱등 생성한다.
 * 코어가 정의하고 카탈로그 확장이 구현한다.
 *
 * 카탈로그는 "항목 하나에 사진 여러 장 + 사진별 설명" 범위다 — 판매·장바구니가
 * 없는 순수 목록이며 제품·과정·시술·메뉴·사례에 공통으로 쓰인다.
 */
interface CatalogProvisioningInterface
{
    /**
     * 카탈로그를 멱등 보장
     *
     * `$provisioningKey` 는 카탈로그 슬러그로 쓰이며 도메인 안에서 유일해야 한다.
     *
     * @param array $preset catalog_name · placeholder_items[] 등.
     *                      플레이스홀더는 **신규 생성 시에만** 넣는다 — 운영자가
     *                      지운 샘플을 재시도가 되살리면 안 된다
     * @return Result 성공 data: {catalog_id: int, catalog_slug: string, created: bool}
     */
    public function ensureCatalog(int $domainId, string $provisioningKey, array $preset = []): Result;
}
