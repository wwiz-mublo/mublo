<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Repository;

use Mublo\Packages\Board\Entity\BoardComment;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\BaseRepository;

/**
 * BoardComment Repository
 *
 * 댓글 데이터베이스 접근 담당
 *
 * 책임:
 * - board_comments 테이블 CRUD
 * - BoardComment Entity 반환
 * - 계층형 댓글 조회
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class BoardCommentRepository extends BaseRepository
{
    protected string $table = 'board_comments';
    protected string $entityClass = BoardComment::class;
    protected string $primaryKey = 'comment_id';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    /**
     * 게시글별 댓글 목록 조회 (계층형)
     *
     * @param int $articleId 게시글 ID
     * @param bool $includeDeleted 삭제된 댓글 포함 여부
     * @return BoardComment[]
     */
    public function getCommentsByArticle(int $articleId, bool $includeDeleted = false): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('article_id', '=', $articleId);

        if (!$includeDeleted) {
            $query->where('status', '=', 'published');
        }

        // path 기준 정렬 (계층 구조 유지)
        $rows = $query
            ->orderBy('path', 'ASC')
            ->orderBy('created_at', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 게시글별 댓글 목록 조회
     *
     * author_name이 board_comments에 저장되므로 members JOIN 불필요
     *
     * @param int $articleId 게시글 ID
     * @return BoardComment[]
     */
    public function getCommentsWithAuthor(int $articleId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('article_id', '=', $articleId)
            ->where('status', '=', 'published')
            ->orderBy('path', 'ASC')
            ->orderBy('created_at', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 대댓글 포함 삭제
     *
     * @param int $commentId 댓글 ID
     * @return int 삭제된 댓글 수
     */
    public function deleteWithChildren(int $commentId): int
    {
        // 해당 댓글의 path 조회
        $comment = $this->find($commentId);
        if (!$comment) {
            return 0;
        }

        // path는 게시글별로 재採番되므로(글 A와 B의 루트 댓글이 같은 path를 가질 수 있음)
        // 반드시 같은 게시글로 스코프해야 한다. 스코프가 없으면 'path LIKE' 가 다른 게시글의
        // 답글 트리까지 삭제하는 데이터 손상이 발생한다. OR도 그룹으로 묶어 우선순위를 고정한다.
        $path = $comment->getPath();

        return $this->getDb()->table($this->table)
            ->where('article_id', '=', $comment->getArticleId())
            ->whereRaw('(comment_id = ? OR path LIKE ?)', [$commentId, $path . '/%'])
            ->update(['status' => 'deleted']);
    }

    /**
     * 상태 변경
     */
    public function updateStatus(int $commentId, string $status): bool
    {
        $affected = $this->getDb()->table($this->table)
            ->where('comment_id', '=', $commentId)
            ->update(['status' => $status]);

        return $affected > 0;
    }

    /**
     * 게시글별 댓글 수 조회
     */
    public function countByArticle(int $articleId, ?string $status = 'published'): int
    {
        $query = $this->getDb()->table($this->table)
            ->where('article_id', '=', $articleId);

        if ($status !== null) {
            $query->where('status', '=', $status);
        }

        return $query->count();
    }

    /**
     * 회원의 특정 게시판 오늘 댓글 수 조회
     */
    public function countTodayByMember(int $boardId, int $memberId): int
    {
        return $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->where('member_id', '=', $memberId)
            ->where('created_at', '>=', date('Y-m-d 00:00:00'))
            ->where('status', '!=', 'deleted')
            ->count();
    }

    /**
     * 비회원의 특정 게시판 오늘 댓글 수 조회
     *
     * 비회원은 식별자가 IP 뿐이다. 공유 IP 뒤의 여러 사람이 한도를 나눠 쓰게
     * 되지만, 비회원 댓글을 연 게시판에서 한도를 아예 못 거는 것보다는 낫다.
     */
    public function countTodayByIp(int $boardId, string $ip): int
    {
        if ($ip === '') {
            return 0;
        }

        return $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->whereNull('member_id')
            ->where('ip_address', '=', $ip)
            ->where('created_at', '>=', date('Y-m-d 00:00:00'))
            ->where('status', '!=', 'deleted')
            ->count();
    }

    /**
     * 회원별 댓글 수 조회
     */
    public function countByMember(int $memberId, ?string $status = 'published'): int
    {
        $query = $this->getDb()->table($this->table)
            ->where('member_id', '=', $memberId);

        if ($status !== null) {
            $query->where('status', '=', $status);
        }

        return $query->count();
    }

    /**
     * 게시판별 댓글 수 조회
     */
    public function countByBoard(int $boardId, ?string $status = 'published'): int
    {
        $query = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId);

        if ($status !== null) {
            $query->where('status', '=', $status);
        }

        return $query->count();
    }

    /**
     * 다음 path 생성
     *
     * @param int $articleId 게시글 ID
     * @param int|null $parentId 부모 댓글 ID
     * @return string 새 path
     */
    public function generatePath(int $articleId, ?int $parentId = null): string
    {
        if ($parentId === null) {
            // 루트 댓글: 새 시퀀스 번호
            $maxPath = $this->getDb()->table($this->table)
                ->where('article_id', '=', $articleId)
                ->whereNull('parent_id')
                ->max('path');

            $nextSeq = $maxPath ? ((int) $maxPath + 1) : 1;
            return str_pad((string) $nextSeq, 10, '0', STR_PAD_LEFT);
        }

        // 대댓글: 부모 path + 시퀀스
        $parent = $this->find($parentId);
        if (!$parent) {
            return str_pad('1', 10, '0', STR_PAD_LEFT);
        }

        $parentPath = $parent->getPath();

        // 부모 아래 마지막 댓글의 path 조회
        $lastChildPath = $this->getDb()->table($this->table)
            ->where('article_id', '=', $articleId)
            ->where('parent_id', '=', $parentId)
            ->max('path');

        if ($lastChildPath) {
            // 기존 자식이 있으면 마지막 시퀀스 + 1
            $parts = explode('/', $lastChildPath);
            $lastPart = end($parts);
            $nextSeq = ((int) $lastPart) + 1;
        } else {
            $nextSeq = 1;
        }

        return $parentPath . '/' . str_pad((string) $nextSeq, 10, '0', STR_PAD_LEFT);
    }

    /**
     * depth 계산
     */
    public function calculateDepth(?int $parentId): int
    {
        if ($parentId === null) {
            return 0;
        }

        $parent = $this->find($parentId);
        return $parent ? $parent->getDepth() + 1 : 0;
    }

    /**
     * 자식 댓글 조회
     */
    public function getChildren(int $commentId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('parent_id', '=', $commentId)
            ->where('status', '=', 'published')
            ->orderBy('created_at', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 자식 댓글 존재 여부
     */
    public function hasChildren(int $commentId): bool
    {
        return $this->getDb()->table($this->table)
            ->where('parent_id', '=', $commentId)
            ->where('status', '=', 'published')
            ->exists();
    }

    /**
     * 반응수 동기화
     */
    public function syncReactionCount(int $commentId): void
    {
        $count = $this->getDb()->table('board_reactions')
            ->where('target_type', '=', 'comment')
            ->where('target_id', '=', $commentId)
            ->count();

        $this->getDb()->table($this->table)
            ->where('comment_id', '=', $commentId)
            ->update(['reaction_count' => $count]);
    }

    /**
     * 최근 댓글 조회 (Admin용)
     */
    public function getRecentComments(int $domainId, int $limit = 10): array
    {
        $rows = $this->getDb()->table($this->table . ' AS c')
            ->select([
                'c.*',
                'a.title AS article_title',
                'b.board_name',
            ])
            ->leftJoin('board_articles AS a', 'c.article_id', '=', 'a.article_id')
            ->leftJoin('board_configs AS b', 'c.board_id', '=', 'b.board_id')
            ->where('c.domain_id', '=', $domainId)
            ->where('c.status', '=', 'published')
            ->orderBy('c.created_at', 'DESC')
            ->limit($limit)
            ->get();

        return $rows;
    }

    /**
     * 블록(게시판 최신댓글)용 최신 댓글 조회
     *
     * Admin용 getRecentComments()와 달리 프론트 노출에 필요한
     * board_slug / article_slug 를 함께 반환하여 게시글 URL을 구성할 수 있게 한다.
     * 숨김/삭제된 게시글의 댓글은 노출하지 않도록 게시글 상태도 published로 제한한다.
     *
     * @param int      $domainId 도메인 ID
     * @param int      $limit    조회 개수
     * @param int|null $boardId  특정 게시판으로 제한 (null이면 도메인 전체 게시판)
     * @param bool     $global   전역 게시판이면 도메인 필터 생략 (board 블록과 동일 규칙)
     * @return array[] 원본 행 배열 (CommentPresenter로 표시용 변환)
     */
    public function getRecentForBlock(int $domainId, int $limit = 10, ?int $boardId = null, bool $global = false): array
    {
        $query = $this->getDb()->table($this->table . ' AS c')
            ->select([
                'c.comment_id', 'c.article_id', 'c.board_id', 'c.member_id',
                'c.author_name', 'c.content', 'c.is_secret', 'c.created_at',
                'a.title AS article_title', 'a.slug AS article_slug',
                'b.board_name', 'b.board_slug',
            ])
            ->leftJoin('board_articles AS a', 'c.article_id', '=', 'a.article_id')
            ->leftJoin('board_configs AS b', 'c.board_id', '=', 'b.board_id');

        // 특정 게시판 지정 시 board_id로 제한. 전역 게시판이 아니면 도메인 스코프 유지.
        if ($boardId !== null) {
            $query->where('c.board_id', '=', $boardId);
            if (!$global) {
                $query->where('c.domain_id', '=', $domainId);
            }
        } else {
            $query->where('c.domain_id', '=', $domainId);
        }

        return $query
            ->where('c.status', '=', 'published')
            ->where('a.status', '=', 'published')
            ->orderBy('c.created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * 회원이 작성한 댓글 목록 (마이페이지용)
     *
     * @return array{items: array[], pagination: array}
     */
    public function getByMember(int $memberId, int $domainId, int $page = 1, int $perPage = 15): array
    {
        $query = $this->getDb()->table($this->table . ' AS c')
            ->select([
                'c.comment_id', 'c.article_id', 'c.board_id', 'c.content', 'c.created_at',
                'a.title AS article_title', 'a.slug AS article_slug',
                'b.board_name', 'b.board_slug',
            ])
            ->leftJoin('board_articles AS a', 'c.article_id', '=', 'a.article_id')
            ->leftJoin('board_configs AS b', 'c.board_id', '=', 'b.board_id')
            ->where('c.member_id', '=', $memberId)
            ->where('c.domain_id', '=', $domainId)
            ->where('c.status', '=', 'published');

        $total  = $query->count();
        $offset = ($page - 1) * $perPage;
        $rows   = $query->orderBy('c.created_at', 'DESC')->limit($perPage)->offset($offset)->get();

        return [
            'items' => $rows,
            'pagination' => [
                'totalItems'  => $total,
                'perPage'     => $perPage,
                'currentPage' => $page,
                'totalPages'  => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * 회원의 게시판별 작성 댓글 수 (마이페이지 커뮤니티 활동 대시보드용)
     *
     * @return array<int, array{board_id:int, board_name:string, board_slug:string, cnt:int}>
     */
    public function getMemberCountsByBoard(int $memberId, int $domainId): array
    {
        return $this->getDb()->table($this->table . ' AS c')
            ->select(['c.board_id', 'b.board_name', 'b.board_slug', 'COUNT(*) AS cnt'])
            ->leftJoin('board_configs AS b', 'c.board_id', '=', 'b.board_id')
            ->where('c.member_id', '=', $memberId)
            ->where('c.domain_id', '=', $domainId)
            ->where('c.status', '=', 'published')
            ->groupBy('c.board_id', 'b.board_name', 'b.board_slug')
            ->get();
    }

    /**
     * 회원의 일별 작성 댓글 수 (최근 활동 추이용). since 이후 데이터만, 날짜→건수 맵.
     *
     * @return array<string,int> ['Y-m-d' => cnt]
     */
    public function getMemberDailyCounts(int $memberId, int $domainId, string $since): array
    {
        // 빌더 select 검증기가 DATE() 를 허용하지 않아 raw SQL 을 쓴다.
        $table = $this->table;
        $rows = $this->getDb()->select(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c
             FROM {$table}
             WHERE member_id = ? AND domain_id = ? AND status = 'published' AND created_at >= ?
             GROUP BY DATE(created_at)",
            [$memberId, $domainId, $since]
        );

        $map = [];
        foreach ($rows as $r) { $map[(string) $r['d']] = (int) $r['c']; }
        return $map;
    }

    // ── 관리자 대시보드 집계 (도메인 단위) ──

    /**
     * 도메인 전체 댓글 수 (published). $since 지정 시 created_at >= $since.
     */
    public function countByDomain(int $domainId, ?string $since = null): int
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('status', '=', 'published');

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        return $query->count();
    }

    /**
     * 도메인 일별 댓글 수 (추이용). $since 이후, 날짜→건수 맵.
     *
     * @return array<string,int> ['Y-m-d' => cnt]
     */
    public function getDailyCountsByDomain(int $domainId, string $since): array
    {
        $table = $this->table;
        $rows = $this->getDb()->select(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c
             FROM {$table}
             WHERE domain_id = ? AND status = 'published' AND created_at >= ?
             GROUP BY DATE(created_at)",
            [$domainId, $since]
        );

        $map = [];
        foreach ($rows as $r) { $map[(string) $r['d']] = (int) $r['c']; }
        return $map;
    }
}
