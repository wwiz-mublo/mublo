<?php
namespace Mublo\Packages\Board\Repository;

use Mublo\Packages\Board\Entity\BoardLink;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * BoardLink Repository
 *
 * 링크 데이터베이스 접근 담당
 *
 * 책임:
 * - board_links 테이블 CRUD
 * - BoardLink Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class BoardLinkRepository extends BaseRepository
{
    protected string $table = 'board_links';
    protected string $entityClass = BoardLink::class;
    protected string $primaryKey = 'link_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 게시글별 링크 목록 조회
     *
     * @param int $articleId 게시글 ID
     * @return BoardLink[]
     */
    public function findByArticle(int $articleId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('article_id', '=', $articleId)
            ->orderBy('created_at', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 게시글별 링크 수 조회
     */
    public function countByArticle(int $articleId): int
    {
        return $this->getDb()->table($this->table)
            ->where('article_id', '=', $articleId)
            ->count();
    }

    /**
     * 게시판별 링크 수 조회
     */
    public function countByBoard(int $boardId): int
    {
        return $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->count();
    }

    /**
     * 게시글별 링크 삭제
     *
     * @param int $articleId 게시글 ID
     * @return int 삭제된 행 수
     */
    public function deleteByArticle(int $articleId): int
    {
        return $this->getDb()->table($this->table)
            ->where('article_id', '=', $articleId)
            ->delete();
    }

    /**
     * 클릭 횟수 증가
     */
    public function incrementClickCount(int $linkId): void
    {
        $table = $this->getDb()->prefixTable($this->table);
        $this->getDb()->execute(
            "UPDATE {$table} SET click_count = click_count + 1 WHERE link_id = ?",
            [$linkId]
        );
    }

    /**
     * URL로 조회
     */
    public function findByUrl(int $articleId, string $url): ?BoardLink
    {
        return $this->findOneBy([
            'article_id' => $articleId,
            'link_url' => $url,
        ]);
    }

    /**
     * URL 중복 검사
     */
    public function existsByUrl(int $articleId, string $url): bool
    {
        return $this->existsBy([
            'article_id' => $articleId,
            'link_url' => $url,
        ]);
    }

    /**
     * OG 정보 업데이트
     */
    public function updateOgInfo(int $linkId, ?string $title, ?string $description, ?string $image): bool
    {
        $affected = $this->getDb()->table($this->table)
            ->where('link_id', '=', $linkId)
            ->update([
                'link_title' => $title,
                'link_description' => $description,
                'link_image' => $image,
            ]);

        return $affected > 0;
    }

    /**
     * 인기 링크 조회 (클릭 수 기준)
     *
     * @param int $domainId 도메인 ID
     * @param int $limit 조회 개수
     * @return BoardLink[]
     */
    public function getPopularLinks(int $domainId, int $limit = 10): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('click_count', 'DESC')
            ->limit($limit)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * OG 정보 없는 링크 조회 (크롤링 대상)
     *
     * @param int $limit 조회 개수
     * @return BoardLink[]
     */
    public function getLinksWithoutOgInfo(int $limit = 50): array
    {
        $rows = $this->getDb()->table($this->table)
            ->whereRaw('(link_title IS NULL AND link_description IS NULL AND link_image IS NULL)')
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 도메인별 링크 통계
     *
     * @param int $boardId 게시판 ID
     * @return array ['example.com' => 10, 'google.com' => 5, ...]
     */
    public function getStatsByDomain(int $boardId): array
    {
        $links = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->get();

        $result = [];
        foreach ($links as $link) {
            $parsed = parse_url($link['link_url']);
            $host = $parsed['host'] ?? 'unknown';

            if (!isset($result[$host])) {
                $result[$host] = 0;
            }
            $result[$host]++;
        }

        arsort($result);
        return $result;
    }

    /**
     * 최근 링크 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $limit 조회 개수
     * @return BoardLink[]
     */
    public function getRecentLinks(int $domainId, int $limit = 10): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();

        return $this->toEntities($rows);
    }
}
