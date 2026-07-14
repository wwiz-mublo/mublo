<?php

namespace Mublo\Contract\Faq;

use Mublo\Core\Result\Result;

/**
 * FaqProvisioningInterface
 *
 * 확장이 사이트를 프로그래밍으로 구축할 때 FAQ 카테고리를 멱등 생성한다.
 * 코어가 정의하고 Faq 플러그인이 구현한다 — 같은 네임스페이스의
 * `FaqQueryInterface`(조회)와 짝을 이룬다.
 */
interface FaqProvisioningInterface
{
    /**
     * FAQ 카테고리를 멱등 보장
     *
     * `$provisioningKey` 는 `faq_categories.category_slug` 로 쓰인다 —
     * `UNIQUE(domain_id, category_slug)` 가 동시 재시도를 막는다.
     *
     * @param array $preset category_name · sort_order 등.
     *                      기존 카테고리가 있으면 **덮지 않는다**
     * @return Result 성공 data: {category_id: int, category_slug: string, created: bool}
     */
    public function ensureCategory(int $domainId, string $provisioningKey, array $preset = []): Result;
}
