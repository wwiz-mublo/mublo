<?php
namespace Mublo\Plugin\Banner\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\Banner\Entity\Banner;
use Mublo\Repository\BaseRepository;

/**
 * BannerRepository
 *
 * 배너 데이터베이스 접근
 */
class BannerRepository extends BaseRepository
{
    protected string $table = 'banners';
    protected string $entityClass = Banner::class;
    protected string $primaryKey = 'banner_id';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    /**
     * 도메인별 배너 목록
     */
    public function findByDomain(int $domainId, bool $activeOnly = false): array
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId);

        if ($activeOnly) {
            $query->where('is_active', '=', 1);
        }

        return $query->orderBy('sort_order', 'ASC')
            ->orderBy('banner_id', 'ASC')
            ->get();
    }

    /**
     * 페이지네이션 목록 (관리자)
     */
    public function findPaginated(int $domainId, int $page = 1, int $perPage = 20, array $search = []): array
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId);

        if (!empty($search['keyword'])) {
            $query->where('title', 'LIKE', "%{$search['keyword']}%");
        }

        $total = $query->count();

        $offset = ($page - 1) * $perPage;
        $items = $query
            ->orderBy('sort_order', 'ASC')
            ->orderBy('banner_id', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * ID 배열로 배너 조회 (블록 렌더링용)
     *
     * 활성 + 날짜 범위 내인 배너만 반환, content_items 순서 유지
     */
    public function findByIds(int $domainId, array $bannerIds): array
    {
        if (empty($bannerIds)) {
            return [];
        }

        $today = date('Y-m-d');
        $placeholders = implode(',', array_fill(0, count($bannerIds), '?'));

        return $this->db->table($this->table)
            ->whereRaw("banner_id IN ({$placeholders})", array_values($bannerIds))
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->whereRaw('(start_date IS NULL OR start_date <= ?)', [$today])
            ->whereRaw('(end_date IS NULL OR end_date >= ?)', [$today])
            ->orderByRaw("FIELD(banner_id, {$placeholders})", array_values($bannerIds))
            ->get();
    }

    /**
     * PK + domain_id 로 단건 조회 (도메인 경계 보장)
     */
    public function findWithDomain(int $bannerId, int $domainId): ?Banner
    {
        $row = $this->db->table($this->table)
            ->where('banner_id', '=', $bannerId)
            ->where('domain_id', '=', $domainId)
            ->first();

        return $row ? Banner::fromArray($row) : null;
    }

    /**
     * 정렬 순서 변경 (도메인 경계 보장)
     */
    public function updateSortOrder(int $bannerId, int $domainId, int $order): int
    {
        return $this->db->table($this->table)
            ->where('banner_id', '=', $bannerId)
            ->where('domain_id', '=', $domainId)
            ->update(['sort_order' => $order]);
    }

    /**
     * 배너 삭제 (도메인 경계 보장)
     */
    public function deleteWithDomain(int $bannerId, int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('banner_id', '=', $bannerId)
            ->where('domain_id', '=', $domainId)
            ->delete();
    }

    /**
     * 배너 수정 (도메인 경계 보장)
     */
    public function updateWithDomain(int $bannerId, int $domainId, array $data): int
    {
        return $this->db->table($this->table)
            ->where('banner_id', '=', $bannerId)
            ->where('domain_id', '=', $domainId)
            ->update($data);
    }

    /**
     * ID 배열로 extras만 조회 (banner_id → extras 매핑)
     *
     * @return array<int, array|null> [banner_id => decoded extras, ...]
     */
    public function findExtrasMap(int $domainId, array $bannerIds): array
    {
        if (empty($bannerIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($bannerIds), '?'));
        $rows = $this->db->table($this->table)
            ->select(['banner_id', 'extras'])
            ->whereRaw("banner_id IN ({$placeholders})", array_values($bannerIds))
            ->where('domain_id', '=', $domainId)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $extras = $row['extras'] ?? null;
            $map[(int) $row['banner_id']] = $extras !== null
                ? (is_string($extras) ? json_decode($extras, true) : $extras)
                : null;
        }

        return $map;
    }

    protected function toEntity(array $row): Banner
    {
        return Banner::fromArray($row);
    }
}
