<?php
namespace Mublo\Packages\Board\Repository;

use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * BoardConfig Repository
 *
 * 게시판 설정 데이터베이스 접근 담당
 *
 * 책임:
 * - board_configs 테이블 CRUD
 * - BoardConfig Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class BoardConfigRepository extends BaseRepository
{
    protected string $table = 'board_configs';
    protected string $entityClass = BoardConfig::class;
    protected string $primaryKey = 'board_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 도메인별 게시판 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $limit 조회 개수
     * @param int $offset 시작 위치
     * @return BoardConfig[]
     */
    public function findByDomain(int $domainId, int $limit = 100, int $offset = 0): array
    {
        $rows = $this->getDb()->table($this->table)
            ->whereRaw('(domain_id = ? OR is_global = 1)', [$domainId])
            ->orderBy('sort_order', 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 그룹별 게시판 목록 조회
     *
     * @param int $groupId 그룹 ID
     * @return BoardConfig[]
     */
    public function findByGroup(int $groupId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('group_id', '=', $groupId)
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 도메인+슬러그로 게시판 조회
     */
    public function findBySlug(int $domainId, string $slug): ?BoardConfig
    {
        $row = $this->getDb()->table($this->table)
            ->whereRaw('(domain_id = ? OR is_global = 1)', [$domainId])
            ->where('board_slug', '=', $slug)
            ->orderBy('is_global', 'ASC')
            ->first();

        return $row ? BoardConfig::fromArray($row) : null;
    }

    /**
     * 블록/프론트에서 접근 가능한 게시판을 ID로 조회한다.
     *
     * 게시판 패키지의 명시적 예외 정책은 현재 도메인 소유 또는 전역(is_global=1)이다.
     */
    public function findAccessibleById(int $domainId, int $boardId): ?BoardConfig
    {
        $row = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->whereRaw('(domain_id = ? OR is_global = 1)', [$domainId])
            ->first();

        return $row ? BoardConfig::fromArray($row) : null;
    }

    /**
     * 슬러그 중복 검사
     *
     * 현재 도메인의 슬러그 + 전역(is_global) 슬러그 충돌도 함께 차단한다.
     */
    public function existsBySlug(int $domainId, string $slug): bool
    {
        return $this->getDb()->table($this->table)
            ->whereRaw('(domain_id = ? OR is_global = 1)', [$domainId])
            ->where('board_slug', '=', $slug)
            ->exists();
    }

    /**
     * 슬러그 중복 검사 (자기 자신 제외)
     */
    public function existsBySlugExceptSelf(int $domainId, string $slug, int $boardId): bool
    {
        $query = $this->getDb()->table($this->table)
            ->whereRaw('(domain_id = ? OR is_global = 1)', [$domainId])
            ->where('board_slug', '=', $slug)
            ->where('board_id', '!=', $boardId);

        return $query->exists();
    }

    /**
     * 도메인별 게시판 수 조회
     */
    public function countByDomain(int $domainId): int
    {
        return $this->getDb()->table($this->table)
            ->whereRaw('(domain_id = ? OR is_global = 1)', [$domainId])
            ->count();
    }

    /**
     * 그룹별 게시판 수 조회
     */
    public function countByGroup(int $groupId): int
    {
        return $this->countBy(['group_id' => $groupId]);
    }

    /**
     * 도메인별 활성 게시판 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return BoardConfig[]
     */
    public function findActiveByDomain(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->whereRaw('(domain_id = ? OR is_global = 1)', [$domainId])
            ->where('is_active', '=', 1)
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 그룹별 활성 게시판 목록 조회
     *
     * @param int $groupId 그룹 ID
     * @return BoardConfig[]
     */
    public function findActiveByGroup(int $groupId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('group_id', '=', $groupId)
            ->where('is_active', '=', 1)
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 정렬 순서 업데이트
     *
     * @param int[] $boardIds 정렬된 게시판 ID 배열
     * @return bool
     */
    public function updateOrder(array $boardIds): bool
    {
        foreach ($boardIds as $index => $boardId) {
            $this->getDb()->table($this->table)
                ->where('board_id', '=', $boardId)
                ->update(['sort_order' => $index]);
        }

        return true;
    }

    /**
     * 다음 정렬 순서 반환
     */
    public function getNextSortOrder(int $domainId): int
    {
        $maxOrder = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->max('sort_order');

        return ($maxOrder ?? -1) + 1;
    }

    /**
     * 게시글 수 조회
     */
    public function getArticleCount(int $boardId): int
    {
        return $this->getDb()->table('board_articles')
            ->where('board_id', '=', $boardId)
            ->count();
    }

    /**
     * 선택 옵션용 게시판 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return array [['value' => id, 'label' => name, 'group_id' => groupId], ...]
     */
    public function getSelectOptions(int $domainId): array
    {
        $boards = $this->findActiveByDomain($domainId);

        return array_map(fn(BoardConfig $board) => [
            'value' => $board->getBoardId(),
            'label' => $board->getBoardName(),
            'group_id' => $board->getGroupId(),
        ], $boards);
    }

    /**
     * 게시판 목록 조회 (그룹 정보 포함)
     *
     * @param int $domainId 도메인 ID
     * @return array 게시판 배열 (그룹 정보 포함)
     */
    public function findByDomainWithGroup(int $domainId, int $limit = 100, int $offset = 0): array
    {
        $rows = $this->getDb()->table($this->table . ' AS b')
            ->select([
                'b.*',
                'g.group_name',
                'g.group_slug',
            ])
            ->leftJoin('board_groups AS g', 'b.group_id', '=', 'g.group_id')
            ->whereRaw('(b.domain_id = ? OR b.is_global = 1)', [$domainId])
            ->orderBy('g.sort_order', 'ASC')
            ->orderBy('b.sort_order', 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        // Entity와 그룹 정보를 함께 반환
        $result = [];
        foreach ($rows as $row) {
            $config = BoardConfig::fromArray($row);
            $result[] = [
                'config' => $config,
                'group_name' => $row['group_name'] ?? '',
                'group_slug' => $row['group_slug'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * 카테고리를 사용하는 게시판 목록 조회
     *
     * @param int $categoryId 카테고리 ID
     * @return BoardConfig[]
     */
    public function findByCategoryId(int $categoryId): array
    {
        $rows = $this->getDb()->table($this->table . ' AS b')
            ->select(['b.*'])
            ->innerJoin('board_category_mapping AS m', 'b.board_id', '=', 'm.board_id')
            ->where('m.category_id', '=', $categoryId)
            ->orderBy('b.sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * JSON 필드 업데이트 (board_admin_ids, reaction_config)
     */
    public function updateJsonField(int $boardId, string $field, ?array $value): bool
    {
        $allowedFields = ['board_admin_ids', 'reaction_config'];

        if (!in_array($field, $allowedFields, true)) {
            return false;
        }

        $jsonValue = $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);

        $affected = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->update([$field => $jsonValue]);

        return $affected >= 0;
    }
}
