<?php
declare(strict_types=1);
namespace Mublo\Plugin\Faq\Sitemap;

use Mublo\Contract\Sitemap\SitemapUrlProviderInterface;
use Mublo\Plugin\Faq\Repository\FaqRepository;

/**
 * FaqSitemapProvider
 *
 * FAQ 플러그인이 사이트맵에 기여하는 공개 URL 목록.
 *
 * 싣는 것: 카테고리별 FAQ 페이지 `/faq/{slug}` 하나뿐이다.
 * 플러그인 라우트는 PrefixedRouteCollector 를 거치므로 routes.php 의
 * '/{slug}' 는 실제로 '/faq/{slug}' 로 노출된다.
 *
 * 싣지 않는 것:
 * - `/faq/admin/...` 관리자 경로 — 권한이 걸린 비공개 화면(계약 1번).
 * - `/faq/api/list` — HTML 페이지가 아닌 JSON 응답이라 색인 대상이 아니다.
 * - `/faq` 목록 자체 — 설치 시 menu_items 에 등록되므로 코어층이 이미 싣는다.
 * - 활성 항목이 0개인 카테고리 — 얇은 빈 목록 페이지(계약 5번).
 *
 * 모든 조회는 $domainId 로 거르고(계약 2번), 호스트 없이 path 만 반환하며
 * (계약 3번) 쿼리스트링은 붙이지 않는다(계약 4번).
 */
class FaqSitemapProvider implements SitemapUrlProviderInterface
{
    /** 프론트 라우트 프리픽스 (플러그인 디렉터리명 소문자) */
    private const PREFIX = '/faq';

    /** routes.php 의 '/{slug:[a-z0-9\-]+}' 와 동일한 제약 */
    private const SLUG_PATTERN = '/^[a-z0-9\-]+$/';

    public function __construct(
        private FaqRepository $repository,
    ) {
    }

    /**
     * @return iterable<array{path: string, lastmod?: string, changefreq?: string, priority?: string}>
     */
    public function sitemapUrls(int $domainId): iterable
    {
        foreach ($this->repository->findSitemapCategories($domainId) as $category) {
            $slug = trim((string) ($category['category_slug'] ?? ''));

            // 라우트가 받지 못하는 슬러그는 404 가 되므로 싣지 않는다
            if ($slug === '' || !preg_match(self::SLUG_PATTERN, $slug)) {
                continue;
            }

            $url = [
                'path'       => self::PREFIX . '/' . $slug,
                'changefreq' => 'monthly',
                'priority'   => '0.5',
            ];

            $lastmod = trim((string) ($category['lastmod'] ?? ''));
            if ($lastmod !== '') {
                $url['lastmod'] = $lastmod;
            }

            yield $url;
        }
    }
}
