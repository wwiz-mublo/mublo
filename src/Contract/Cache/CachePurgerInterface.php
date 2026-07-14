<?php

namespace Mublo\Contract\Cache;

/**
 * CDN 캐시 퍼지 계약
 *
 * Cloudflare 등 CDN 서비스가 구현하여 도메인/페이지별 캐시를 퍼지한다.
 */
interface CachePurgerInterface
{
    /**
     * 도메인 캐시 퍼지
     *
     * @param int $domainId 도메인 ID
     * @param int|null $pageId 특정 페이지만 퍼지 (null이면 전체)
     */
    public function purgeForDomain(int $domainId, ?int $pageId = null): void;
}
