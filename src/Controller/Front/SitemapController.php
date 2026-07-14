<?php

namespace Mublo\Controller\Front;

use Mublo\Contract\Sitemap\SitemapUrlProviderInterface;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Core\Response\XmlResponse;
use Mublo\Infrastructure\Database\Database;

/**
 * 동적 sitemap.xml 생성
 *
 * 두 층으로 구성된다.
 *
 *  1) 코어층 — 어떤 확장이 깔려 있든 항상 동작한다.
 *     메인(/) + 공개 메뉴(menu_items) + 블록 페이지(/p/{code}).
 *     메뉴는 도메인이 실제로 노출하는 진입점이고 visibility/min_level 로
 *     공개 여부까지 들고 있어, 코어가 알아야 할 것이 여기에 다 있다.
 *
 *  2) 확장층 — SitemapUrlProviderInterface 구현체가 상세 URL 을 기여한다.
 *     코어는 확장의 테이블을 알지 못한다. 확장이 비활성이면 Provider 가
 *     로드되지 않으므로 URL 도 자동으로 사라진다.
 *
 * 라우트를 스캔해 자동 생성하지 않는 이유: /checkout/{orderCode},
 * /orders/{orderCode}, /auth/{provider} 처럼 사이트맵에 절대 들어가면 안 되는
 * 경로가 섞인다. 무엇이 공개 콘텐츠인지는 그 확장만 안다.
 */
class SitemapController
{
    /** 사이트맵 1개 파일의 URL 상한 (프로토콜 규격 50,000) */
    private const MAX_URLS = 50000;

    public function __construct(
        private Database $db,
        private ContractRegistry $registry,
    ) {
    }

    public function index(Request $request, Context $context): XmlResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $baseUrl  = $request->getScheme() . '://' . $request->getHost();

        $urls = [];
        $seen = [];

        $add = function (string $path, array $attrs = []) use (&$urls, &$seen, $baseUrl): bool {
            $path = $this->normalizePath($path);
            if ($path === null || isset($seen[$path])) {
                return true;
            }
            if (count($urls) >= self::MAX_URLS) {
                return false;
            }
            $seen[$path] = true;
            $urls[] = ['loc' => $baseUrl . $path] + $attrs;
            return true;
        };

        $add('/', ['changefreq' => 'daily', 'priority' => '1.0']);

        $truncated = false;
        foreach ($this->coreUrls($domainId) as $url) {
            if (!$add($url['path'], $this->attrs($url))) {
                $truncated = true;
                break;
            }
        }

        if (!$truncated) {
            foreach ($this->providerUrls($domainId) as $url) {
                if (!$add($url['path'], $this->attrs($url))) {
                    $truncated = true;
                    break;
                }
            }
        }

        if ($truncated) {
            error_log(sprintf(
                '[Sitemap] domain %d hit the %d URL cap; remaining URLs were dropped',
                $domainId,
                self::MAX_URLS
            ));
        }

        return new XmlResponse($this->renderXml($urls));
    }

    // ─────────────────────────────────────────
    // 코어층
    // ─────────────────────────────────────────

    /**
     * @return iterable<array<string, string>>
     */
    private function coreUrls(int $domainId): iterable
    {
        // 공개 메뉴
        //
        // visibility='all' 만 싣는다. guest 는 비로그인 전용(로그인/가입),
        // member 는 로그인 전용(마이페이지)이라 크롤러에게 의미가 없다.
        // min_level>0 또는 required_permission 이 걸린 메뉴도 제외한다.
        try {
            $menus = $this->db->select(
                "SELECT url, updated_at
                   FROM menu_items
                  WHERE domain_id = ?
                    AND is_active = 1
                    AND visibility = 'all'
                    AND target <> '_blank'
                    AND COALESCE(min_level, 0) = 0
                    AND COALESCE(required_permission, '') = ''
                  ORDER BY item_id",
                [$domainId]
            );
            foreach ($menus as $menu) {
                yield [
                    'path'       => (string) ($menu['url'] ?? ''),
                    'lastmod'    => (string) ($menu['updated_at'] ?? ''),
                    'changefreq' => 'weekly',
                    'priority'   => '0.8',
                ];
            }
        } catch (\Throwable $e) {
            error_log('[Sitemap] menu_items block failed: ' . $e->getMessage());
        }

        // 블록 페이지 (/p/{code})
        try {
            $pages = $this->db->select(
                "SELECT page_code, updated_at
                   FROM block_pages
                  WHERE domain_id = ?
                    AND is_active = 1
                    AND is_deleted = 0
                    AND COALESCE(allow_level, 0) = 0
                  ORDER BY page_id",
                [$domainId]
            );
            foreach ($pages as $page) {
                yield [
                    'path'       => '/p/' . (string) ($page['page_code'] ?? ''),
                    'lastmod'    => (string) ($page['updated_at'] ?? ''),
                    'changefreq' => 'weekly',
                    'priority'   => '0.7',
                ];
            }
        } catch (\Throwable $e) {
            error_log('[Sitemap] block_pages block failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────
    // 확장층
    // ─────────────────────────────────────────

    /**
     * @return iterable<array<string, string>>
     */
    private function providerUrls(int $domainId): iterable
    {
        foreach ($this->registry->keys(SitemapUrlProviderInterface::class) as $key) {
            try {
                $provider = $this->registry->get(SitemapUrlProviderInterface::class, $key);
            } catch (\Throwable $e) {
                error_log("[Sitemap] provider '{$key}' could not be resolved: " . $e->getMessage());
                continue;
            }

            if (!$provider instanceof SitemapUrlProviderInterface) {
                continue;
            }

            // 확장 하나가 죽어도 사이트맵 전체가 비지 않도록 개별로 감싼다
            try {
                foreach ($provider->sitemapUrls($domainId) as $url) {
                    if (is_array($url) && isset($url['path'])) {
                        yield $url;
                    }
                }
            } catch (\Throwable $e) {
                error_log("[Sitemap] provider '{$key}' failed: " . $e->getMessage());
            }
        }
    }

    // ─────────────────────────────────────────
    // 정규화 / 렌더
    // ─────────────────────────────────────────

    /**
     * 사이트맵에 실을 수 있는 경로인지 판정하고 정규화한다.
     *
     * canonical 은 쿼리스트링을 버리므로(FrontViewRenderer) 쿼리가 붙은 URL 을
     * 실으면 페이지가 선언한 canonical 과 불일치해 색인되지 않는다. 외부 링크와
     * 앵커도 이 사이트의 페이지가 아니므로 제외한다.
     */
    private function normalizePath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '' || $path === '#' || !str_starts_with($path, '/')) {
            return null; // 빈 값, 앵커, 외부 링크(http://...), 상대경로
        }
        if (str_starts_with($path, '//')) {
            return null; // 프로토콜 상대 외부 링크
        }
        if (str_contains($path, '?') || str_contains($path, '#')) {
            return null; // 쿼리·프래그먼트
        }

        // 관리자/API/인증 경로는 어떤 경로로 들어와도 싣지 않는다
        foreach (['/admin', '/api', '/install', '/auth', '/mypage', '/logout', '/login'] as $deny) {
            if ($path === $deny || str_starts_with($path, $deny . '/')) {
                return null;
            }
        }

        return rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
    }

    /**
     * @param array<string, string> $url
     * @return array<string, string>
     */
    private function attrs(array $url): array
    {
        $attrs = [];

        $lastmod = $this->formatDate((string) ($url['lastmod'] ?? ''));
        if ($lastmod !== '') {
            $attrs['lastmod'] = $lastmod;
        }
        if (!empty($url['changefreq'])) {
            $attrs['changefreq'] = (string) $url['changefreq'];
        }
        if (!empty($url['priority'])) {
            $attrs['priority'] = (string) $url['priority'];
        }

        return $attrs;
    }

    /**
     * @param array<int, array<string, string>> $urls
     */
    private function renderXml(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1) . "</loc>\n";
            foreach (['lastmod', 'changefreq', 'priority'] as $tag) {
                if (!empty($url[$tag])) {
                    $xml .= "    <{$tag}>" . htmlspecialchars($url[$tag], ENT_XML1) . "</{$tag}>\n";
                }
            }
            $xml .= "  </url>\n";
        }

        return $xml . '</urlset>';
    }

    private function formatDate(string $datetime): string
    {
        if (trim($datetime) === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($datetime))->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }
}
