<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\Shop\Entity\Order;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Repository\BaseRepository;

/**
 * Order Repository
 *
 * 주문 데이터베이스 접근 담당
 *
 * 책임:
 * - shop_orders 테이블 CRUD
 * - shop_order_details 테이블 관리
 * - Order Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class OrderRepository extends BaseRepository
{
    protected string $table = 'shop_orders';
    protected string $entityClass = Order::class;
    protected string $primaryKey = 'order_no';

    private string $detailsTable = 'shop_order_details';
    private string $logsTable = 'shop_order_logs';
    private string $returnsTable = 'shop_returns';
    private string $transactionsTable = 'shop_payment_transactions';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    /**
     * 주문 목록 조회 (관리자용, 페이지네이션)
     *
     * @param int $domainId 도메인 ID
     * @param array $filters 검색 조건 (member_id, order_status, date_from, date_to, keyword)
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @return array ['items' => Order[], 'pagination' => [...]]
     */
    public function getList(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId);

        // 회원 필터
        if (!empty($filters['member_id'])) {
            $query->where('member_id', '=', (int) $filters['member_id']);
        }

        // 주문 상태 필터
        if (!empty($filters['order_status'])) {
            $query->where('order_status', '=', $filters['order_status']);
        } else {
            // 내부 과도상태(attempting=결제 미완료)는 관리할 주문이 아니므로 기본 목록에서 제외.
            // (디버깅용으로 order_status=attempting 명시 조회는 위 분기로 허용)
            $query->where('order_status', '<>', 'attempting');
        }

        // 선택 주문번호 필터 (엑셀 내보내기 등 — 선택 건만 대상)
        if (!empty($filters['order_nos']) && is_array($filters['order_nos'])) {
            $orderNos = array_values(array_filter(array_map('strval', $filters['order_nos'])));
            if (!empty($orderNos)) {
                $query->whereIn('order_no', $orderNos);
            }
        }

        // 기간 필터
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        // 키워드 검색 (Blind Index + 주문번호)
        if (!empty($filters['keyword'])) {
            $orderNoPattern = '%' . $filters['keyword'] . '%';

            if (!empty($filters['keyword_index'])) {
                // 암호화 환경: Blind Index로 이름/연락처 정확 검색
                $idx = $filters['keyword_index'];
                $query->whereRaw(
                    '(recipient_name_index = ? OR orderer_name_index = ? '
                    . 'OR recipient_phone_index = ? OR orderer_phone_index = ? '
                    . 'OR order_no LIKE ?)',
                    [$idx, $idx, $idx, $idx, $orderNoPattern]
                );
            } else {
                // Blind Index 미생성 시 주문번호만 검색
                $query->whereRaw('(order_no LIKE ?)', [$orderNoPattern]);
            }
        }

        // 전체 개수
        $total = $query->count();

        // 정렬 및 페이지네이션 ($perPage <= 0 은 '전체' — limit 없이 조회)
        $query->orderBy('created_at', 'DESC');
        if ($perPage > 0) {
            $rows = $query->limit($perPage)->offset(($page - 1) * $perPage)->get();
        } else {
            $rows = $query->get();
        }

        return [
            'items' => $this->toEntities($rows),
            'pagination' => [
                'totalItems' => $total,
                'perPage' => $perPage > 0 ? $perPage : $total,
                'currentPage' => $perPage > 0 ? $page : 1,
                'totalPages' => $perPage > 0 ? ($total > 0 ? (int) ceil($total / $perPage) : 1) : 1,
            ],
        ];
    }

    /**
     * 주문 상세 아이템 조회
     *
     * @param string $orderNo 주문번호
     * @return array 주문 상세 목록 (raw arrays)
     */
    public function getItems(string $orderNo): array
    {
        return $this->getDb()->table($this->detailsTable)
            ->where('order_no', '=', $orderNo)
            ->orderBy('order_detail_id', 'ASC')
            ->get();
    }

    /**
     * 구매확정 주문의 "후기 미작성" 상품 목록 (관리자 후기 등록 피커).
     *
     * order_status='confirmed' 주문의 주문상세 중 shop_reviews에 아직 없는(NOT EXISTS) 항목만
     * 최신 주문 우선으로 페이지네이션한다. orderer_name은 암호화 원본이며 복호화는 서비스가 담당.
     *
     * @return array{items: array, pagination: array}
     */
    public function getReviewableConfirmedItems(int $domainId, string $keyword = '', int $page = 1, int $perPage = 12): array
    {
        $where = "o.domain_id = ? AND o.order_status = 'confirmed'"
            . " AND NOT EXISTS (SELECT 1 FROM shop_reviews rv WHERE rv.order_detail_id = d.order_detail_id)";
        $params = [$domainId];

        if ($keyword !== '') {
            $where .= " AND (d.order_no LIKE ? OR d.goods_name LIKE ?)";
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $total = (int) ($this->getDb()->selectOne(
            "SELECT COUNT(*) AS cnt
             FROM {$this->detailsTable} d
             INNER JOIN {$this->table} o ON o.order_no = d.order_no
             WHERE {$where}",
            $params
        )['cnt'] ?? 0);

        $offset = ($page - 1) * $perPage;
        $rows = $this->getDb()->select(
            "SELECT d.order_detail_id, d.order_no, d.goods_id, d.goods_name, d.goods_image,
                    d.option_name, d.quantity, o.member_id, o.orderer_name, o.created_at
             FROM {$this->detailsTable} d
             INNER JOIN {$this->table} o ON o.order_no = d.order_no
             WHERE {$where}
             ORDER BY o.created_at DESC, d.order_detail_id ASC
             LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );

        return [
            'items' => $rows,
            'pagination' => [
                'totalItems'  => $total,
                'perPage'     => $perPage,
                'currentPage' => $page,
                'totalPages'  => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * 자동 구매확정 대상 주문번호 조회.
     *
     * 배송중/배송완료 상태이면서, 최초 발송(shipping 전이) 시각이 cutoff 이전인 주문.
     * 발송 시각은 shop_order_logs의 new_status='shipping' 최초 기록으로 판정한다.
     *
     * @return string[] 주문번호 목록 (발송 오래된 순)
     */
    public function findAutoConfirmDue(int $domainId, string $cutoff, int $limit): array
    {
        $rows = $this->getDb()->select(
            "SELECT o.order_no
             FROM {$this->table} o
             INNER JOIN (
                 SELECT order_no, MIN(created_at) AS shipped_at
                 FROM {$this->logsTable}
                 WHERE new_status = ?
                 GROUP BY order_no
             ) l ON l.order_no = o.order_no
             WHERE o.domain_id = ?
               AND o.order_status IN (?, ?)
               AND l.shipped_at <= ?
             ORDER BY l.shipped_at ASC
             LIMIT ?",
            [
                OrderAction::SHIPPING->value,
                $domainId,
                OrderAction::SHIPPING->value,
                OrderAction::DELIVERED->value,
                $cutoff,
                $limit,
            ]
        );

        return array_map(static fn($r) => (string) $r['order_no'], $rows);
    }

    /**
     * 주문에 포함된 원본 장바구니 아이템 ID 목록
     *
     * @return int[]
     */
    public function getCartItemIds(string $orderNo): array
    {
        $rows = $this->getDb()->table($this->detailsTable)
            ->select(['cart_item_id'])
            ->where('order_no', '=', $orderNo)
            ->get();

        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['cart_item_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 주문 헤더 단건 조회 (order_no 기준)
     *
     * @param string $orderNo 주문번호
     * @return array|null 주문 헤더 (raw array)
     */
    public function findByOrderNo(string $orderNo): ?array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('order_no', '=', $orderNo)
            ->limit(1)
            ->get();

        return $rows[0] ?? null;
    }

    /** 현재 도메인의 주문번호로 주문 헤더를 조회한다. */
    public function findByOrderNoInDomain(int $domainId, string $orderNo): ?array
    {
        $row = $this->getDb()->table($this->table)
            ->where('order_no', '=', $orderNo)
            ->where('domain_id', '=', $domainId)
            ->first();

        return $row ?: null;
    }

    /**
     * 고객 프론트에 노출 가능한 주문인지 확인
     */
    public function isCustomerVisible(string $orderNo): bool
    {
        if ($orderNo === '') {
            return false;
        }

        $query = $this->getDb()->table($this->table)
            ->where('order_no', '=', $orderNo);
        $this->applyCustomerVisibleScope($query);

        return $query->count() > 0;
    }

    /**
     * 주문번호 목록으로 헤더 다건 조회 (비회원 세션 주문 목록 표시용)
     *
     * @param string[] $orderNos
     * @return Order[] 최신순
     */
    public function findByOrderNos(array $orderNos, bool $customerVisibleOnly = false): array
    {
        if (empty($orderNos)) {
            return [];
        }

        $query = $this->getDb()->table($this->table)
            ->whereIn('order_no', $orderNos);

        if ($customerVisibleOnly) {
            $this->applyCustomerVisibleScope($query);
        }

        $rows = $query
            ->orderBy('created_at', 'DESC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 비회원 주문 조회 후보 (주문인명 Blind Index 기준)
     *
     * 비회원 주문(member_id = 0) 중 주문인명이 일치하는 후보를 반환한다.
     * 연락처 대조(복호화 후 숫자 비교)는 Service 책임이므로 여기서는 후보 raw row만 반환.
     *
     * @param string $nameIndex 주문인명 Blind Index
     * @param int $limit 후보 상한 (동명이인 방어)
     * @return array<int,array> raw 주문 헤더 목록
     */
    public function findGuestByNameIndex(string $nameIndex, int $limit = 100, bool $customerVisibleOnly = false): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('member_id', '=', 0)
            ->where('orderer_name_index', '=', $nameIndex);

        if ($customerVisibleOnly) {
            $this->applyCustomerVisibleScope($query);
        }

        return $query
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * 주문 생성
     *
     * @param array $orderData 주문 데이터 (order_no 포함)
     * @return string 주문번호
     */
    public function createOrder(array $orderData): string
    {
        if (empty($orderData['order_no'])) {
            $orderData['order_no'] = $this->generateOrderNo();
        }

        if (!isset($orderData['created_at'])) {
            $orderData['created_at'] = date('Y-m-d H:i:s');
        }

        $this->getDb()->table($this->table)->insert($orderData);

        return $orderData['order_no'];
    }

    /**
     * 주문 상세 아이템 생성
     *
     * @param array $data 주문 상세 데이터
     * @return int 생성된 order_detail_id
     */
    public function createOrderItem(array $data): int
    {
        if (!isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        return $this->getDb()->table($this->detailsTable)->insert($data);
    }

    /**
     * 주문 상태 변경
     *
     * @param string $orderNo 주문번호
     * @param string $status 변경할 상태
     * @return bool 성공 여부
     */
    /**
     * 주문 상태 업데이트
     *
     * @param string $orderNo 주문번호
     * @param string $newStatus 변경할 상태
     * @param string|null $expectedStatus 현재 상태 검증 (null이면 무조건 업데이트)
     *   더블클릭/재요청 방어: DB 레벨에서 현재 상태가 기대값과 다르면 affected=0
     */
    public function updateStatus(string $orderNo, string $newStatus, ?string $expectedStatus = null): bool
    {
        $query = $this->getDb()->table($this->table)
            ->where('order_no', '=', $orderNo);

        if ($expectedStatus !== null) {
            $query->where('order_status', '=', $expectedStatus);
        }

        $affected = $query->update([
            'order_status' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $affected > 0;
    }

    /**
     * 결제 수단 업데이트 (결제 완료 후 PG 실제 수단으로 갱신)
     */
    public function updatePaymentMethod(string $orderNo, string $paymentMethod): void
    {
        $this->getDb()->table($this->table)
            ->where('order_no', '=', $orderNo)
            ->update([
                'payment_method' => $paymentMethod,
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * 결제 준비 시점의 PG 거래번호 기록
     *
     * "이 거래번호로 결제를 요청했다"는 사실만 남긴다 — 승인 여부와 무관하다.
     * webhook 유실·결제창 이탈로 검증 전에 흐름이 끊겨도 PG 에 조회할 키가 남는다.
     */
    public function updatePaymentTid(string $orderNo, string $transactionId): void
    {
        if ($transactionId === '') {
            return;
        }

        $this->getDb()->table($this->table)
            ->where('order_no', '=', $orderNo)
            ->update([
                'payment_tid' => $transactionId,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * 회원별 주문 목록 조회 (페이지네이션)
     *
     * @param int $memberId 회원 ID
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @return array ['items' => Order[], 'pagination' => [...]]
     */
    public function getByMember(int $domainId, int $memberId, int $page = 1, int $perPage = 20, string $keyword = ''): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('member_id', '=', $memberId);

        $this->applyCustomerVisibleScope($query);

        // 상품명 검색 (공백 분리 AND): 매칭되는 주문번호 집합으로 제한
        if ($keyword !== '') {
            $tokens = preg_split('/\s+/', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (!empty($tokens)) {
                $detailsQuery = $this->getDb()->table($this->detailsTable);
                foreach ($tokens as $token) {
                    $detailsQuery->where('goods_name', 'LIKE', '%' . $token . '%');
                }
                $matchRows = $detailsQuery->get();
                $orderNos = array_values(array_unique(array_filter(
                    array_map(fn($r) => (string) ($r['order_no'] ?? ''), $matchRows),
                    fn($n) => $n !== ''
                )));
                if (empty($orderNos)) {
                    return [
                        'items' => [],
                        'pagination' => [
                            'totalItems' => 0,
                            'perPage' => $perPage,
                            'currentPage' => $page,
                            'totalPages' => 1,
                        ],
                    ];
                }
                $query->whereIn('order_no', $orderNos);
            }
        }

        // 전체 개수
        $total = $query->count();

        // 정렬 및 페이지네이션
        $offset = ($page - 1) * $perPage;
        $rows = $query
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

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
     * 고객 프론트에 노출할 주문만 남긴다.
     *
     * PG 결제는 결제창을 띄우기 위해 주문을 먼저 만들지만(내부 과도상태
     * attempting), 고객이 결제를 취소·이탈하면 그대로 남는다. 이런 미결제
     * 주문은 운영 기록으로만 보존하고 고객 주문내역에서는 숨긴다.
     *
     * 무통장입금은 received(주문접수)로 출생하므로 정상 노출된다.
     * attempting이라도 성공 PAYMENT 트랜잭션이 있으면 결제완료-상태전이 실패
     * (결제-상태 불일치)일 수 있으므로 숨기지 않는다.
     */
    private function applyCustomerVisibleScope($query): void
    {
        $query->whereRaw(
            "NOT (
                order_status = ?
                AND NOT EXISTS (
                    SELECT 1
                    FROM {$this->transactionsTable} pt
                    WHERE pt.order_no = {$this->table}.order_no
                      AND pt.transaction_type = ?
                      AND pt.transaction_status = ?
                )
            )",
            ['attempting', 'PAYMENT', 'SUCCESS']
        );
    }

    /**
     * 만료된 결제시도(attempting) 고아 주문 하드 삭제
     *
     * 결제창만 띄우고 이탈한 미결제 주문을 컷오프 경과 후 정리한다.
     * 안전장치(필수): 성공 PAYMENT 트랜잭션이 있는 건(결제됐는데 활성화 누락된 경우)은
     * 절대 삭제하지 않는다. 결제 진행 중 레이스를 피하려고 created_at 컷오프는 PG 결제창
     * 만료보다 충분히 길게 둔다. 이 단계에선 실주문이 된 적 없는 pre-payment 레코드만
     * 지우므로(재고·쿠폰·포인트 부수효과는 PAID 시점에 발생) 하드 삭제가 안전하다.
     *
     * @param string $cutoff 'Y-m-d H:i:s' — 이 시각 이전 생성분만 대상
     * @param int $limit 회당 최대 삭제 건수 (백로그 폭주 시 페이지 지연 상한 보장)
     * @return int 삭제된 주문 건수
     */
    public function deleteStaleAttempting(int $domainId, string $cutoff, int $limit = 200): int
    {
        return $this->deleteByOrderNos($this->findStaleAttemptingOrderNos($domainId, $cutoff, $limit));
    }

    /**
     * 지정한 주문번호들의 주문·상세·로그를 하드 삭제.
     *
     * 스윕에서 '복원 대상으로 조회한 바로 그 목록'을 그대로 삭제하는 데 쓴다.
     * (복원용/삭제용 조회를 따로 돌리면 LIMIT·정렬 차이로 대상이 발산해 일부 주문이 쿠폰 복원을
     *  건너뛸 수 있으므로, 조회는 한 번만 하고 그 결과를 이 메서드에 넘긴다.)
     *
     * @param list<string> $orderNos
     * @return int 삭제된 주문 건수
     */
    public function deleteByOrderNos(array $orderNos): int
    {
        if (empty($orderNos)) {
            return 0;
        }

        $db = $this->getDb();
        $placeholders = implode(',', array_fill(0, count($orderNos), '?'));
        // 자식(상세·로그) 먼저, 부모(주문) 나중 — FK 유무와 무관하게 안전한 순서
        foreach ([$this->detailsTable, $this->logsTable, $this->table] as $t) {
            $db->execute("DELETE FROM `{$t}` WHERE order_no IN ({$placeholders})", $orderNos);
        }

        return count($orderNos);
    }

    /**
     * 삭제 대상 attempting 고아 주문번호 조회 (삭제는 하지 않음).
     *
     * 스윕이 하드 삭제하기 전에, 이 주문들에 사용된 쿠폰을 복원할 수 있도록 대상 목록을 노출한다.
     * 조건은 deleteStaleAttempting 과 동일 — 성공 PAYMENT 가 있는 건은 제외한다.
     *
     * @return list<string> 주문번호 목록
     */
    public function findStaleAttemptingOrderNos(int $domainId, string $cutoff, int $limit = 200): array
    {
        $db = $this->getDb();
        $limit = max(1, $limit);
        $rows = $db->select(
            "SELECT order_no FROM {$this->table}
             WHERE domain_id = ?
               AND order_status = ?
               AND created_at < ?
               AND NOT EXISTS (
                   SELECT 1 FROM {$this->transactionsTable} pt
                   WHERE pt.order_no = {$this->table}.order_no
                     AND pt.transaction_type = ?
                     AND pt.transaction_status = ?
               )
             LIMIT {$limit}",
            [$domainId, 'attempting', $cutoff, 'PAYMENT', 'SUCCESS']
        );

        return array_map(static fn($r) => (string) $r['order_no'], $rows);
    }

    /**
     * 여러 주문의 상세 아이템 조회 (주문번호 기준 그룹핑)
     *
     * @param array $orderNos 주문번호 배열
     * @return array [order_no => items[]] 형태
     */
    public function getItemsByOrderNos(array $orderNos): array
    {
        if (empty($orderNos)) {
            return [];
        }

        $rows = $this->getDb()->table($this->detailsTable)
            ->whereIn('order_no', $orderNos)
            ->orderBy('order_detail_id', 'ASC')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $no = $row['order_no'] ?? '';
            $grouped[$no][] = $row;
        }

        return $grouped;
    }

    // ===== 주문 로그 =====

    /**
     * 주문 로그 기록
     */
    public function insertOrderLog(array $data): int
    {
        if (!isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        return $this->getDb()->table($this->logsTable)->insert($data);
    }

    /**
     * 주문 로그 조회 (최신순)
     */
    public function getOrderLogs(string $orderNo): array
    {
        return $this->getDb()->table($this->logsTable)
            ->where('order_no', '=', $orderNo)
            ->orderBy('created_at', 'DESC')
            ->orderBy('log_id', 'DESC') // 같은 초 동률 타이브레이크: 삽입순서(log_id)로 인과 보존
            ->get();
    }

    // ===== 아이템 관리 =====

    /**
     * 주문 상세 아이템 단건 조회
     */
    public function getItem(int $detailId): ?array
    {
        $rows = $this->getDb()->table($this->detailsTable)
            ->where('order_detail_id', '=', $detailId)
            ->limit(1)
            ->get();

        return $rows[0] ?? null;
    }

    /** 현재 도메인의 주문에 속한 상세 아이템만 조회한다. */
    public function getItemInDomain(int $domainId, int $detailId): ?array
    {
        return $this->getDb()->selectOne(
            "SELECT d.*
             FROM {$this->detailsTable} d
             INNER JOIN {$this->table} o ON o.order_no = d.order_no
             WHERE d.order_detail_id = ? AND o.domain_id = ?",
            [$detailId, $domainId]
        ) ?: null;
    }

    /**
     * 아이템 상태 변경
     */
    public function updateItemStatus(int $detailId, string $status): bool
    {
        $affected = $this->getDb()->table($this->detailsTable)
            ->where('order_detail_id', '=', $detailId)
            ->update([
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $affected > 0;
    }

    /**
     * 아이템 반품 정보 변경
     */
    public function updateItemReturn(int $detailId, string $returnType, string $returnStatus): bool
    {
        $affected = $this->getDb()->table($this->detailsTable)
            ->where('order_detail_id', '=', $detailId)
            ->update([
                'return_type' => $returnType,
                'return_status' => $returnStatus,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $affected > 0;
    }

    /**
     * 아이템 플래그 업데이트 (is_paid, is_preparing, is_shipped, is_completed)
     */
    public function updateItemFlags(int $detailId, array $flags): bool
    {
        $allowed = ['is_paid', 'is_preparing', 'is_shipped', 'is_completed', 'stock_deducted'];
        $data = array_intersect_key($flags, array_flip($allowed));
        if (empty($data)) {
            return false;
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        $affected = $this->getDb()->table($this->detailsTable)
            ->where('order_detail_id', '=', $detailId)
            ->update($data);

        return $affected > 0;
    }

    // ===== 반품 관리 (shop_returns) =====

    /**
     * 반품 레코드 생성
     */
    public function createReturn(array $data): int
    {
        if (!isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        return $this->getDb()->table($this->returnsTable)->insert($data);
    }

    /**
     * 반품 단건 조회 (order_detail_id 기준, 최신)
     */
    public function getReturnByDetailId(int $detailId): ?array
    {
        $rows = $this->getDb()->table($this->returnsTable)
            ->where('order_detail_id', '=', $detailId)
            ->orderBy('created_at', 'DESC')
            ->limit(1)
            ->get();

        return $rows[0] ?? null;
    }

    /**
     * 주문의 반품 목록 조회
     */
    public function getReturnsByOrderNo(string $orderNo): array
    {
        return $this->getDb()->table($this->returnsTable)
            ->where('order_no', '=', $orderNo)
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    /**
     * 반품 상태/정보 업데이트
     */
    public function updateReturn(int $returnId, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        $affected = $this->getDb()->table($this->returnsTable)
            ->where('return_id', '=', $returnId)
            ->update($data);

        return $affected > 0;
    }

    /**
     * 주문번호 생성
     *
     * YYYYMMDD + 6자리 랜덤 숫자
     *
     * @return string 주문번호 (예: 20260207123456)
     */
    public function generateOrderNo(): string
    {
        return date('Ymd') . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 생성 타임스탬프 필드명
     *
     * BaseRepository의 create()에서 자동 추가하지 않도록 null 반환
     * (createOrder에서 직접 관리)
     */
    protected function getCreatedAtField(): ?string
    {
        return 'created_at';
    }

    /**
     * 회원의 완료된 주문 존재 여부 확인 (쿠폰 첫 주문 검증용)
     */
    public function hasCompletedOrder(int $memberId): bool
    {
        return $this->getDb()->table($this->table)
            ->where('member_id', '=', $memberId)
            ->whereIn('order_status', OrderAction::paidOrBeyondValues())
            ->count() > 0;
    }

    // ── 대시보드 통계 ────────────────────────────────────────────────────────

    /**
     * 기간 내 매출 합계 (결제 완료 이후 상태 기준)
     *
     * shop_orders에는 final_price/is_paid 컬럼이 없으므로
     * 합산은 PriceCalculator 공식과 동일하게 컬럼 합으로 계산하고,
     * 필터는 order_status로 대체.
     */
    public function sumRevenue(int $domainId, string $startDate, string $endDate): int
    {
        $statuses = OrderAction::paidOrBeyondValues();
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $row = $this->getDb()->selectOne(
            "SELECT COALESCE(SUM(total_price + shipping_fee + extra_price + tax_amount - point_used - coupon_discount), 0) AS total
             FROM {$this->table}
             WHERE domain_id = ?
               AND order_status IN ({$placeholders})
               AND DATE(created_at) BETWEEN ? AND ?",
            [$domainId, ...$statuses, $startDate, $endDate]
        );
        return (int) ($row['total'] ?? 0);
    }

    /**
     * 기간 내 주문 수 (전체 주문 기준)
     */
    public function countByDateRange(int $domainId, string $startDate, string $endDate): int
    {
        $row = $this->getDb()->selectOne(
            "SELECT COUNT(*) AS cnt
             FROM {$this->table}
             WHERE domain_id = ? AND order_status <> 'attempting'
               AND DATE(created_at) BETWEEN ? AND ?",
            [$domainId, $startDate, $endDate]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * 지정 상태들의 주문 수 합계
     */
    public function countByStatuses(int $domainId, array $statuses): int
    {
        if (empty($statuses)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $row = $this->getDb()->selectOne(
            "SELECT COUNT(*) AS cnt
             FROM {$this->table}
             WHERE domain_id = ? AND order_status IN ({$placeholders})",
            [$domainId, ...$statuses]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * 상태별 주문 수 (대시보드 현황판용)
     *
     * @return array [['order_status' => ..., 'cnt' => ...], ...]
     */
    public function countGroupByStatus(int $domainId): array
    {
        return $this->getDb()->select(
            "SELECT order_status, COUNT(*) AS cnt
             FROM {$this->table}
             WHERE domain_id = ? AND order_status <> 'attempting'
             GROUP BY order_status",
            [$domainId]
        );
    }

    /**
     * 최근 주문 목록 (복호화되지 않은 원본 rows)
     */
    public function getRecentOrders(int $domainId, int $limit = 10): array
    {
        // 주문 기본 정보 (PII인 orderer_name은 대시보드에서 표시하지 않음 — 상품으로 식별)
        $rows = $this->getDb()->select(
            "SELECT order_no, member_id,
                    (total_price + shipping_fee + extra_price + tax_amount - point_used - coupon_discount) AS final_price,
                    order_status, created_at
             FROM {$this->table}
             WHERE domain_id = ? AND order_status <> 'attempting'
             ORDER BY created_at DESC
             LIMIT ?",
            [$domainId, $limit]
        );

        if (empty($rows)) {
            return [];
        }

        // 각 주문의 대표 상품(첫 아이템) + 상품 건수
        $orderNos = array_map(static fn($r) => $r['order_no'], $rows);
        $itemsMap = $this->getItemsByOrderNos($orderNos);
        foreach ($rows as &$r) {
            $items = $itemsMap[$r['order_no']] ?? [];
            $r['first_item_name'] = $items[0]['goods_name'] ?? null;
            $r['item_count']      = count($items);
        }
        unset($r);

        return $rows;
    }

    /**
     * 기간 내 판매 상위 상품
     *
     * @return array [['goods_id' => ..., 'goods_name' => ..., 'total_qty' => ..., 'total_revenue' => ...], ...]
     */
    public function getTopProducts(int $domainId, string $startDate, string $endDate, int $limit = 5): array
    {
        $statuses = OrderAction::paidOrBeyondValues();
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        return $this->getDb()->select(
            "SELECT d.goods_id, d.goods_name,
                    SUM(d.quantity) AS total_qty,
                    SUM(d.total_price) AS total_revenue
             FROM shop_order_details d
             INNER JOIN {$this->table} o ON o.order_no = d.order_no
             WHERE o.domain_id = ?
               AND o.order_status IN ({$placeholders})
               AND DATE(o.created_at) BETWEEN ? AND ?
             GROUP BY d.goods_id, d.goods_name
             ORDER BY total_revenue DESC
             LIMIT ?",
            [$domainId, ...$statuses, $startDate, $endDate, $limit]
        );
    }
}
