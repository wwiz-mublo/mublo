<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\Shop\Entity\Product;
use Mublo\Repository\BaseRepository;

/**
 * Product Repository
 *
 * 상품 데이터베이스 접근 담당
 *
 * 책임:
 * - shop_products 테이블 CRUD
 * - Product Entity 반환
 * - 상품 이미지/상세정보 관리
 * - 필터링 + 페이지네이션 목록 조회
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class ProductRepository extends BaseRepository
{
    protected string $table = 'shop_products';
    protected string $entityClass = Product::class;
    protected string $primaryKey = 'goods_id';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    /**
     * @deprecated Shop 상품은 findInDomain()으로만 단건 조회한다.
     */
    public function find(int|string $id): ?object
    {
        throw new \LogicException('Shop 상품 조회에는 domainId가 필요합니다. findInDomain()을 사용하세요.');
    }

    /**
     * @deprecated Shop 상품은 updateInDomain()으로만 수정한다.
     */
    public function update(int|string $id, array $data): int
    {
        throw new \LogicException('Shop 상품 수정에는 domainId가 필요합니다. updateInDomain()을 사용하세요.');
    }

    /**
     * @deprecated Shop 상품은 deleteInDomain()으로만 삭제한다.
     */
    public function delete(int|string $id): int
    {
        throw new \LogicException('Shop 상품 삭제에는 domainId가 필요합니다. deleteInDomain()을 사용하세요.');
    }

    /**
     * 현재 도메인 소유 상품 단건 조회.
     *
     * Shop 상품은 도메인 간 공유하지 않는다. 외부 입력으로 받은 goods_id를
     * 조회할 때는 반드시 이 메서드를 사용해 소유 도메인을 함께 제한한다.
     */
    public function findInDomain(int $domainId, int $goodsId): ?Product
    {
        $row = $this->getDb()->table($this->table)
            ->where($this->primaryKey, '=', $goodsId)
            ->where('domain_id', '=', $domainId)
            ->first();

        return $row ? $this->toEntity($row) : null;
    }

    /**
     * 현재 도메인 소유 상품만 수정한다.
     */
    public function updateInDomain(int $domainId, int $goodsId, array $data): int
    {
        unset($data['domain_id']);
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->getDb()->table($this->table)
            ->where($this->primaryKey, '=', $goodsId)
            ->where('domain_id', '=', $domainId)
            ->update($data);
    }

    /**
     * 현재 도메인 소유 상품만 삭제한다.
     */
    public function deleteInDomain(int $domainId, int $goodsId): int
    {
        return $this->getDb()->table($this->table)
            ->where($this->primaryKey, '=', $goodsId)
            ->where('domain_id', '=', $domainId)
            ->delete();
    }

    /**
     * 상품 목록 조회 (페이지네이션 + 필터)
     *
     * @param int $domainId 도메인 ID
     * @param array $filters 필터 조건 (category_code, keyword, is_active, sort)
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @return array ['items' => Product[], 'pagination' => [...]]
     */
    public function getList(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId);

        // 카테고리 필터 (하위 카테고리 포함)
        if (!empty($filters['category_codes'])) {
            $query->whereIn('category_code', $filters['category_codes']);
        } elseif (!empty($filters['category_code'])) {
            $query->where('category_code', '=', $filters['category_code']);
        }

        // 키워드 검색 (상품명)
        if (!empty($filters['keyword'])) {
            $query->where('goods_name', 'LIKE', '%' . $filters['keyword'] . '%');
        }

        // 활성 상태 필터 ('' = 전체 → 필터 미적용. isset()은 빈문자열을 true로 봐 is_active=0으로 오작동했음)
        if (($filters['is_active'] ?? '') !== '') {
            $query->where('is_active', '=', (int) $filters['is_active']);
        }

        // 전체 개수
        $total = $query->count();

        // 정렬
        $sort = $filters['sort'] ?? 'newest';
        switch ($sort) {
            case 'price_asc':
                $query->orderBy('display_price', 'ASC');
                break;
            case 'price_desc':
                $query->orderBy('display_price', 'DESC');
                break;
            case 'popular':
                $query->orderBy('hit', 'DESC');
                break;
            case 'newest':
            default:
                $query->orderBy('created_at', 'DESC');
                break;
        }

        // 페이지네이션
        $offset = ($page - 1) * $perPage;
        $rows = $query->limit($perPage)->offset($offset)->get();

        return [
            'items' => $this->toEntities($rows),
            'pagination' => [
                'totalItems' => $total,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
            ],
        ];
    }

    /**
     * 상품 개수 조회 (필터 적용)
     *
     * @param int $domainId 도메인 ID
     * @param array $filters 필터 조건
     * @return int
     */
    public function getCount(int $domainId, array $filters = []): int
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId);

        if (!empty($filters['category_code'])) {
            $query->where('category_code', '=', $filters['category_code']);
        }

        if (!empty($filters['keyword'])) {
            $query->where('goods_name', 'LIKE', '%' . $filters['keyword'] . '%');
        }

        // '' = 전체 → 필터 미적용 (getList()와 동일 처리)
        if (($filters['is_active'] ?? '') !== '') {
            $query->where('is_active', '=', (int) $filters['is_active']);
        }

        return $query->count();
    }

    /**
     * 상품 일괄 삭제
     *
     * @param int $domainId 현재 도메인 ID
     * @param array $goodsIds 삭제할 상품 ID 배열
     * @return int 삭제된 행 수
     */
    public function deleteMultiple(int $domainId, array $goodsIds): int
    {
        if (empty($goodsIds)) {
            return 0;
        }

        return $this->getDb()->table($this->table)
            ->whereIn($this->primaryKey, $goodsIds)
            ->where('domain_id', '=', $domainId)
            ->delete();
    }

    /**
     * 도메인 소유 검증: 주어진 상품 ID 중 해당 도메인에 속하는 것만 반환
     *
     * @param array $goodsIds 상품 ID 배열
     * @param int $domainId 도메인 ID
     * @return array 해당 도메인에 속하는 goods_id 배열
     */
    public function filterByDomain(array $goodsIds, int $domainId): array
    {
        if (empty($goodsIds)) {
            return [];
        }

        $rows = $this->getDb()->table($this->table)
            ->select([$this->primaryKey])
            ->whereIn($this->primaryKey, $goodsIds)
            ->where('domain_id', '=', $domainId)
            ->get();

        return array_column($rows, $this->primaryKey);
    }

    /**
     * 주어진 접두사로 시작하는 상품코드 중 가장 큰 것
     *
     * item_code 는 도메인 무관 전역 유니크(uk_item_code)이므로 도메인으로 좁히지 않는다.
     * 좁히면 다른 도메인이 이미 쓴 번호를 다시 만들어 낸다.
     *
     * @return string|null 없으면 null
     */
    public function maxItemCodeWithPrefix(string $prefix): ?string
    {
        $sql = "SELECT item_code FROM {$this->table}
                WHERE item_code LIKE ?
                ORDER BY LENGTH(item_code) DESC, item_code DESC
                LIMIT 1";

        // LIKE 와일드카드(_, %)가 접두사에 섞여도 리터럴로 취급되게 이스케이프한다
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
        $rows = $this->getDb()->select($sql, [$escaped . '%']);

        return $rows[0]['item_code'] ?? null;
    }

    /**
     * 상품코드 사용 여부 (전역 유니크 기준)
     */
    public function itemCodeExists(string $itemCode): bool
    {
        $row = $this->getDb()->table($this->table)
            ->select(['item_code'])
            ->where('item_code', '=', $itemCode)
            ->first();

        return $row !== null;
    }

    /**
     * 상품 대표 이미지 배치 조회
     *
     * N+1 방지: 목록 조회 후 한 번의 WHERE IN 쿼리로 일괄 조회
     *
     * @param array $goodsIds 상품 ID 배열
     * @return array [goods_id => ['image_url' => ..., 'thumbnail_url' => ...]]
     */
    public function getMainImages(array $goodsIds): array
    {
        if (empty($goodsIds)) {
            return [];
        }

        // is_main=1 우선, 없으면 sort_order 가장 작은 이미지 폴백
        $rows = $this->getDb()->table('shop_product_images')
            ->whereIn('goods_id', $goodsIds)
            ->orderBy('is_main', 'DESC')
            ->orderBy('sort_order', 'ASC')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $gid = (int) $row['goods_id'];
            if (!isset($map[$gid])) {
                $map[$gid] = $row;
            }
        }

        return $map;
    }

    /**
     * 상품별 판매수량 배치 집계
     *
     * 결제완료(is_paid=1) 주문상세의 수량 합. 취소/반품 완료분은 제외.
     *
     * @param array $goodsIds 상품 ID 배열
     * @return array [goods_id => sold_count]
     */
    public function getSoldCounts(array $goodsIds): array
    {
        if (empty($goodsIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($goodsIds), '?'));
        $sql = "SELECT goods_id, SUM(quantity) AS cnt
                FROM shop_order_details
                WHERE goods_id IN ($placeholders)
                  AND is_paid = 1
                  AND NOT (return_type IN ('CANCEL', 'RETURN') AND return_status = 'COMPLETED')
                GROUP BY goods_id";
        $rows = $this->getDb()->select($sql, array_values($goodsIds));

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['goods_id']] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * 상품별 후기 수 배치 집계
     *
     * @param array $goodsIds 상품 ID 배열
     * @return array [goods_id => review_count]
     */
    public function getReviewCounts(array $goodsIds): array
    {
        if (empty($goodsIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($goodsIds), '?'));
        $sql = "SELECT goods_id, COUNT(*) AS cnt
                FROM shop_reviews
                WHERE goods_id IN ($placeholders)
                GROUP BY goods_id";
        $rows = $this->getDb()->select($sql, array_values($goodsIds));

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['goods_id']] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * 상품별 문의 수 배치 집계
     *
     * @param array $goodsIds 상품 ID 배열
     * @return array [goods_id => inquiry_count]
     */
    public function getInquiryCounts(array $goodsIds): array
    {
        if (empty($goodsIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($goodsIds), '?'));
        $sql = "SELECT goods_id, COUNT(*) AS cnt
                FROM shop_inquiries
                WHERE goods_id IN ($placeholders)
                GROUP BY goods_id";
        $rows = $this->getDb()->select($sql, array_values($goodsIds));

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['goods_id']] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * 상품별 평균 평점 배치 집계
     *
     * @param array $goodsIds 상품 ID 배열
     * @return array [goods_id => avg_rating(float)]
     */
    public function getRatingAverages(array $goodsIds): array
    {
        if (empty($goodsIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($goodsIds), '?'));
        $sql = "SELECT goods_id, AVG(rating) AS avg_rating
                FROM shop_reviews
                WHERE goods_id IN ($placeholders)
                GROUP BY goods_id";
        $rows = $this->getDb()->select($sql, array_values($goodsIds));

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['goods_id']] = (float) $row['avg_rating'];
        }

        return $map;
    }

    /**
     * 상품별 찜(위시리스트) 수 배치 집계
     *
     * @param array $goodsIds 상품 ID 배열
     * @return array [goods_id => wishlist_count]
     */
    public function getWishlistCounts(array $goodsIds): array
    {
        if (empty($goodsIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($goodsIds), '?'));
        $sql = "SELECT goods_id, COUNT(*) AS cnt
                FROM shop_wishlist
                WHERE goods_id IN ($placeholders)
                GROUP BY goods_id";
        $rows = $this->getDb()->select($sql, array_values($goodsIds));

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['goods_id']] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * 상품 이미지 목록 조회
     *
     * @param int $goodsId 상품 ID
     * @return array raw array 목록
     */
    public function getImages(int $goodsId): array
    {
        return $this->getDb()->table('shop_product_images')
            ->where('goods_id', '=', $goodsId)
            ->orderBy('sort_order', 'ASC')
            ->get();
    }

    /**
     * 상품 이미지 생성
     *
     * @param array $data 이미지 데이터
     * @return int 생성된 image_id
     */
    public function createImage(array $data): int
    {
        $data['created_at'] = date('Y-m-d H:i:s');

        return $this->getDb()->table('shop_product_images')->insert($data);
    }

    /**
     * 상품 이미지 삭제
     *
     * @param int $imageId 이미지 ID
     * @return bool 성공 여부
     */
    public function deleteImage(int $imageId): bool
    {
        $affected = $this->getDb()->table('shop_product_images')
            ->where('image_id', '=', $imageId)
            ->delete();

        return $affected > 0;
    }

    /**
     * 상품 이미지 일괄 삭제
     *
     * @param int $goodsId 상품 ID
     * @return int 삭제된 행 수
     */
    public function deleteImages(int $goodsId): int
    {
        return $this->getDb()->table('shop_product_images')
            ->where('goods_id', '=', $goodsId)
            ->delete();
    }

    /**
     * 상품 상세정보 일괄 삭제
     *
     * @param int $goodsId 상품 ID
     * @return int 삭제된 행 수
     */
    public function deleteDetails(int $goodsId): int
    {
        return $this->getDb()->table('shop_product_details')
            ->where('goods_id', '=', $goodsId)
            ->delete();
    }

    /**
     * 상품 상세정보 조회
     *
     * @param int $goodsId 상품 ID
     * @return array raw array 목록
     */
    public function getDetails(int $goodsId): array
    {
        return $this->getDb()->table('shop_product_details')
            ->where('goods_id', '=', $goodsId)
            ->get();
    }

    /**
     * 상품 상세정보 저장
     *
     * @param int $goodsId 상품 ID
     * @param array $data 상세정보 데이터
     * @return int 생성된 detail_id
     */
    public function saveDetail(int $goodsId, array $data): int
    {
        $data['goods_id'] = $goodsId;
        $data['created_at'] = date('Y-m-d H:i:s');

        return $this->getDb()->table('shop_product_details')->insert($data);
    }

    /**
     * ID 배열로 상품 조회 (블록 렌더링용)
     *
     * @param array $goodsIds 상품 ID 배열
     * @return array Product Entity 배열
     */
    public function findByIds(array $goodsIds): array
    {
        throw new \LogicException('Shop 상품 목록 조회에는 domainId가 필요합니다. findByIdsForDomain()을 사용하세요.');
    }

    /**
     * 현재 도메인의 활성 상품만 ID 배열로 조회한다 (블록 렌더링용).
     *
     * 반환 순서는 DB에 의존하지 않으며 호출자가 content_items 순서로 재정렬한다.
     *
     * @return Product[]
     */
    public function findByIdsForDomain(int $domainId, array $goodsIds): array
    {
        if (empty($goodsIds)) {
            return [];
        }

        $rows = $this->getDb()->table($this->table)
            ->whereIn($this->primaryKey, $goodsIds)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 블록 에디터용 상품 목록 조회 (활성 상품)
     *
     * @param int $domainId 도메인 ID
     * @return array raw 배열 목록
     */
    public function getActiveList(int $domainId): array
    {
        return $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    /**
     * 블록 에디터용 상품 목록 (페이징 + 필터)
     *
     * @param int $domainId 도메인 ID
     * @param array $filters ['category_code' => string, 'keyword' => string]
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @return array ['items' => raw[], 'pagination' => [...]]
     */
    public function getActiveListPaginated(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1);

        if (!empty($filters['category_codes'])) {
            $query->whereIn('category_code', $filters['category_codes']);
        } elseif (!empty($filters['category_code'])) {
            $query->where('category_code', '=', $filters['category_code']);
        }

        if (!empty($filters['keyword'])) {
            $query->where('goods_name', 'LIKE', '%' . $filters['keyword'] . '%');
        }

        $total = $query->count();

        $query->orderBy('created_at', 'DESC');
        $offset = ($page - 1) * $perPage;
        $rows = $query->limit($perPage)->offset($offset)->get();

        return [
            'items' => $rows,
            'pagination' => [
                'totalItems' => $total,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
            ],
        ];
    }

    /**
     * 현재 도메인 상품의 조회수 증가
     */
    public function incrementHit(int $domainId, int $goodsId): void
    {
        $this->getDb()->execute(
            "UPDATE {$this->table} SET hit = hit + 1 WHERE goods_id = ? AND domain_id = ?",
            [$goodsId, $domainId]
        );
    }

    /**
     * 재고 증감 (atomic)
     *
     * stock_quantity가 NULL(미관리)이면 무시.
     * 차감 시 0 미만으로 내려가지 않도록 GREATEST 사용.
     *
     * @param int $goodsId 상품 ID
     * @param int $delta 증감량 (양수=증가, 음수=차감)
     * @return int 영향받은 행 수
     */
    /**
     * 상품 재고 조회 (NONE 모드)
     *
     * @return int|null 재고 수량, 미관리(NULL)면 null
     */
    public function getStock(int $goodsId): ?int
    {
        $row = $this->getDb()->table($this->table)
            ->where('goods_id', '=', $goodsId)
            ->first();

        $stock = $row['stock_quantity'] ?? null;
        return ($stock === null || $stock === '') ? null : (int) $stock;
    }

    public function adjustStock(int $goodsId, int $delta): int
    {
        if ($delta >= 0) {
            return $this->getDb()->execute(
                "UPDATE {$this->table}
                 SET stock_quantity = stock_quantity + ?
                 WHERE goods_id = ? AND stock_quantity IS NOT NULL",
                [$delta, $goodsId]
            );
        }

        return $this->getDb()->execute(
            "UPDATE {$this->table}
             SET stock_quantity = stock_quantity + ?
             WHERE goods_id = ? AND stock_quantity IS NOT NULL AND stock_quantity + ? >= 0",
            [$delta, $goodsId, $delta]
        );
    }

    /**
     * 전체 검색용 상품 조회 (상품명/태그/뱃지 LIKE, 활성 상품만)
     *
     * @param int    $domainId 도메인 ID
     * @param string $keyword  검색 키워드
     * @param int    $limit    최대 결과 수
     * @return array [{title, url, summary, thumbnail, date, meta}]
     */
    public function searchByKeyword(int $domainId, string $keyword, int $limit = 5): array
    {
        $kw = '%' . $this->escapeLike($keyword) . '%';

        $rows = $this->getDb()->table($this->table)
            ->select(['goods_id', 'goods_name', 'goods_slug', 'goods_badge', 'goods_tags',
                      'display_price', 'created_at'])
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->whereRaw('(goods_name LIKE ? OR goods_tags LIKE ? OR goods_badge LIKE ?)', [$kw, $kw, $kw])
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();

        if (empty($rows)) {
            return [];
        }

        $goodsIds = array_map(static fn($r) => (int) $r['goods_id'], $rows);
        $images   = $this->getMainImages($goodsIds);

        $items = [];
        foreach ($rows as $row) {
            $gid = (int) $row['goods_id'];
            $img = $images[$gid] ?? null;
            $thumbnail = $img['thumbnail_url'] ?? $img['image_url'] ?? null;

            $url = '/shop/products/' . $gid;
            if (!empty($row['goods_slug'])) {
                $url .= '/' . rawurlencode($row['goods_slug']);
            }

            $items[] = [
                'title'     => $row['goods_name'] ?? '',
                'url'       => $url,
                'summary'   => isset($row['display_price']) ? number_format((int) $row['display_price']) . '원' : '',
                'thumbnail' => $thumbnail,
                'date'      => isset($row['created_at']) ? substr($row['created_at'], 0, 10) : null,
                'meta'      => '상품',
            ];
        }

        return $items;
    }

    /**
     * 전체 검색용 상품 개수 (searchByKeyword와 동일 조건)
     */
    public function countByKeyword(int $domainId, string $keyword): int
    {
        $kw = '%' . $this->escapeLike($keyword) . '%';

        return $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->whereRaw('(goods_name LIKE ? OR goods_tags LIKE ? OR goods_badge LIKE ?)', [$kw, $kw, $kw])
            ->count();
    }

    /**
     * LIKE 검색용 와일드카드 이스케이프
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * 생성 타임스탬프 필드명
     */
    protected function getCreatedAtField(): ?string
    {
        return 'created_at';
    }

    /**
     * 수정 타임스탬프 필드명
     */
    protected function getUpdatedAtField(): ?string
    {
        return 'updated_at';
    }
}
