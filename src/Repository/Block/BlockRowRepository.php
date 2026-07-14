<?php
namespace Mublo\Repository\Block;

use Mublo\Entity\Block\BlockRow;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * BlockRow Repository
 *
 * 블록 행(Row) 데이터베이스 접근 담당
 *
 * 책임:
 * - block_rows 테이블 CRUD
 * - BlockRow Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class BlockRowRepository extends BaseRepository
{
    /**
     * position_menu 특수값 — "메인화면(index) 전용".
     * 전역(NULL/'')·특정 메뉴 타겟과 별개로, 방문자가 메인화면을 볼 때만 출력된다.
     */
    public const POSITION_MENU_MAIN_ONLY = '__index__';

    protected string $table = 'block_rows';
    protected string $entityClass = BlockRow::class;
    protected string $primaryKey = 'row_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /** 예상 버전과 일치할 때만 수정하고 버전을 원자적으로 증가시킨다. */
    public function updateIfRevision(int $rowId, int $expectedRevision, array $data): int
    {
        $data['revision_no'] = $expectedRevision + 1;
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->getDb()->table($this->table)
            ->where('row_id', '=', $rowId)
            ->where('revision_no', '=', $expectedRevision)
            ->update($data);
    }

    /**
     * 도메인별 행 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $limit 조회 개수
     * @param int $offset 시작 위치
     * @return BlockRow[]
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
     * 페이지별 행 목록 조회
     *
     * @param int $pageId 페이지 ID
     * @return BlockRow[]
     */
    public function findByPage(int $pageId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('page_id', '=', $pageId)
            ->whereRaw(
                'domain_id = (SELECT p.domain_id FROM block_pages p WHERE p.page_id = ?)',
                [$pageId]
            )
            ->where('is_active', '=', 1)
            ->whereRaw(
                'EXISTS (SELECT 1 FROM block_columns c WHERE c.row_id = block_rows.row_id '
                . 'AND c.domain_id = block_rows.domain_id AND c.is_active = 1)'
            )
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 위치별 행 목록 조회 (프론트 렌더링용)
     *
     * @param int $domainId 도메인 ID
     * @param string $position 위치 (index, header, footer, left, right 등)
     * @param string|null $menuCode 특정 메뉴 코드 (선택)
     * @return BlockRow[]
     */
    public function findByPosition(int $domainId, string $position, ?string $menuCode = null, bool $isMainScreen = false): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('position', '=', $position)
            ->where('is_active', '=', 1);

        // 메뉴 코드 필터링: 전역(null) + 해당 메뉴 + (메인화면이면) 메인 전용(__index__)
        $mainOnly = self::POSITION_MENU_MAIN_ONLY;
        $query->where(function ($q) use ($menuCode, $isMainScreen, $mainOnly) {
            $q->whereNull('position_menu');
            if ($menuCode !== null) {
                $q->orWhere('position_menu', '=', $menuCode);
            }
            if ($isMainScreen) {
                $q->orWhere('position_menu', '=', $mainOnly);
            }
        });

        // 칼럼이 존재하는 행만 반환 (빈 로우 프루닝)
        $query->whereRaw(
            'EXISTS (SELECT 1 FROM block_columns c WHERE c.row_id = block_rows.row_id AND c.is_active = 1)'
        );

        $rows = $query->orderBy('sort_order', 'ASC')->get();

        return $this->toEntities($rows);
    }

    /**
     * 위치별 모든 행 조회 (관리자용, is_active 무관)
     *
     * @param int $domainId 도메인 ID
     * @param string|null $position 위치 필터 (null이면 전체)
     * @param string|null $menuCode 메뉴 필터. 주면 프론트와 같은 스코프로 좁힌다 —
     *                              전역 행(position_menu IS NULL) + 그 메뉴 행.
     *                              null이면 그 위치의 모든 행(전역 + 모든 메뉴).
     * @return BlockRow[]
     */
    public function findAllByPosition(int $domainId, ?string $position = null, ?string $menuCode = null): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->whereNull('page_id');

        if ($position !== null) {
            $query->where('position', '=', $position);
        }

        // 메뉴를 지정하면 "그 페이지에 실제로 뜨는 것"을 보여준다 —
        // 프론트 findByPosition() 과 같은 규칙(전역 OR 그 메뉴)이다.
        // 빈 문자열은 시더·직접 INSERT 로 들어온 전역 행이므로 NULL 과 같이 취급한다.
        if ($menuCode !== null && $menuCode !== '') {
            $query->where(function ($q) use ($menuCode) {
                $q->whereNull('position_menu')
                    ->orWhere('position_menu', '=', '')
                    ->orWhere('position_menu', '=', $menuCode);
            });
        }

        $rows = $query->orderBy('position', 'ASC')
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 페이지별 모든 행 조회 (관리자용, is_active 무관)
     *
     * @param int $pageId 페이지 ID
     * @return BlockRow[]
     */
    public function findAllByPage(int $pageId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('page_id', '=', $pageId)
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /** replace/delete와 정확히 같은 position_menu 스코프의 모든 행을 조회한다. */
    public function findByPositionScope(int $domainId, string $position, ?string $menuCode): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('position', '=', $position)
            ->whereNull('page_id');

        if ($menuCode === null || $menuCode === '') {
            $query->where(function ($q) {
                $q->whereNull('position_menu')->orWhere('position_menu', '=', '');
            });
        } else {
            $query->where('position_menu', '=', $menuCode);
        }

        return $this->toEntities($query->orderBy('sort_order', 'ASC')->get());
    }

    /**
     * 도메인별 행 수 조회
     */
    public function countByDomain(int $domainId): int
    {
        return $this->countBy(['domain_id' => $domainId]);
    }

    /**
     * 페이지별 행 수 조회
     */
    public function countByPage(int $pageId): int
    {
        return $this->countBy(['page_id' => $pageId]);
    }

    /**
     * 위치별 행 수 조회
     */
    public function countByPosition(int $domainId, string $position): int
    {
        return $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('position', '=', $position)
            ->count();
    }

    /**
     * 정렬 순서 업데이트
     *
     * @param int[] $rowIds 정렬된 행 ID 배열
     * @return bool
     */
    public function updateOrder(array $rowIds): bool
    {
        foreach ($rowIds as $index => $rowId) {
            $this->getDb()->execute(
                'UPDATE block_rows SET sort_order = ?, revision_no = revision_no + 1, updated_at = ? WHERE row_id = ?',
                [$index, date('Y-m-d H:i:s'), (int) $rowId]
            );
        }

        return true;
    }

    /**
     * 지정된 행 ID들이 모두 해당 도메인에 속하는지 검증
     *
     * @param int[] $rowIds 행 ID 배열
     * @param int $domainId 도메인 ID
     * @return bool 모두 해당 도메인 소속이면 true
     */
    public function verifyAllBelongToDomain(array $rowIds, int $domainId): bool
    {
        if (empty($rowIds)) {
            return true;
        }

        $intIds = array_map('intval', $rowIds);
        $count = $this->getDb()->table($this->table)
            ->whereIn('row_id', $intIds)
            ->where('domain_id', '=', $domainId)
            ->count();

        return $count === count($intIds);
    }

    /**
     * 다음 정렬 순서 반환 (위치 기반)
     */
    public function getNextSortOrderByPosition(int $domainId, string $position): int
    {
        $maxOrder = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('position', '=', $position)
            ->max('sort_order');

        return ($maxOrder ?? -1) + 1;
    }

    /**
     * 다음 정렬 순서 반환 (페이지 기반)
     */
    public function getNextSortOrderByPage(int $pageId): int
    {
        $maxOrder = $this->getDb()->table($this->table)
            ->where('page_id', '=', $pageId)
            ->max('sort_order');

        return ($maxOrder ?? -1) + 1;
    }

    /**
     * 페이지네이션 (위치 기반)
     *
     * @param int $domainId 도메인 ID
     * @param string|null $position 위치 필터
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @return array
     */
    public function paginateByPosition(int $domainId, ?string $position = null, int $page = 1, int $perPage = 15): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->whereNull('page_id');

        if ($position !== null) {
            $query->where('position', '=', $position);
        }

        $total = (clone $query)->count();

        $rows = $query->orderBy('position', 'ASC')
            ->orderBy('sort_order', 'ASC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data' => $this->toEntities($rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    }

    /**
     * 페이지네이션 (페이지 기반)
     *
     * @param int $domainId 도메인 ID
     * @param int|null $pageId 페이지 ID 필터
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @return array
     */
    public function paginateByPage(int $domainId, ?int $pageId = null, int $page = 1, int $perPage = 15): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->whereNotNull('page_id');

        if ($pageId !== null) {
            $query->where('page_id', '=', $pageId);
        }

        $total = (clone $query)->count();

        $rows = $query->orderBy('page_id', 'ASC')
            ->orderBy('sort_order', 'ASC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data' => $this->toEntities($rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    }

    /**
     * 행 목록 조회 (페이지 정보 포함)
     *
     * @param int $domainId 도메인 ID
     * @return array
     */
    public function findByDomainWithPageInfo(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table . ' AS r')
            ->select([
                'r.*',
                'p.page_code',
                'p.page_title',
            ])
            ->leftJoin('block_pages AS p', 'r.page_id', '=', 'p.page_id')
            ->where('r.domain_id', '=', $domainId)
            ->orderBy('r.position', 'ASC')
            ->orderBy('r.sort_order', 'ASC')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $blockRow = BlockRow::fromArray($row);
            $result[] = [
                'row' => $blockRow,
                'page_code' => $row['page_code'] ?? null,
                'page_title' => $row['page_title'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * ID 배열로 행 일괄 조회
     *
     * @param int[] $rowIds 행 ID 배열
     * @return BlockRow[] ID 순서 유지
     */
    public function findByIds(array $rowIds): array
    {
        if (empty($rowIds)) {
            return [];
        }

        $rows = $this->getDb()->table($this->table)
            ->whereIn('row_id', $rowIds)
            ->get();

        // ID → Entity 매핑
        $mapped = [];
        foreach ($rows as $row) {
            $entity = BlockRow::fromArray($row);
            $mapped[$entity->getRowId()] = $entity;
        }

        // 원래 ID 순서 유지
        $result = [];
        foreach ($rowIds as $id) {
            if (isset($mapped[$id])) {
                $result[] = $mapped[$id];
            }
        }
        return $result;
    }

    /** @return BlockRow[] 캐시된 ID를 현재 도메인 경계 안에서만 복원 */
    public function findByIdsForDomain(int $domainId, array $rowIds): array
    {
        if (empty($rowIds)) {
            return [];
        }

        $rows = $this->getDb()->table($this->table)
            ->whereIn('row_id', $rowIds)
            ->where('domain_id', '=', $domainId)
            ->get();

        return $this->orderRowsByRequestedIds($rows, $rowIds);
    }

    /** @return BlockRow[] 캐시된 ID를 페이지 소유 도메인 경계 안에서만 복원 */
    public function findByIdsForPage(int $pageId, array $rowIds): array
    {
        if (empty($rowIds)) {
            return [];
        }

        $rows = $this->getDb()->table($this->table)
            ->whereIn('row_id', $rowIds)
            ->where('page_id', '=', $pageId)
            ->whereRaw(
                'domain_id = (SELECT p.domain_id FROM block_pages p WHERE p.page_id = ?)',
                [$pageId]
            )
            ->get();

        return $this->orderRowsByRequestedIds($rows, $rowIds);
    }

    /** @return BlockRow[] */
    private function orderRowsByRequestedIds(array $rows, array $rowIds): array
    {
        $entities = $this->toEntities($rows);
        $byId = [];
        foreach ($entities as $entity) {
            $byId[$entity->getRowId()] = $entity;
        }

        $result = [];
        foreach ($rowIds as $rowId) {
            if (isset($byId[(int) $rowId])) {
                $result[] = $byId[(int) $rowId];
            }
        }

        return $result;
    }

    /**
     * 도메인별 사용 중인 메뉴 코드 목록 조회
     *
     * @return string[] position_menu 값 배열 (null 제외)
     */
    public function getDistinctMenuCodes(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->select(['position_menu'])
            ->where('domain_id', '=', $domainId)
            ->whereNotNull('position_menu')
            ->distinct()
            ->get();

        return array_column($rows, 'position_menu');
    }

    /**
     * 도메인별 행이 있는 페이지 ID 목록 조회
     *
     * 도메인 전체 캐시 무효화가 페이지 행 목록 캐시까지 지우기 위한 근거.
     *
     * @return int[] page_id 값 배열 (null 제외)
     */
    public function getDistinctPageIds(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->select(['page_id'])
            ->where('domain_id', '=', $domainId)
            ->whereNotNull('page_id')
            ->distinct()
            ->get();

        return array_map('intval', array_column($rows, 'page_id'));
    }

    /**
     * JSON 필드 업데이트 (background_config)
     */
    public function updateBackgroundConfig(int $rowId, ?array $config): bool
    {
        $jsonValue = $config === null ? null : json_encode($config, JSON_UNESCAPED_UNICODE);

        $affected = $this->getDb()->table($this->table)
            ->where('row_id', '=', $rowId)
            ->update(['background_config' => $jsonValue]);

        return $affected >= 0;
    }

    /**
     * 페이지 삭제 시 관련 행 삭제
     */
    /**
     * 위치별 행 삭제 (블록 킷 replace 모드용)
     *
     * findByPosition() 은 프론트 렌더용이라 is_active=1 로 거르고, menuCode 를 주면
     * 전역(position_menu IS NULL) 행까지 함께 매칭한다. 삭제에 그 조건을 쓰면
     * 비활성 행이 살아남아 중복되고 무관한 전역 행이 지워진다.
     * 따라서 여기서는 position_menu 를 정확히 일치시키고 is_active 를 보지 않는다.
     *
     * block_columns.row_id 는 ON DELETE CASCADE 이므로 칸은 함께 지워진다.
     *
     * @param int $domainId 도메인 ID
     * @param string $position 위치
     * @param string|null $menuCode 메뉴 코드 (null 이면 전역 행만)
     * @return int 삭제된 행 수
     */
    public function deleteByPosition(int $domainId, string $position, ?string $menuCode = null): int
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('position', '=', $position)
            ->whereNull('page_id');

        if ($menuCode === null) {
            // 전역 행은 NULL 이 정상이지만 시더·직접 INSERT 로 빈 문자열이 들어온 행이 있을 수 있다.
            // 내보내기는 ''를 전역으로 보므로, 삭제도 같은 기준이어야 replace 후 중복이 남지 않는다.
            $query->where(function ($q) {
                $q->whereNull('position_menu')
                    ->orWhere('position_menu', '=', '');
            });
        } else {
            $query->where('position_menu', '=', $menuCode);
        }

        return $query->delete();
    }

    public function deleteByPage(int $pageId): int
    {
        return $this->getDb()->table($this->table)
            ->where('page_id', '=', $pageId)
            ->delete();
    }
}
