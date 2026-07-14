<?php

namespace Mublo\Packages\Board\Sitemap;

use Mublo\Contract\Sitemap\SitemapUrlProviderInterface;
use Mublo\Infrastructure\Database\Database;

/**
 * Board 패키지 사이트맵 URL 제공자
 *
 * 코어 SitemapController 가 ContractRegistry 를 통해 소비한다.
 *
 * ── 무엇을 싣는가 ──
 *  1. 커뮤니티 목록 (/community, /community/group/{slug})
 *     — 공개 글이 1건이라도 있을 때만. 빈 목록 페이지는 만들지 않는다(계약 5).
 *  2. 게시글 상세 (/board/{board_slug}/view/{article_id}[/{slug}])
 *
 * ── 무엇을 싣지 않는가 ──
 *  - 비밀글(board_articles.is_secret), 개별 글 읽기 레벨(board_articles.read_level > 0)
 *  - 삭제/임시저장 글(board_articles.status <> 'published'), 예약 발행 미도래 글(published_at)
 *  - 로그인/레벨이 걸린 게시판(board_configs.list_level·read_level > 0)
 *  - 비밀게시판(board_configs.is_secret_board), 비활성 게시판(is_active = 0)
 *  - 비공개/비활성 그룹(board_groups.list_level·read_level > 0, is_active = 0)
 *  - 카테고리 매핑으로 레벨이 덮인 글(board_category_mapping.list_level·read_level > 0)
 *  - 작성/수정/삭제 폼, 파일 다운로드, 비밀번호 확인 등 상태가 걸린 경로 전부
 *  - /community/popular — /community 와 같은 글을 정렬만 바꿔 보여주는 중복 목록
 *
 * 공지글(is_notice = 1)은 제외하지 않는다. 목록 상단에 고정될 뿐 권한 체계상
 * 일반 글과 동일한 공개 콘텐츠이며, 실제로 검색 유입 가치가 있는 본문이다.
 *
 * 레벨 판정 기준은 BoardPermissionService 와 같다. 비회원 레벨은 0 이므로
 * "필요 레벨 = 0" 인 경우에만 비로그인 크롤러가 실제로 읽을 수 있다.
 * 권한 우선순위(글 → 카테고리매핑 → 게시판 → 그룹)에서 상위 단계가 NULL 이면
 * 하위로 내려가므로, 여기서는 네 단계를 모두 0 으로 요구해 보수적으로 거른다.
 */
class BoardSitemapProvider implements SitemapUrlProviderInterface
{
    /** 사이트맵에 실을 게시글 상한 (게시판은 수십만 건까지 커질 수 있다) */
    private const MAX_ARTICLES = 20000;

    /** 한 번에 읽어올 행 수 — 전량을 메모리에 올리지 않기 위한 청크 크기 */
    private const CHUNK_SIZE = 500;

    public function __construct(
        private Database $db,
    ) {
    }

    /**
     * 공개 글만 남기는 WHERE 절 (게시글/커뮤니티 목록이 공유한다)
     *
     * 바인딩 순서: [domainId, domainId]
     */
    private const PUBLIC_ARTICLE_WHERE = <<<'SQL'
              FROM board_articles AS a
        INNER JOIN board_configs AS b ON b.board_id = a.board_id
        INNER JOIN board_groups  AS g ON g.group_id = b.group_id
         LEFT JOIN board_category_mapping AS m
                ON m.board_id = a.board_id
               AND m.category_id = a.category_id
             WHERE a.domain_id = ?
               AND (b.domain_id = ? OR b.is_global = 1)
               -- 글 단위 공개 조건
               AND a.status = 'published'
               AND a.is_secret = 0
               AND COALESCE(a.read_level, 0) = 0
               AND (a.published_at IS NULL OR a.published_at <= NOW())
               -- 게시판 단위 공개 조건 (가장 놓치기 쉬운 관문)
               AND b.is_active = 1
               AND b.is_secret_board = 0
               AND b.use_separate_table = 0
               AND b.list_level = 0
               AND b.read_level = 0
               -- 그룹 단위 공개 조건
               AND g.is_active = 1
               AND g.list_level = 0
               AND g.read_level = 0
               -- 카테고리 매핑 오버라이드 (매핑이 없으면 게시판 레벨을 따르므로 통과)
               AND (
                     a.category_id IS NULL
                     OR m.mapping_id IS NULL
                     OR (
                          m.is_active = 1
                          AND COALESCE(m.list_level, 0) = 0
                          AND COALESCE(m.read_level, 0) = 0
                        )
                   )
        SQL;

    /**
     * {@inheritDoc}
     */
    public function sitemapUrls(int $domainId): iterable
    {
        yield from $this->communityUrls($domainId);
        yield from $this->articleUrls($domainId);
    }

    // ─────────────────────────────────────────
    // 커뮤니티 목록
    // ─────────────────────────────────────────

    /**
     * /community 와 /community/group/{slug}
     *
     * 두 경로 모두 PrefixedRouteCollector 의 addRawRoute 로 등록돼 있어
     * 'board' 접두사가 붙지 않는다(routes.php 참조).
     *
     * @return iterable<array<string, string>>
     */
    private function communityUrls(int $domainId): iterable
    {
        try {
            // 전체 피드 — 공개 글이 하나라도 있어야 싣는다
            $feed = $this->db->selectOne(
                'SELECT COUNT(*) AS cnt, MAX(a.updated_at) AS lastmod ' . self::PUBLIC_ARTICLE_WHERE,
                [$domainId, $domainId]
            );

            if ((int) ($feed['cnt'] ?? 0) === 0) {
                return; // 공개 글이 없으면 그룹 목록도 볼 것이 없다
            }

            yield [
                'path'       => '/community',
                'lastmod'    => (string) ($feed['lastmod'] ?? ''),
                'changefreq' => 'daily',
                'priority'   => '0.7',
            ];

            // 그룹별 피드 — 공개 글이 있는 그룹만
            $groups = $this->db->select(
                'SELECT g.group_slug, MAX(a.updated_at) AS lastmod '
                . self::PUBLIC_ARTICLE_WHERE
                . ' GROUP BY g.group_id, g.group_slug ORDER BY g.group_id',
                [$domainId, $domainId]
            );

            foreach ($groups as $group) {
                $slug = trim((string) ($group['group_slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }

                yield [
                    'path'       => '/community/group/' . rawurlencode($slug),
                    'lastmod'    => (string) ($group['lastmod'] ?? ''),
                    'changefreq' => 'daily',
                    'priority'   => '0.6',
                ];
            }
        } catch (\Throwable $e) {
            error_log('[Sitemap][Board] community block failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────
    // 게시글 상세
    // ─────────────────────────────────────────

    /**
     * /board/{board_slug}/view/{article_id}[/{slug}]
     *
     * '/{board_id}/view/{post_no}' 는 PrefixedRouteCollector 의 addRoute 로
     * 등록돼 'board' 접두사가 붙는다. {board_id} 는 숫자 PK 가 아니라
     * board_configs.board_slug 이고, {post_no} 는 board_articles.article_id 다
     * (BoardController::view 참조).
     *
     * article_id DESC 키셋 페이징으로 청크를 돌며 yield 한다. 전량을 배열로
     * 쌓지 않으므로 게시판이 커져도 메모리가 터지지 않는다.
     *
     * @return iterable<array<string, string>>
     */
    private function articleUrls(int $domainId): iterable
    {
        $sql = 'SELECT a.article_id, a.slug, a.updated_at, b.board_slug '
             . self::PUBLIC_ARTICLE_WHERE
             . ' AND a.article_id < ?'
             . ' ORDER BY a.article_id DESC'
             . ' LIMIT ' . self::CHUNK_SIZE;

        $cursor  = PHP_INT_MAX;
        $emitted = 0;

        try {
            while ($emitted < self::MAX_ARTICLES) {
                $rows = $this->db->select($sql, [$domainId, $domainId, $cursor]);

                if (empty($rows)) {
                    return; // 더 실을 글이 없다 — 잘린 것이 아니므로 로그도 없다
                }

                foreach ($rows as $row) {
                    $cursor = (int) $row['article_id'];

                    $path = $this->articlePath(
                        (string) ($row['board_slug'] ?? ''),
                        $cursor,
                        (string) ($row['slug'] ?? '')
                    );
                    if ($path === null) {
                        continue;
                    }

                    yield [
                        'path'       => $path,
                        'lastmod'    => (string) ($row['updated_at'] ?? ''),
                        'changefreq' => 'weekly',
                        'priority'   => '0.6',
                    ];

                    if (++$emitted >= self::MAX_ARTICLES) {
                        break;
                    }
                }
            }

            // 상한에 걸렸다 — 남은 글이 실제로 있는지 확인하고 반드시 남긴다.
            // 조용한 잘림은 금지다.
            $remaining = $this->db->selectOne(
                'SELECT a.article_id ' . self::PUBLIC_ARTICLE_WHERE
                . ' AND a.article_id < ? ORDER BY a.article_id DESC LIMIT 1',
                [$domainId, $domainId, $cursor]
            );

            if ($remaining !== null) {
                error_log(sprintf(
                    '[Sitemap][Board] domain %d hit the %d article cap; '
                    . 'articles older than article_id %d were dropped',
                    $domainId,
                    self::MAX_ARTICLES,
                    $cursor
                ));
            }
        } catch (\Throwable $e) {
            error_log('[Sitemap][Board] article block failed: ' . $e->getMessage());
        }
    }

    /**
     * 게시글 상세 경로 생성
     *
     * slug 세그먼트는 내부 링크(ArticlePresenter)와 같은 형태로 붙인다. 다만
     * urlencode/rawurlencode 결과가 갈리는 문자(공백 등)가 들어 있으면 canonical
     * 과 어긋날 수 있으므로, 인코딩이 필요 없는 slug 일 때만 붙이고 아니면
     * slug 없는 형태로 낸다(라우트에서 slug 는 선택 세그먼트다).
     */
    private function articlePath(string $boardSlug, int $articleId, string $slug): ?string
    {
        $boardSlug = trim($boardSlug);
        if ($boardSlug === '' || $articleId <= 0) {
            return null;
        }

        $path = '/board/' . rawurlencode($boardSlug) . '/view/' . $articleId;

        $slug = trim($slug);
        if ($slug !== '' && preg_match('/^[A-Za-z0-9._~-]+$/', $slug) === 1) {
            $path .= '/' . $slug;
        }

        return $path;
    }
}
