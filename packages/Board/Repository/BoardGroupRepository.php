<?php
namespace Mublo\Packages\Board\Repository;

use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * BoardGroup Repository
 *
 * 게시판 그룹 데이터베이스 접근 담당
 *
 * 책임:
 * - board_groups 테이블 CRUD
 * - BoardGroup Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class BoardGroupRepository extends BaseRepository
{
    protected string $table = 'board_groups';
    protected string $entityClass = BoardGroup::class;
    protected string $primaryKey = 'group_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 도메인별 그룹 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $limit 조회 개수
     * @param int $offset 시작 위치
     * @return BoardGroup[]
     */
    public function findByDomain(int $domainId, int $limit = 100, int $offset = 0): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('sort_order', 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 도메인+슬러그로 그룹 조회
     */
    public function findBySlug(int $domainId, string $slug): ?BoardGroup
    {
        return $this->findOneBy([
            'domain_id' => $domainId,
            'group_slug' => $slug,
        ]);
    }

    /** 게시판 그룹은 전역 예외 없이 현재 도메인 소유만 허용한다. */
    public function findByIdForDomain(int $domainId, int $groupId): ?BoardGroup
    {
        return $this->findOneBy([
            'group_id' => $groupId,
            'domain_id' => $domainId,
        ]);
    }

    /**
     * 슬러그 중복 검사
     */
    public function existsBySlug(int $domainId, string $slug): bool
    {
        return $this->existsBy([
            'domain_id' => $domainId,
            'group_slug' => $slug,
        ]);
    }

    /**
     * 슬러그 중복 검사 (자기 자신 제외)
     */
    public function existsBySlugExceptSelf(int $domainId, string $slug, int $groupId): bool
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('group_slug', '=', $slug)
            ->where('group_id', '!=', $groupId);

        return $query->exists();
    }

    /**
     * 도메인별 그룹 수 조회
     */
    public function countByDomain(int $domainId): int
    {
        return $this->countBy(['domain_id' => $domainId]);
    }

    /**
     * 도메인별 활성 그룹 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return BoardGroup[]
     */
    public function findActiveByDomain(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 정렬 순서 업데이트
     *
     * @param int[] $groupIds 정렬된 그룹 ID 배열
     * @return bool
     */
    public function updateOrder(array $groupIds): bool
    {
        foreach ($groupIds as $index => $groupId) {
            $this->getDb()->table($this->table)
                ->where('group_id', '=', $groupId)
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
     * 그룹에 속한 게시판 수 조회
     */
    public function getBoardCount(int $groupId): int
    {
        return $this->getDb()->table('board_configs')
            ->where('group_id', '=', $groupId)
            ->count();
    }

    /**
     * 선택 옵션용 그룹 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return array [['value' => id, 'label' => name], ...]
     */
    public function getSelectOptions(int $domainId): array
    {
        $groups = $this->findActiveByDomain($domainId);

        return array_map(fn(BoardGroup $group) => [
            'value' => $group->getGroupId(),
            'label' => $group->getGroupName(),
        ], $groups);
    }
}
