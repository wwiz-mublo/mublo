<?php
namespace Mublo\Repository\Menu;

use Mublo\Entity\Menu\MenuItem;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\BaseRepository;

/**
 * MenuItemRepository
 *
 * 메뉴 아이템 풀 (menu_items) 데이터베이스 접근
 *
 * 책임:
 * - menu_items 테이블 CRUD
 * - 도메인별 메뉴 아이템 조회
 * - 유틸리티/푸터 메뉴 조회
 */
class MenuItemRepository extends BaseRepository
{
    protected string $table = 'menu_items';
    protected string $entityClass = MenuItem::class;
    protected string $primaryKey = 'item_id';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    /**
     * 도메인별 전체 메뉴 아이템 조회
     */
    public function findByDomain(int $domainId, bool $activeOnly = false): array
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId);

        if ($activeOnly) {
            $query->where('is_active', '=', 1);
        }

        return $query->orderBy('label', 'ASC')->get();
    }

    /**
     * 도메인별 메뉴 코드 목록 조회 (활성/비활성 무관)
     *
     * 블록 행 목록 캐시가 메뉴 코드별로 쪼개지므로(block:ids:pos:{d}:{pos}:{menuCode}),
     * 캐시 무효화 시 "존재할 수 있는 스코프"를 열거하는 근거로 쓴다.
     * 비활성 메뉴도 포함해야 한다 — 캐시가 쓰인 뒤 비활성화된 메뉴의 키가 남기 때문.
     *
     * @return string[] menu_code 값 배열
     */
    public function findMenuCodesByDomain(int $domainId): array
    {
        $rows = $this->db->table($this->table)
            ->select(['menu_code'])
            ->where('domain_id', '=', $domainId)
            ->distinct()
            ->get();

        return array_values(array_filter(
            array_column($rows, 'menu_code'),
            static fn($code) => is_string($code) && $code !== ''
        ));
    }

    /**
     * 메뉴 코드로 조회
     */
    public function findByMenuCode(int $domainId, string $menuCode): ?array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('menu_code', '=', $menuCode)
            ->first();
    }

    /**
     * 주어진 item_id 중 해당 도메인 소유인 것만 반환 (일괄 수정 소유권 검증용).
     *
     * @param int[] $itemIds
     * @return int[] 도메인 소유 item_id 목록
     */
    public function findOwnedItemIds(int $domainId, array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $rows = $this->db->table($this->table)
            ->select(['item_id'])
            ->where('domain_id', '=', $domainId)
            ->whereIn('item_id', $itemIds)
            ->get();

        return array_map(static fn ($row) => (int) $row['item_id'], $rows);
    }

    /**
     * 도메인 스코프 UPDATE — item_id 와 domain_id 를 모두 WHERE 에 걸어
     * 다른 도메인의 행은 절대 갱신되지 않게 한다(IDOR 심층 방어).
     *
     * @return int 실제 갱신된 행 수
     */
    public function updateInDomain(int $itemId, int $domainId, array $data): int
    {
        return $this->db->table($this->table)
            ->where('item_id', '=', $itemId)
            ->where('domain_id', '=', $domainId)
            ->update($data);
    }

    /**
     * 메뉴 코드 중복 체크
     */
    public function existsByMenuCode(int $domainId, string $menuCode, ?int $excludeId = null): bool
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('menu_code', '=', $menuCode);

        if ($excludeId !== null) {
            $query->where('item_id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * 유틸리티 메뉴 조회
     */
    public function findUtilityMenus(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('show_in_utility', '=', 1)
            ->where('is_active', '=', 1)
            ->orderBy('utility_order', 'ASC')
            ->get();
    }

    /**
     * 푸터 메뉴 조회
     */
    public function findFooterMenus(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('show_in_footer', '=', 1)
            ->where('is_active', '=', 1)
            ->orderBy('footer_order', 'ASC')
            ->get();
    }

    /**
     * 유틸리티 플래그 전체 초기화
     */
    public function resetUtilityFlags(int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->update(['show_in_utility' => 0, 'utility_order' => 0]);
    }

    /**
     * 유틸리티 아이템 활성화 + 순서 설정
     */
    public function setUtilityActive(int $itemId, int $domainId, int $order): int
    {
        return $this->db->table($this->table)
            ->where('item_id', '=', $itemId)
            ->where('domain_id', '=', $domainId)
            ->update(['show_in_utility' => 1, 'utility_order' => $order]);
    }

    /**
     * 유틸리티 메뉴 순서 업데이트
     */
    public function updateUtilityOrder(int $itemId, int $domainId, int $order): int
    {
        return $this->db->table($this->table)
            ->where('item_id', '=', $itemId)
            ->where('domain_id', '=', $domainId)
            ->update(['utility_order' => $order]);
    }

    /**
     * 푸터 플래그 전체 초기화
     */
    public function resetFooterFlags(int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->update(['show_in_footer' => 0, 'footer_order' => 0]);
    }

    /**
     * 푸터 아이템 활성화 + 순서 설정
     */
    public function setFooterActive(int $itemId, int $domainId, int $order): int
    {
        return $this->db->table($this->table)
            ->where('item_id', '=', $itemId)
            ->where('domain_id', '=', $domainId)
            ->update(['show_in_footer' => 1, 'footer_order' => $order]);
    }

    /**
     * 푸터 메뉴 순서 업데이트
     */
    public function updateFooterOrder(int $itemId, int $domainId, int $order): int
    {
        return $this->db->table($this->table)
            ->where('item_id', '=', $itemId)
            ->where('domain_id', '=', $domainId)
            ->update(['footer_order' => $order]);
    }

    /**
     * 마이페이지 메뉴 조회
     */
    public function findMypageMenus(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('show_in_mypage', '=', 1)
            ->where('is_active', '=', 1)
            ->orderBy('mypage_order', 'ASC')
            ->get();
    }

    /**
     * 시스템 메뉴(is_system=1) 아이템 ID 목록
     *
     * 마이페이지 순서 재부여 시 시스템 메뉴를 제외하기 위한 용도.
     */
    public function findSystemItemIds(int $domainId): array
    {
        $rows = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_system', '=', 1)
            ->get();

        return array_map(static fn($row) => (int) $row['item_id'], $rows);
    }

    /**
     * 마이페이지 플래그 전체 초기화 (시스템 메뉴 제외)
     */
    public function resetMypageFlags(int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_system', '=', 0)
            ->update(['show_in_mypage' => 0, 'mypage_order' => 0]);
    }

    /**
     * 마이페이지 아이템 활성화 + 순서 설정
     */
    public function setMypageActive(int $itemId, int $domainId, int $order): int
    {
        return $this->db->table($this->table)
            ->where('item_id', '=', $itemId)
            ->where('domain_id', '=', $domainId)
            ->update(['show_in_mypage' => 1, 'mypage_order' => $order]);
    }

    /**
     * 마이페이지 메뉴 순서 업데이트
     */
    public function updateMypageOrder(int $itemId, int $domainId, int $order): int
    {
        return $this->db->table($this->table)
            ->where('item_id', '=', $itemId)
            ->where('domain_id', '=', $domainId)
            ->update(['mypage_order' => $order]);
    }

    /**
     * URL 접두사로 메뉴 아이템 조회
     *
     * 특정 URL 패턴(예: /rental/category/)으로 시작하는 메뉴 아이템 목록 반환.
     * 패키지에서 등록한 카테고리 메뉴 확인 등에 활용.
     *
     * @return array [['item_id' => int, 'url' => string, 'label' => string, ...], ...]
     */
    public function findByUrlPrefix(int $domainId, string $urlPrefix): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('url', 'LIKE', $urlPrefix . '%')
            ->get();
    }

    /**
     * 제공자별 메뉴 조회
     */
    public function findByProvider(int $domainId, string $providerType, ?string $providerName = null): array
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('provider_type', '=', $providerType);

        if ($providerName !== null) {
            $query->where('provider_name', '=', $providerName);
        }

        return $query->get();
    }

    /**
     * 제공자별 메뉴 삭제
     */
    public function deleteByProvider(int $domainId, string $providerType, string $providerName): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('provider_type', '=', $providerType)
            ->where('provider_name', '=', $providerName)
            ->delete();
    }

    /**
     * 메뉴 코드 목록으로 조회
     */
    public function findByMenuCodes(int $domainId, array $menuCodes): array
    {
        if (empty($menuCodes)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($menuCodes), '?'));

        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->whereRaw("menu_code IN ({$placeholders})", array_values($menuCodes))
            ->get();
    }

    /**
     * 검색/페이지네이션이 적용된 메뉴 아이템 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 항목 수
     * @param array $search 검색 조건 ['keyword' => '', 'field' => '']
     * @return array ['items' => [], 'total' => int]
     */
    /** 목록 정렬 허용 컬럼 (allowlist, SQL 주입 방지) */
    public const SORTABLE_FIELDS = ['item_id', 'provider_type', 'provider_name', 'label', 'url'];

    /**
     * 정렬 컬럼/방향 검증 (allowlist 기반). 미허용 시 [item_id, DESC].
     *
     * @return array{0:string,1:string} [컬럼, 방향]
     */
    public function resolveSort(?string $sort, ?string $order): array
    {
        $col = in_array($sort, self::SORTABLE_FIELDS, true) ? $sort : 'item_id';
        $dir = strtoupper((string) $order) === 'ASC' ? 'ASC' : 'DESC';
        return [$col, $dir];
    }

    public function findPaginated(int $domainId, int $page = 1, int $perPage = 20, array $search = [], ?string $sort = null, ?string $order = null): array
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId);

        // 검색 조건 적용
        if (!empty($search['keyword']) && !empty($search['field'])) {
            $keyword = $search['keyword'];
            $field = $search['field'];

            // 허용된 검색 필드
            $allowedFields = ['label', 'url', 'menu_code', 'provider_name'];
            if (in_array($field, $allowedFields)) {
                $query->where($field, 'LIKE', "%{$keyword}%");
            }
        } elseif (!empty($search['keyword'])) {
            // 필드 지정 없이 키워드만 있으면 label 검색
            $query->where('label', 'LIKE', "%{$search['keyword']}%");
        }

        // 제공자 유형 필터
        if (!empty($search['provider_type'])) {
            $allowedTypes = ['core', 'plugin', 'package'];
            if (in_array($search['provider_type'], $allowedTypes)) {
                $query->where('provider_type', '=', $search['provider_type']);
            }
        }

        // 제공자 이름 필터 (정확 매치)
        if (!empty($search['provider_name'])) {
            $query->where('provider_name', '=', $search['provider_name']);
        }

        // 전체 개수 조회
        $total = $query->count();

        // 정렬 (allowlist 검증, 기본 item_id DESC)
        [$sortCol, $sortDir] = $this->resolveSort($sort, $order);

        // 페이지네이션 적용
        $offset = ($page - 1) * $perPage;
        $items = $query
            ->orderBy($sortCol, $sortDir)
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * 도메인별 고유 제공자 목록 조회 (provider_type, provider_name)
     *
     * @return array [['provider_type' => string, 'provider_name' => string], ...]
     */
    public function findDistinctProviders(int $domainId): array
    {
        return $this->db->table($this->table)
            ->select(['provider_type', 'provider_name'])
            ->where('domain_id', '=', $domainId)
            ->groupBy(['provider_type', 'provider_name'])
            ->orderBy('provider_type', 'ASC')
            ->orderBy('provider_name', 'ASC')
            ->get();
    }

    /**
     * URL 맵 조회 (메뉴 매칭용)
     *
     * 활성 메뉴 중 URL이 있는 항목의 [menu_code, url, 레이아웃 오버라이드] 목록 반환.
     * 레이아웃 컬럼을 함께 실어, 현재 메뉴 확정 시 Context 에 오버라이드를 담아
     * FrontViewRenderer 가 렌더 때 별도 조회 없이 쓰게 한다(캐시 히트 시 쿼리 0).
     *
     * @return array [['menu_code' => string, 'url' => string, 'layout_type' => ?int, ...], ...]
     */
    public function findUrlMap(int $domainId): array
    {
        return $this->db->table($this->table)
            ->select([
                'menu_code', 'url',
                'layout_type', 'sidebar_left_width', 'sidebar_left_mobile',
                'sidebar_right_width', 'sidebar_right_mobile',
            ])
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->whereNotNull('url')
            ->get();
    }

    /**
     * 아이템 ID 목록에서 pair_code 수집 (NULL 제외)
     *
     * @return string[] pair_code 문자열 배열
     */
    public function findPairCodesByIds(int $domainId, array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $params = array_merge([$domainId], array_map('intval', $itemIds));

        $rows = $this->db->select(
            "SELECT DISTINCT pair_code FROM {$this->table}
             WHERE domain_id = ? AND item_id IN ({$placeholders}) AND pair_code IS NOT NULL AND pair_code != ''",
            $params
        );

        return array_column($rows, 'pair_code');
    }

    /**
     * pair_code 목록으로 해당하는 아이템 ID 조회 (excludeIds 제외)
     *
     * @return int[] item_id 배열
     */
    public function findPairedItemIds(int $domainId, array $pairCodes, array $excludeIds): array
    {
        if (empty($pairCodes)) {
            return [];
        }

        $pairPlaceholders = implode(',', array_fill(0, count($pairCodes), '?'));
        $params = array_merge([$domainId], $pairCodes);

        $excludeClause = '';
        if (!empty($excludeIds)) {
            $excludePlaceholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $excludeClause = " AND item_id NOT IN ({$excludePlaceholders})";
            $params = array_merge($params, array_map('intval', $excludeIds));
        }

        $rows = $this->db->select(
            "SELECT item_id FROM {$this->table}
             WHERE domain_id = ? AND pair_code IN ({$pairPlaceholders}) AND is_active = 1{$excludeClause}",
            $params
        );

        return array_map(fn($row) => (int) $row['item_id'], $rows);
    }

    /**
     * 배열을 MenuItem Entity로 변환
     */
    protected function toEntity(array $row): MenuItem
    {
        return MenuItem::fromArray($row);
    }
}
