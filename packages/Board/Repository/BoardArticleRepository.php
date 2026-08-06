<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Repository;

use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\BaseRepository;

/**
 * BoardArticle Repository
 *
 * 게시글 데이터베이스 접근 담당
 *
 * 책임:
 * - board_articles 테이블 CRUD
 * - BoardArticle Entity 반환
 * - 복합 쿼리 (필터, 페이지네이션)
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class BoardArticleRepository extends BaseRepository
{
    protected string $table = 'board_articles';
    protected string $entityClass = BoardArticle::class;
    protected string $primaryKey = 'article_id';

    /**
     * LIKE 검색용 와일드카드 이스케이프
     *
     * 백슬래시를 '먼저' 이스케이프해야 한다. %/_ 를 먼저 처리하면 그때 붙인 백슬래시가
     * 뒤 단계에서 재이중화되어(예: '50%' → '50\\%') 사용자 와일드카드가 살아남는다(LIKE 와일드카드 인젝션).
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    /**
     * 게시판별 게시글 목록 조회 (페이지네이션)
     *
     * @param int $domainId 도메인 ID
     * @param int $boardId 게시판 ID
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @param array $filters 필터 조건
     * @return array ['items' => BoardArticle[], 'pagination' => [...]]
     */
    public function getPaginatedList(
        int $domainId,
        int $boardId,
        int $page = 1,
        int $perPage = 20,
        array $filters = [],
        bool $isGlobal = false
    ): array {
        $query = $this->getDb()->table($this->table . ' AS a')
            ->where('a.board_id', '=', $boardId);

        // 전역 게시판이 아닌 경우에만 도메인 필터를 적용
        if (!$isGlobal) {
            $query->where('a.domain_id', '=', $domainId);
        }

        // 상태 필터 (기본: published)
        $status = $filters['status'] ?? 'published';
        if ($status !== 'all') {
            $query->where('a.status', '=', $status);
        }

        // 카테고리 필터
        if (!empty($filters['category_id'])) {
            $query->where('a.category_id', '=', (int) $filters['category_id']);
        }

        // 공지사항 필터
        if (isset($filters['is_notice'])) {
            $query->where('a.is_notice', '=', (int) $filters['is_notice']);
        }

        // 검색
        if (!empty($filters['keyword']) && !empty($filters['search_field'])) {
            $keyword = '%' . $this->escapeLike($filters['keyword']) . '%';
            $field = $filters['search_field'];

            if ($field === 'title') {
                $query->where('a.title', 'LIKE', $keyword);
            } elseif ($field === 'content') {
                $query->where('a.content', 'LIKE', $keyword);
            } elseif ($field === 'title_content') {
                $query->whereRaw('(a.title LIKE ? OR a.content LIKE ?)', [$keyword, $keyword]);
            }
        }

        // 회원 필터
        if (!empty($filters['member_id'])) {
            $query->where('a.member_id', '=', (int) $filters['member_id']);
        }

        // 기본 일반글 목록은 MariaDB가 단일 인덱스들을 index_merge하는 잘못된 계획을
        // 선택하기 쉽다. 이 조건 조합에 맞춘 커버링 인덱스를 명시해 COUNT와 목록 모두
        // 같은 범위 인덱스를 사용하게 한다. 추가 필터가 있으면 옵티마이저 선택을 존중한다.
        $canUseDomainListIndex = !$isGlobal
            && $status !== 'all'
            && isset($filters['is_notice'])
            && empty($filters['category_id'])
            && empty($filters['keyword'])
            && empty($filters['member_id']);

        if ($canUseDomainListIndex) {
            $query->forceIndex('idx_domain_board_list');
        }

        // 전체 개수
        $total = $query->count();

        // 정렬 및 페이지네이션
        $offset = ($page - 1) * $perPage;
        // 공지 여부가 필터로 고정된 목록에서 is_notice를 다시 정렬하면 MariaDB가
        // idx_*_board_list를 사용하지 않고 전체 후보를 filesort할 수 있다.
        // 공지/일반글을 함께 조회할 때만 기존의 공지 우선 정렬을 유지한다.
        if (!isset($filters['is_notice'])) {
            $query->orderBy('a.is_notice', 'DESC');
        }

        $rows = $query
            ->orderBy('a.created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        // 목록용 관계 데이터(첨부/링크/카테고리)를 배치로 적재 — 글마다 조회하는 N+1을 피한다.
        $articleIds = array_map(static fn($row) => (int) $row['article_id'], $rows);
        $categoryIds = array_map(static fn($row) => (int) ($row['category_id'] ?? 0), $rows);

        return [
            'items' => $this->toEntities($rows),
            'pagination' => [
                'totalItems' => $total,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => (int) ceil($total / $perPage),
            ],
            'relations' => $this->loadRelations($articleIds, $categoryIds),
        ];
    }

    /**
     * 게시판 블록용 최신글 목록 조회.
     *
     * 블록은 전체 건수와 페이지 수를 사용하지 않으므로 COUNT 쿼리를 실행하지 않는다.
     * 목록 표현에 필요한 관계 데이터는 일반 목록과 동일하게 반환해 기존 스킨 계약을 유지한다.
     *
     * @param array<string, mixed> $filters
     * @return array{items: BoardArticle[], relations: array{attachments: array, links: array, categories: array}}
     */
    public function getRecentList(
        int $domainId,
        int $boardId,
        int $limit = 20,
        array $filters = [],
        bool $isGlobal = false
    ): array {
        $query = $this->getDb()->table($this->table . ' AS a')
            ->where('a.board_id', '=', $boardId);

        if (!$isGlobal) {
            $query->where('a.domain_id', '=', $domainId);
        }

        $status = $filters['status'] ?? 'published';
        if ($status !== 'all') {
            $query->where('a.status', '=', $status);
        }

        if (!empty($filters['category_id'])) {
            $query->where('a.category_id', '=', (int) $filters['category_id']);
        }

        if (isset($filters['is_notice'])) {
            $query->where('a.is_notice', '=', (int) $filters['is_notice']);
        }

        $rows = $query
            ->orderBy('a.is_notice', 'DESC')
            ->orderBy('a.created_at', 'DESC')
            ->limit($limit)
            ->get();

        $articleIds = array_map(static fn($row) => (int) $row['article_id'], $rows);
        $categoryIds = array_map(static fn($row) => (int) ($row['category_id'] ?? 0), $rows);

        return [
            'items' => $this->toEntities($rows),
            'relations' => $this->loadRelations($articleIds, $categoryIds),
        ];
    }

    /**
     * 목록용 관계 데이터(첨부/링크/카테고리) 배치 적재
     *
     * 목록을 그리는 여러 진입점(목록·공지·블록)이 공통으로 사용한다.
     * 결과는 BoardListAssembler::assemble()에 그대로 넘길 수 있다.
     *
     * @param int[] $articleIds  게시글 ID 목록
     * @param int[] $categoryIds 카테고리 ID 목록(0/중복 무방, 내부에서 정리)
     * @return array{attachments: array, links: array, categories: array}
     */
    public function loadRelations(array $articleIds, array $categoryIds): array
    {
        $categoryIds = array_values(array_unique(array_filter($categoryIds)));

        return [
            'attachments' => $this->getAttachmentsMap($articleIds),
            'links' => $this->getLinksMap($articleIds),
            'categories' => $this->getCategoryMap($categoryIds),
        ];
    }

    /**
     * 카테고리 ID → 이름/슬러그 맵 조회 (목록 표시용)
     *
     * @param int[] $categoryIds 게시글에 사용된 카테고리 ID 목록
     * @return array<int, array{name: string, slug: ?string}>
     */
    private function getCategoryMap(array $categoryIds): array
    {
        if (empty($categoryIds)) {
            return [];
        }

        $rows = $this->getDb()->table('board_categories')
            ->whereIn('category_id', $categoryIds)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['category_id']] = [
                'name' => $row['category_name'],
                'slug' => $row['category_slug'],
            ];
        }

        return $map;
    }

    /**
     * 게시글별 첨부파일 맵 조회 (목록 리치 데이터)
     *
     * 스킨이 첨부 개수·종류·이미지·대표 썸네일 등을 자유롭게 출력할 수 있도록
     * 원본 컬럼에 충실한 형태로 반환한다. 배치 단일 쿼리로 N+1을 피한다.
     *
     * @param int[] $articleIds 게시글 ID 목록
     * @return array<int, array<int, array<string, mixed>>> [article_id => [첨부, ...]]
     */
    private function getAttachmentsMap(array $articleIds): array
    {
        if (empty($articleIds)) {
            return [];
        }

        $rows = $this->getDb()->table('board_attachments')
            ->whereIn('article_id', $articleIds)
            ->orderBy('article_id', 'ASC')
            ->orderBy('attachment_id', 'ASC')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $aid = (int) $row['article_id'];
            $isImage = (bool) $row['is_image'];
            $thumb = $row['thumbnail_path'] ?? null;

            // 이미지는 비민감 표시 정보로 공개 직링크 사용.
            // 그 외 파일은 저장 해시(stored_name)/내부 경로를 노출하지 않고,
            // canDownload·포인트 게이트가 걸린 다운로드 엔드포인트로만 접근.
            $url = $isImage
                ? '/storage/' . $row['file_path'] . '/' . $row['stored_name']
                : '/board/' . (int) $row['board_id'] . '/file/download/' . (string) $row['public_id'];

            $map[$aid][] = [
                'attachment_id' => (int) $row['attachment_id'],
                'public_id'     => (string) $row['public_id'],
                'name'          => $row['original_name'],
                'ext'           => $row['file_extension'],
                'size'          => (int) $row['file_size'],
                'mime'          => $row['mime_type'],
                'is_image'      => $isImage,
                'url'           => $url,
                'thumb_url'     => ($isImage && $thumb) ? '/storage/' . $thumb : null,
            ];
        }

        return $map;
    }

    /**
     * 게시글별 링크 맵 조회 (목록 리치 데이터)
     *
     * @param int[] $articleIds 게시글 ID 목록
     * @return array<int, array<int, array<string, mixed>>> [article_id => [링크, ...]]
     */
    private function getLinksMap(array $articleIds): array
    {
        if (empty($articleIds)) {
            return [];
        }

        $rows = $this->getDb()->table('board_links')
            ->whereIn('article_id', $articleIds)
            ->orderBy('article_id', 'ASC')
            ->orderBy('created_at', 'ASC')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $aid = (int) $row['article_id'];
            $map[$aid][] = [
                'link_id'    => (int) $row['link_id'],
                'url'        => $row['link_url'],
                'title'      => $row['link_title'],
                'image'      => $row['link_image'],
                'click_count' => (int) $row['click_count'],
            ];
        }

        return $map;
    }

    /**
     * 공지사항 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $boardId 게시판 ID
     * @param int $limit 조회 개수
     * @return BoardArticle[]
     */
    public function getNotices(int $domainId, int $boardId, int $limit = 10, bool $isGlobal = false): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->where('is_notice', '=', 1)
            ->where('status', '=', 'published');

        if (!$isGlobal) {
            $query->where('domain_id', '=', $domainId);
        }

        $rows = $query
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 이전/다음 글 조회
     *
     * @param int $articleId 현재 게시글 ID
     * @param int $boardId 게시판 ID
     * @return array ['prev' => ?BoardArticle, 'next' => ?BoardArticle]
     */
    public function getAdjacentArticles(int $articleId, int $boardId): array
    {
        // 이전 글 (더 작은 ID 중 가장 큰 것)
        $prevRow = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->where('article_id', '<', $articleId)
            ->where('status', '=', 'published')
            ->where('is_notice', '=', 0)
            ->orderBy('article_id', 'DESC')
            ->first();

        // 다음 글 (더 큰 ID 중 가장 작은 것)
        $nextRow = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->where('article_id', '>', $articleId)
            ->where('status', '=', 'published')
            ->where('is_notice', '=', 0)
            ->orderBy('article_id', 'ASC')
            ->first();

        return [
            'prev' => $prevRow ? BoardArticle::fromArray($prevRow) : null,
            'next' => $nextRow ? BoardArticle::fromArray($nextRow) : null,
        ];
    }

    /**
     * 조회수 증가
     */
    public function incrementViewCount(int $articleId): void
    {
        $table = $this->table;
        $this->getDb()->execute(
            "UPDATE {$table} SET view_count = view_count + 1 WHERE article_id = ?",
            [$articleId]
        );
    }

    /**
     * 댓글수 동기화
     */
    public function syncCommentCount(int $articleId): void
    {
        $count = $this->getDb()->table('board_comments')
            ->where('article_id', '=', $articleId)
            ->where('status', '=', 'published')
            ->count();

        $this->getDb()->table($this->table)
            ->where('article_id', '=', $articleId)
            ->update(['comment_count' => $count]);
    }

    /**
     * 반응수 동기화
     */
    public function syncReactionCount(int $articleId): void
    {
        $count = $this->getDb()->table('board_reactions')
            ->where('target_type', '=', 'article')
            ->where('target_id', '=', $articleId)
            ->count();

        $this->getDb()->table($this->table)
            ->where('article_id', '=', $articleId)
            ->update(['reaction_count' => $count]);
    }

    /**
     * 게시판별 게시글 수 조회
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
     * 회원의 특정 게시판 오늘 글 수 조회
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
     * 비회원의 특정 게시판 오늘 글 수 조회
     *
     * 비회원은 식별자가 IP 뿐이다. 공유 IP 뒤의 여러 사람이 한도를 나눠 쓰게
     * 되지만, 비회원 글쓰기를 연 게시판에서 한도를 아예 못 거는 것보다는 낫다.
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
     * 회원별 게시글 수 조회
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
     * 슬러그로 게시글 조회
     */
    public function findBySlug(int $boardId, string $slug): ?BoardArticle
    {
        return $this->findOneBy([
            'board_id' => $boardId,
            'slug' => $slug,
        ]);
    }

    /**
     * 슬러그 중복 검사
     */
    public function existsBySlug(int $boardId, string $slug): bool
    {
        return $this->existsBy([
            'board_id' => $boardId,
            'slug' => $slug,
        ]);
    }

    /**
     * 슬러그 중복 검사 (자기 자신 제외)
     */
    public function existsBySlugExceptSelf(int $boardId, string $slug, int $articleId): bool
    {
        return $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->where('slug', '=', $slug)
            ->where('article_id', '!=', $articleId)
            ->exists();
    }

    /**
     * 상태 변경
     */
    public function updateStatus(int $articleId, string $status): bool
    {
        $affected = $this->getDb()->table($this->table)
            ->where('article_id', '=', $articleId)
            ->update(['status' => $status]);

        return $affected > 0;
    }

    /**
     * 특정 도메인에 속하는 게시글 수 조회 (ID 목록 기준)
     *
     * @param int $domainId 도메인 ID
     * @param array $articleIds 게시글 ID 배열
     * @return int 해당 도메인에 속하는 게시글 수
     */
    public function countByDomainAndIds(int $domainId, array $articleIds): int
    {
        if (empty($articleIds)) {
            return 0;
        }

        return (int) $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->whereIn('article_id', $articleIds)
            ->count();
    }

    /**
     * 현재 도메인이 관리할 수 있는 게시글 수.
     * 일반 게시판은 글 소유 도메인만, 전역 게시판은 모든 도메인에서 접근 가능하다.
     */
    public function countAccessibleByDomainAndIds(int $domainId, array $articleIds): int
    {
        if (empty($articleIds)) {
            return 0;
        }

        return (int) $this->getDb()->table($this->table . ' AS a')
            ->leftJoin('board_configs AS b', 'a.board_id', '=', 'b.board_id')
            ->whereRaw('(a.domain_id = ? OR b.is_global = 1)', [$domainId])
            ->whereIn('a.article_id', $articleIds)
            ->count();
    }

    /**
     * 일괄 상태 변경
     */
    public function bulkUpdateStatus(array $articleIds, string $status): int
    {
        if (empty($articleIds)) {
            return 0;
        }

        return $this->getDb()->table($this->table)
            ->whereIn('article_id', $articleIds)
            ->update(['status' => $status]);
    }

    /**
     * 도메인별 전체 게시글 조회 (통합 피드용)
     *
     * @param int $domainId 도메인 ID
     * @param int $page 페이지
     * @param int $perPage 페이지당 개수
     * @param array $filters 필터
     * @return array
     */
    public function getAllByDomain(
        int $domainId,
        int $page = 1,
        int $perPage = 20,
        array $filters = []
    ): array {
        $query = $this->getDb()->table($this->table . ' AS a')
            ->select([
                'a.*',
                'b.board_name',
                'b.board_slug',
                'g.group_name',
                'g.group_slug',
            ])
            ->leftJoin('board_configs AS b', 'a.board_id', '=', 'b.board_id')
            ->leftJoin('board_groups AS g', 'b.group_id', '=', 'g.group_id')
            ->whereRaw('(a.domain_id = ? OR b.is_global = 1)', [$domainId])
            ->where('a.status', '=', 'published')
            ->where('b.is_active', '=', 1)
            ->where('a.is_secret', '=', 0); // 커뮤니티 피드에 비밀글 노출 방지

        // 그룹 필터
        if (!empty($filters['group_id'])) {
            $query->where('b.group_id', '=', (int) $filters['group_id']);
        }

        // 게시판 필터 (단일)
        if (!empty($filters['board_id'])) {
            $query->where('a.board_id', '=', (int) $filters['board_id']);
        }

        // 게시판 필터 (복수 - 권한 기반)
        if (!empty($filters['board_ids'])) {
            $query->whereIn('a.board_id', $filters['board_ids']);
        }

        // 검색
        if (!empty($filters['keyword']) && !empty($filters['search_field'])) {
            $keyword = '%' . $this->escapeLike($filters['keyword']) . '%';
            $field = $filters['search_field'];

            if ($field === 'title') {
                $query->where('a.title', 'LIKE', $keyword);
            } elseif ($field === 'content') {
                $query->where('a.content', 'LIKE', $keyword);
            } elseif ($field === 'title_content') {
                $query->whereRaw('(a.title LIKE ? OR a.content LIKE ?)', [$keyword, $keyword]);
            }
        }

        // 전체 개수
        $total = $query->count();

        // 정렬 (허용된 컬럼만)
        $allowedOrderBy = ['created_at', 'view_count', 'reaction_count', 'comment_count', 'title'];
        $orderBy = in_array($filters['order_by'] ?? 'created_at', $allowedOrderBy, true)
            ? ($filters['order_by'] ?? 'created_at')
            : 'created_at';
        $orderDir = $filters['order_dir'] ?? 'DESC';
        $query->orderBy('a.' . $orderBy, $orderDir);

        // 페이지네이션
        $offset = ($page - 1) * $perPage;
        $rows = $query->limit($perPage)->offset($offset)->get();

        // 결과에 게시판/그룹 정보 포함 (작성자는 author_name 사용)
        $items = [];
        foreach ($rows as $row) {
            $article = BoardArticle::fromArray($row);
            $items[] = [
                'article' => $article,
                'board_name' => $row['board_name'] ?? '',
                'board_slug' => $row['board_slug'] ?? '',
                'group_name' => $row['group_name'] ?? '',
                'group_slug' => $row['group_slug'] ?? '',
            ];
        }

        return [
            'items' => $items,
            'pagination' => [
                'totalItems' => $total,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * 인기글 조회 (조회수/반응수 기준)
     *
     * @param int $domainId 도메인 ID
     * @param int $limit 조회 개수
     * @param string $orderBy 정렬 기준 (view_count, reaction_count)
     * @param int $days 최근 N일
     * @return BoardArticle[]
     */
    public function getPopular(
        int $domainId,
        int $limit = 10,
        string $orderBy = 'view_count',
        int $days = 7
    ): array {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $allowedOrderBy = ['view_count', 'reaction_count', 'comment_count', 'created_at'];
        $safeOrderBy = in_array($orderBy, $allowedOrderBy, true) ? $orderBy : 'view_count';

        $rows = $this->getDb()->table($this->table . ' AS a')
            ->select(['a.*'])
            ->leftJoin('board_configs AS b', 'a.board_id', '=', 'b.board_id')
            ->whereRaw('(a.domain_id = ? OR b.is_global = 1)', [$domainId])
            ->where('a.status', '=', 'published')
            ->where('a.is_secret', '=', 0) // 인기글에 비밀글 노출 방지
            ->where('a.created_at', '>=', $since)
            ->orderBy('a.' . $safeOrderBy, 'DESC')
            ->limit($limit)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 인기글 페이지네이션 조회 (통합 피드용)
     *
     * @param int $domainId 도메인 ID
     * @param int $page 페이지
     * @param int $perPage 페이지당 개수
     * @param array $filters 필터 (days, board_ids, group_id, keyword, search_field)
     * @return array ['items' => [...], 'pagination' => [...]]
     */
    public function getPopularPaginated(
        int $domainId,
        int $page = 1,
        int $perPage = 20,
        array $filters = []
    ): array {
        $days = $filters['days'] ?? 7;
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $query = $this->getDb()->table($this->table . ' AS a')
            ->select([
                'a.*',
                'b.board_name',
                'b.board_slug',
                'g.group_name',
                'g.group_slug',
            ])
            ->leftJoin('board_configs AS b', 'a.board_id', '=', 'b.board_id')
            ->leftJoin('board_groups AS g', 'b.group_id', '=', 'g.group_id')
            ->whereRaw('(a.domain_id = ? OR b.is_global = 1)', [$domainId])
            ->where('a.status', '=', 'published')
            ->where('b.is_active', '=', 1)
            ->where('a.is_secret', '=', 0) // 인기 피드에 비밀글 노출 방지
            ->where('a.created_at', '>=', $since);

        // 게시판 필터 (권한 기반)
        if (!empty($filters['board_ids'])) {
            $query->whereIn('a.board_id', $filters['board_ids']);
        }

        // 그룹 필터
        if (!empty($filters['group_id'])) {
            $query->where('b.group_id', '=', (int) $filters['group_id']);
        }

        // 검색
        if (!empty($filters['keyword']) && !empty($filters['search_field'])) {
            $keyword = '%' . $this->escapeLike($filters['keyword']) . '%';
            $field = $filters['search_field'];

            if ($field === 'title') {
                $query->where('a.title', 'LIKE', $keyword);
            } elseif ($field === 'content') {
                $query->where('a.content', 'LIKE', $keyword);
            } elseif ($field === 'title_content') {
                $query->whereRaw('(a.title LIKE ? OR a.content LIKE ?)', [$keyword, $keyword]);
            }
        }

        $total = $query->count();

        // 인기순 정렬
        $offset = ($page - 1) * $perPage;
        $rows = $query
            ->orderBy('a.view_count', 'DESC')
            ->orderBy('a.reaction_count', 'DESC')
            ->orderBy('a.created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $article = BoardArticle::fromArray($row);
            $items[] = [
                'article' => $article,
                'board_name' => $row['board_name'] ?? '',
                'board_slug' => $row['board_slug'] ?? '',
                'group_name' => $row['group_name'] ?? '',
                'group_slug' => $row['group_slug'] ?? '',
            ];
        }

        return [
            'items' => $items,
            'pagination' => [
                'totalItems' => $total,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
            ],
        ];
    }

    /**
     * 게시글 상세 조회
     *
     * author_name이 board_articles에 저장되므로 members JOIN 불필요
     */
    public function findWithAuthor(int $articleId): ?array
    {
        $article = $this->find($articleId);

        if (!$article) {
            return null;
        }

        return [
            'article' => $article,
        ];
    }

    /**
     * Admin용 전체 게시글 목록 조회
     */
    public function getAdminList(
        int $domainId,
        int $page = 1,
        int $perPage = 20,
        array $filters = []
    ): array {
        $query = $this->getDb()->table($this->table . ' AS a')
            ->select([
                'a.*',
                'b.board_name',
                'b.board_slug',
                // 관리자 목록이 "이 글의 개별 레벨이 게시판 정책보다 낮다"를 표시하려면
                // 둘을 나란히 놓고 비교해야 한다. 글 값만으로는 어긋났는지 알 수 없다.
                'b.read_level AS board_read_level',
                'b.download_level AS board_download_level',
                'm.user_id AS author_userid',
            ])
            ->leftJoin('board_configs AS b', 'a.board_id', '=', 'b.board_id')
            ->leftJoin('members AS m', 'a.member_id', '=', 'm.member_id')
            ->whereRaw('(a.domain_id = ? OR b.is_global = 1)', [$domainId]);

        // 게시판 필터
        if (!empty($filters['board_id'])) {
            $query->where('a.board_id', '=', (int) $filters['board_id']);
        }

        // 상태 필터
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('a.status', '=', $filters['status']);
        }

        // 검색
        if (!empty($filters['keyword']) && !empty($filters['search_field'])) {
            $keyword = '%' . $this->escapeLike($filters['keyword']) . '%';
            $field = $filters['search_field'];

            if ($field === 'title') {
                $query->where('a.title', 'LIKE', $keyword);
            } elseif ($field === 'content') {
                $query->where('a.content', 'LIKE', $keyword);
            } elseif ($field === 'author') {
                $query->where('a.author_name', 'LIKE', $keyword);
            }
        }

        // 전체 개수
        $total = $query->count();

        // 정렬 및 페이지네이션
        $offset = ($page - 1) * $perPage;
        $rows = $query
            ->orderBy('a.created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'items' => $rows,
            'pagination' => [
                'totalItems' => $total,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * 전체 검색용 게시글 조회 (제목+내용 LIKE, 공개 게시글만)
     *
     * @param int    $domainId 도메인 ID
     * @param string $keyword  검색 키워드
     * @param int    $limit    최대 결과 수
     * @return array [{title, url, summary, thumbnail, date, meta(board_name)}]
     */
    public function searchByKeyword(int $domainId, string $keyword, int $limit = 5): array
    {
        $kw = '%' . $this->escapeLike($keyword) . '%';

        $rows = $this->getDb()->table($this->table . ' AS a')
            ->select(['a.article_id', 'a.title', 'a.content', 'a.thumbnail', 'a.created_at',
                      'b.board_slug', 'b.board_name'])
            ->leftJoin('board_configs AS b', 'a.board_id', '=', 'b.board_id')
            ->whereRaw('(a.domain_id = ? OR b.is_global = 1)', [$domainId])
            ->where('a.status', '=', 'published')
            ->where('b.is_active', '=', 1)
            ->where('a.is_secret', '=', 0)
            ->whereRaw('(a.title LIKE ? OR a.content LIKE ?)', [$kw, $kw])
            ->orderBy('a.created_at', 'DESC')
            ->limit($limit)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $summary = html_entity_decode(strip_tags($row['content'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $summary = trim(preg_replace('/\s+/u', ' ', $summary));
            $summary = mb_strlen($summary) > 100 ? mb_substr($summary, 0, 100) . '...' : $summary;
            $items[] = [
                'title'     => $row['title'] ?? '',
                'url'       => '/board/' . ($row['board_slug'] ?? '') . '/view/' . $row['article_id'],
                'summary'   => $summary,
                'thumbnail' => $row['thumbnail'] ?? null,
                'date'      => isset($row['created_at']) ? substr($row['created_at'], 0, 10) : null,
                'meta'      => $row['board_name'] ?? '',
            ];
        }

        return $items;
    }

    /**
     * 전체 검색용 게시글 개수 (searchByKeyword와 동일 조건)
     */
    public function countByKeyword(int $domainId, string $keyword): int
    {
        $kw = '%' . $this->escapeLike($keyword) . '%';

        return $this->getDb()->table($this->table . ' AS a')
            ->leftJoin('board_configs AS b', 'a.board_id', '=', 'b.board_id')
            ->whereRaw('(a.domain_id = ? OR b.is_global = 1)', [$domainId])
            ->where('a.status', '=', 'published')
            ->where('b.is_active', '=', 1)
            ->where('a.is_secret', '=', 0)
            ->whereRaw('(a.title LIKE ? OR a.content LIKE ?)', [$kw, $kw])
            ->count();
    }

    /**
     * 회원이 작성한 게시글 목록 (마이페이지용)
     *
     * @return array{items: array[], pagination: array}
     */
    public function getByMember(int $memberId, int $domainId, int $page = 1, int $perPage = 15): array
    {
        $query = $this->getDb()->table($this->table . ' AS a')
            ->select([
                'a.article_id', 'a.board_id', 'a.title', 'a.status',
                'a.view_count', 'a.comment_count', 'a.created_at', 'a.thumbnail',
                'b.board_name', 'b.board_slug',
            ])
            ->leftJoin('board_configs AS b', 'a.board_id', '=', 'b.board_id')
            ->where('a.member_id', '=', $memberId)
            ->where('a.domain_id', '=', $domainId)
            ->where('a.status', '=', 'published');

        $total  = $query->count();
        $offset = ($page - 1) * $perPage;
        $rows   = $query->orderBy('a.created_at', 'DESC')->limit($perPage)->offset($offset)->get();

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
     * 회원의 게시판별 작성 글 수 (마이페이지 커뮤니티 활동 대시보드용)
     *
     * @return array<int, array{board_id:int, board_name:string, board_slug:string, cnt:int}>
     */
    public function getMemberCountsByBoard(int $memberId, int $domainId): array
    {
        return $this->getDb()->table($this->table . ' AS a')
            ->select(['a.board_id', 'b.board_name', 'b.board_slug', 'COUNT(*) AS cnt'])
            ->leftJoin('board_configs AS b', 'a.board_id', '=', 'b.board_id')
            ->where('a.member_id', '=', $memberId)
            ->where('a.domain_id', '=', $domainId)
            ->where('a.status', '=', 'published')
            ->groupBy('a.board_id', 'b.board_name', 'b.board_slug')
            ->get();
    }

    /**
     * 회원의 일별 작성 글 수 (최근 활동 추이용). since 이후 데이터만, 날짜→건수 맵.
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
     * 도메인 전체 게시글 수 (published). $since 지정 시 created_at >= $since.
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
     * 도메인 최근 게시글 목록 (게시판명 포함)
     */
    public function getRecentByDomain(int $domainId, int $limit = 8): array
    {
        return $this->getDb()->table($this->table . ' AS a')
            ->select([
                'a.article_id', 'a.board_id', 'a.title', 'a.author_name',
                'a.comment_count', 'a.view_count', 'a.created_at',
                'b.board_name', 'b.board_slug',
            ])
            ->leftJoin('board_configs AS b', 'a.board_id', '=', 'b.board_id')
            ->where('a.domain_id', '=', $domainId)
            ->where('a.status', '=', 'published')
            ->orderBy('a.created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * 도메인 일별 작성 글 수 (추이용). $since 이후, 날짜→건수 맵.
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

    /**
     * 게시판별 게시글 수 상위 목록
     *
     * @return array<int,array{board_id:int,board_name:string,board_slug:string,cnt:int}>
     */
    public function getTopBoardsByArticleCount(int $domainId, int $limit = 5): array
    {
        return $this->getDb()->table($this->table . ' AS a')
            ->select(['a.board_id', 'b.board_name', 'b.board_slug', 'COUNT(*) AS cnt'])
            ->leftJoin('board_configs AS b', 'a.board_id', '=', 'b.board_id')
            ->where('a.domain_id', '=', $domainId)
            ->where('a.status', '=', 'published')
            ->groupBy('a.board_id', 'b.board_name', 'b.board_slug')
            ->orderByRaw('cnt DESC')
            ->limit($limit)
            ->get();
    }
}
