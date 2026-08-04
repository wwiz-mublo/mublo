<?php
declare(strict_types=1);
namespace Mublo\Plugin\Manual\Sitemap;

use Mublo\Contract\Sitemap\SitemapUrlProviderInterface;
use Mublo\Infrastructure\Database\Database;

/**
 * ManualSitemapProvider
 *
 * 매뉴얼(문서) 공개 URL 을 사이트맵에 기여한다.
 *
 * 싣는 것
 *  - 책 랜딩:  /manual/{bookSlug}
 *  - 개별 페이지: /manual/{bookSlug}/{pageSlug}
 *
 * 싣지 않는 것
 *  - /admin/manual/... (관리자·작성/수정 폼)
 *  - 비활성(is_active = 0) 책·페이지
 *  - 다른 도메인의 책 (manual_books.domain_id 로 필터)
 *  - 활성 페이지가 하나도 없는 책 — 열어도 내용이 없으므로 계약 5번(빈 목록 금지)에 걸린다.
 *
 * 경로는 라우트 접두사가 붙은 형태로 직접 만든다(plugins/Manual/routes.php 는
 * PrefixedRouteCollector 로 '/manual' 접두사가 자동 적용되며 addRawRoute 는 쓰지 않는다).
 */
class ManualSitemapProvider implements SitemapUrlProviderInterface
{
    /** 라우트 접두사 — routes.php 의 프론트 라우트가 실제로 매칭되는 경로 */
    private const PREFIX = '/manual';

    /** 라우트 슬러그 제약({bookSlug:[a-z0-9\-]+})과 동일 — 라우팅되지 않을 URL 은 싣지 않는다 */
    private const SLUG_PATTERN = '/^[a-z0-9\-]+$/';

    public function __construct(private Database $db)
    {
    }

    /**
     * @return iterable<array{path: string, lastmod?: string, changefreq?: string, priority?: string}>
     */
    public function sitemapUrls(int $domainId): iterable
    {
        // 활성 책 × 활성 페이지를 한 번에 읽는다.
        // INNER JOIN 이므로 활성 페이지가 없는 책은 결과에 아예 나오지 않는다(계약 5번).
        // 책 단위로 정렬해 두고, 책이 바뀌는 첫 행에서 랜딩 URL 을 먼저 흘린다.
        $sql = "SELECT b.book_id,
                       b.slug        AS book_slug,
                       b.updated_at  AS book_updated_at,
                       p.slug        AS page_slug,
                       p.updated_at  AS page_updated_at,
                       (SELECT MAX(p2.updated_at)
                          FROM manual_pages p2
                         WHERE p2.book_id = b.book_id
                           AND p2.is_active = 1) AS book_page_updated_at
                  FROM manual_books b
            INNER JOIN manual_pages p
                    ON p.book_id = b.book_id
                   AND p.is_active = 1
                 WHERE b.domain_id = ?
                   AND b.is_active = 1
              ORDER BY b.sort_order ASC, b.book_id ASC, p.sort_order ASC, p.page_id ASC";

        $rows = $this->db->select($sql, [$domainId]);

        $currentBookId = null;

        foreach ($rows as $row) {
            $bookSlug = (string) ($row['book_slug'] ?? '');
            $pageSlug = (string) ($row['page_slug'] ?? '');

            if (!preg_match(self::SLUG_PATTERN, $bookSlug)) {
                continue;
            }

            $bookId = (int) ($row['book_id'] ?? 0);

            // 책이 바뀌는 첫 행 = 이 책의 랜딩 URL
            if ($bookId !== $currentBookId) {
                $currentBookId = $bookId;

                yield [
                    'path'       => self::PREFIX . '/' . $bookSlug,
                    'lastmod'    => $this->latest(
                        (string) ($row['book_updated_at'] ?? ''),
                        (string) ($row['book_page_updated_at'] ?? '')
                    ),
                    'changefreq' => 'weekly',
                    'priority'   => '0.8',
                ];
            }

            if (!preg_match(self::SLUG_PATTERN, $pageSlug)) {
                continue;
            }

            yield [
                'path'       => self::PREFIX . '/' . $bookSlug . '/' . $pageSlug,
                'lastmod'    => (string) ($row['page_updated_at'] ?? ''),
                'changefreq' => 'monthly',
                'priority'   => '0.6',
            ];
        }
    }

    /**
     * 책 랜딩의 lastmod — 책 자체와 소속 페이지 중 더 최근 시각.
     * 랜딩이 목차/첫 페이지를 렌더하므로 페이지 수정도 랜딩의 변경으로 본다.
     */
    private function latest(string $a, string $b): string
    {
        if (trim($a) === '') {
            return $b;
        }
        if (trim($b) === '') {
            return $a;
        }

        return strtotime($b) > strtotime($a) ? $b : $a;
    }
}
