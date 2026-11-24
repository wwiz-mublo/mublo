<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Repository;

use Mublo\Contract\Board\BoardGroupDescriptor;
use Mublo\Contract\Board\BoardGroupReadOverlayInterface;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\BaseRepository;

/**
 * BoardGroup Repository
 *
 * 게시판 그룹 데이터베이스 접근 담당
 *
 * 책임:
 * - board_groups 테이블 CRUD
 * - BoardGroup Entity 반환
 * - 요청 단위 read overlay가 있으면 도메인 조회(findByDomain·findActiveByDomain·findBySlug·findByIdForDomain)를
 *   overlay의 최종 그룹 목록으로 응답. 커뮤니티 그룹 탭·그룹 화면·게시판 그룹 블록이 이 조회를 거친다.
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class BoardGroupRepository extends BaseRepository
{
    protected string $table = 'board_groups';
    protected string $entityClass = BoardGroup::class;
    protected string $primaryKey = 'group_id';

    public function __construct(Database $db, private readonly ?BoardGroupReadOverlayInterface $readOverlay = null)
    {
        parent::__construct($db);
    }

    /** @param list<BoardGroupDescriptor> $groups @return BoardGroup[] */
    private function fromDescriptors(array $groups): array
    {
        return array_map(static fn(BoardGroupDescriptor $group): BoardGroup => BoardGroup::fromArray($group->values), $groups);
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
        $overlay = $this->readOverlay?->groups($domainId);
        if ($overlay !== null) {
            return array_slice($this->fromDescriptors($overlay), $offset, $limit);
        }
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
        // overlay가 있는 도메인은 overlay 목록이 최종 상태다. 없는 슬러그는 미리보기에서 없는 그룹이다.
        if ($this->readOverlay?->groups($domainId) !== null) {
            $group = $this->readOverlay->findBySlug($domainId, $slug);
            return $group !== null ? BoardGroup::fromArray($group->values) : null;
        }
        return $this->findOneBy([
            'domain_id' => $domainId,
            'group_slug' => $slug,
        ]);
    }

    /** 게시판 그룹은 전역 예외 없이 현재 도메인 소유만 허용한다. */
    public function findByIdForDomain(int $domainId, int $groupId): ?BoardGroup
    {
        if ($this->readOverlay?->groups($domainId) !== null) {
            $group = $this->readOverlay->findById($domainId, $groupId);
            return $group !== null ? BoardGroup::fromArray($group->values) : null;
        }
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
        $overlay = $this->readOverlay?->groups($domainId, true);
        if ($overlay !== null) {
            return $this->fromDescriptors($overlay);
        }
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
