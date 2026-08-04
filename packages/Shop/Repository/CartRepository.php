<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\Shop\Entity\CartItem;
use Mublo\Repository\BaseRepository;

/**
 * Cart Repository
 *
 * 장바구니 데이터베이스 접근 담당
 *
 * 책임:
 * - shop_carts 테이블 CRUD
 * - shop_cart_fees 테이블 관리
 * - CartItem Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class CartRepository extends BaseRepository
{
    protected string $table = 'shop_carts';
    protected string $entityClass = CartItem::class;
    protected string $primaryKey = 'cart_item_id';

    private string $feesTable = 'shop_cart_fees';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    public function findInDomain(int $domainId, int $cartItemId): ?CartItem
    {
        $row = $this->getDb()->table($this->table)
            ->where($this->primaryKey, '=', $cartItemId)
            ->where('domain_id', '=', $domainId)
            ->first();

        return $row ? $this->toEntity($row) : null;
    }

    /**
     * 장바구니 아이템 조회
     *
     * 세션 또는 회원 기준으로 PENDING 상태인 아이템 목록 조회
     *
     * @param string $sessionId 장바구니 세션 ID
     * @param int $memberId 회원 ID
     * @return CartItem[]
     */
    public function getItems(string $sessionId, int $memberId, int $domainId): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('cart_status', '=', 'PENDING')
            ->orderBy('cart_item_id', 'ASC');

        if ($memberId > 0) {
            $query->whereRaw('(cart_session_id = ? OR member_id = ?)', [$sessionId, $memberId]);
        } else {
            $query->where('cart_session_id', '=', $sessionId);
        }

        $rows = $query->get();

        return $this->toEntities($rows);
    }

    /**
     * 장바구니 아이템 추가
     *
     * @param array $data 아이템 데이터
     * @return int 생성된 cart_item_id
     */
    public function addItem(array $data): int
    {
        return $this->create($data);
    }

    /**
     * 장바구니 아이템 수량 변경
     *
     * 수량 변경 시 total_price 도 재계산
     *
     * @param int $cartItemId 장바구니 아이템 ID
     * @param int $quantity 변경할 수량
     * @return bool 성공 여부
     */
    public function updateQuantity(int $cartItemId, int $quantity, int $domainId): bool
    {
        // 현재 아이템 조회하여 단가 확인
        $item = $this->findInDomain($domainId, $cartItemId);
        if (!$item) {
            return false;
        }

        $unitPrice = $item->getGoodsPrice() + $item->getOptionPrice();
        $totalPrice = $unitPrice * $quantity;

        $affected = $this->getDb()->table($this->table)
            ->where('cart_item_id', '=', $cartItemId)
            ->where('domain_id', '=', $domainId)
            ->update([
                'quantity' => $quantity,
                'total_price' => $totalPrice,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $affected > 0;
    }

    /**
     * 장바구니 아이템 본가(goods_price) 갱신
     *
     * 가격변동 항목을 현재 판매가로 "다시 담기" 할 때 사용.
     * 옵션 추가금(option_price)·수량은 유지하고 total_price만 재계산.
     *
     * @param int $cartItemId 장바구니 아이템 ID
     * @param int $goodsPrice 갱신할 상품 본가(현재 판매가)
     * @return bool 성공 여부
     */
    public function updatePrice(int $cartItemId, int $goodsPrice, int $domainId): bool
    {
        $item = $this->findInDomain($domainId, $cartItemId);
        if (!$item) {
            return false;
        }

        $totalPrice = ($goodsPrice + $item->getOptionPrice()) * $item->getQuantity();

        $affected = $this->getDb()->table($this->table)
            ->where('cart_item_id', '=', $cartItemId)
            ->where('domain_id', '=', $domainId)
            ->update([
                'goods_price' => $goodsPrice,
                'total_price' => $totalPrice,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $affected > 0;
    }

    /**
     * 장바구니 아이템 삭제
     *
     * @param int $cartItemId 장바구니 아이템 ID
     * @return bool 성공 여부
     */
    public function removeItem(int $cartItemId, int $domainId): bool
    {
        $affected = $this->getDb()->table($this->table)
            ->where('cart_item_id', '=', $cartItemId)
            ->where('domain_id', '=', $domainId)
            ->delete();
        return $affected > 0;
    }

    /**
     * 동일 상품+옵션 장바구니 아이템 찾기 (중복 처리용)
     *
     * @param string $sessionId 장바구니 세션 ID
     * @param int $memberId 회원 ID
     * @param int $goodsId 상품 ID
     * @param int $optionId 옵션 ID (0이면 옵션 없음)
     * @param string|null $optionCode 옵션 코드
     * @return int|null 존재하면 cart_item_id, 없으면 null
     */
    public function findDuplicate(
        string $sessionId,
        int $memberId,
        int $domainId,
        int $goodsId,
        int $optionId,
        ?string $optionCode
    ): ?int
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('goods_id', '=', $goodsId)
            ->where('option_id', '=', $optionId)
            ->where('cart_status', '=', 'PENDING');

        if ($memberId > 0) {
            $query->whereRaw('(cart_session_id = ? OR member_id = ?)', [$sessionId, $memberId]);
        } else {
            $query->where('cart_session_id', '=', $sessionId);
        }

        if ($optionCode !== null) {
            $query->where('option_code', '=', $optionCode);
        } else {
            $query->whereNull('option_code');
        }

        $row = $query->first();
        return $row ? (int) $row['cart_item_id'] : null;
    }

    /**
     * 세션 기준 PENDING 아이템 전체 삭제
     *
     * @param string $sessionId 장바구니 세션 ID
     * @return int 삭제된 행 수
     */
    public function removeBySession(string $sessionId): int
    {
        return $this->getDb()->table($this->table)
            ->where('cart_session_id', '=', $sessionId)
            ->where('cart_status', '=', 'PENDING')
            ->delete();
    }

    /**
     * 세션 기준 PENDING 아이템을 ORDERED로 변경
     *
     * @param string $sessionId 장바구니 세션 ID
     * @return int 변경된 행 수
     */
    public function markOrdered(string $sessionId): int
    {
        return $this->getDb()->table($this->table)
            ->where('cart_session_id', '=', $sessionId)
            ->where('cart_status', '=', 'PENDING')
            ->update([
                'cart_status' => 'ORDERED',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * 특정 cart_item_id들만 ORDERED로 변경
     *
     * 부분 주문 시 해당 아이템만 ORDERED로 전환
     *
     * @param array $cartItemIds cart_item_id 배열
     * @return int 변경된 행 수
     */
    public function markOrderedByIds(array $cartItemIds, string $sessionId = ''): int
    {
        if (empty($cartItemIds)) {
            return 0;
        }

        $query = $this->getDb()->table($this->table)
            ->whereIn('cart_item_id', $cartItemIds)
            ->where('cart_status', '=', 'PENDING');

        if ($sessionId !== '') {
            $query->where('cart_session_id', '=', $sessionId);
        }

        return $query->update([
            'cart_status' => 'ORDERED',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 장바구니 부가비용 조회
     *
     * @param string $sessionId 장바구니 세션 ID
     * @return array 부가비용 목록 (raw arrays)
     */
    public function getFees(string $sessionId): array
    {
        return $this->getDb()->table($this->feesTable)
            ->where('cart_session_id', '=', $sessionId)
            ->get();
    }

    /**
     * 특정 상품의 장바구니 항목 전체 삭제
     *
     * 옵션 수정 시 기존 옵션 전체 삭제 → 새 옵션 재저장에 사용
     *
     * @param string $sessionId 장바구니 세션 ID
     * @param int $memberId 회원 ID
     * @param int $goodsId 상품 ID
     * @return int 삭제된 행 수
     */
    public function removeByGoodsId(string $sessionId, int $memberId, int $goodsId): int
    {
        $query = $this->getDb()->table($this->table)
            ->where('goods_id', '=', $goodsId)
            ->where('cart_status', '=', 'PENDING');

        if ($memberId > 0) {
            $query->whereRaw('(cart_session_id = ? OR member_id = ?)', [$sessionId, $memberId]);
        } else {
            $query->where('cart_session_id', '=', $sessionId);
        }

        return $query->delete();
    }

    /**
     * 배송비 정보 저장 (upsert)
     *
     * shop_cart_fees UNIQUE KEY (cart_session_id, goods_id) 활용
     * 동일 세션+상품 존재 시 삭제 후 재삽입
     *
     * @param array $data 배송비 데이터
     * @return int 생성된 fee_id
     */
    public function saveShippingFee(array $data): int
    {
        if (!empty($data['cart_session_id']) && !empty($data['goods_id'])) {
            $this->getDb()->table($this->feesTable)
                ->where('cart_session_id', '=', $data['cart_session_id'])
                ->where('goods_id', '=', (int) $data['goods_id'])
                ->delete();
        }

        return $this->getDb()->table($this->feesTable)->insert($data);
    }

    /**
     * 특정 상품의 배송비 정보 삭제
     *
     * @param string $sessionId 장바구니 세션 ID
     * @param int $goodsId 상품 ID
     * @return int 삭제된 행 수
     */
    public function removeShippingFee(string $sessionId, int $goodsId): int
    {
        return $this->getDb()->table($this->feesTable)
            ->where('cart_session_id', '=', $sessionId)
            ->where('goods_id', '=', $goodsId)
            ->delete();
    }
}
