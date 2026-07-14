<?php

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Packages\Shop\Entity\CouponPolicy;
use Mublo\Repository\BaseRepository;

/**
 * Coupon Repository
 *
 * 쿠폰 정책/발행 데이터베이스 접근 담당
 *
 * 책임:
 * - shop_coupon_group 테이블 CRUD (쿠폰 정책)
 * - shop_coupon_issue 테이블 관리 (쿠폰 발행/사용)
 * - CouponPolicy Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class CouponRepository extends BaseRepository
{
    protected string $table = 'shop_coupon_group';
    protected string $entityClass = CouponPolicy::class;
    protected string $primaryKey = 'coupon_group_id';

    private string $issueTable = 'shop_coupon_issue';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 쿠폰 정책 목록 조회 (페이지네이션)
     *
     * @param int $domainId 도메인 ID
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @return array ['items' => CouponPolicy[], 'pagination' => [...]]
     */
    public function getList(int $domainId, int $page = 1, int $perPage = 20): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId);

        // 전체 개수
        $total = $query->count();

        // 정렬 및 페이지네이션
        $offset = ($page - 1) * $perPage;
        $rows = $query
            ->orderBy('coupon_group_id', 'DESC')
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
     * 여러 쿠폰 정책의 발급된 쿠폰 수를 배치 집계.
     *
     * @param int[] $ids coupon_group_id 목록
     * @return array<int, int> [coupon_group_id => 발급 수]
     */
    public function getIssuedCountsByGroupIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->select(
            "SELECT coupon_group_id, COUNT(*) AS cnt
             FROM {$this->issueTable}
             WHERE coupon_group_id IN ({$placeholders})
             GROUP BY coupon_group_id",
            array_values($ids)
        );

        $counts = [];
        foreach ($rows as $r) {
            $counts[(int) $r['coupon_group_id']] = (int) $r['cnt'];
        }

        return $counts;
    }

    /**
     * 활성 쿠폰 정책 조회
     *
     * @param int $domainId 도메인 ID
     * @return CouponPolicy[]
     */
    public function getActivePolicies(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->orderBy('coupon_group_id', 'DESC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 자동 발행 대상 쿠폰 정책 조회
     *
     * @param int $domainId 도메인 ID
     * @param string $trigger 트리거 종류 (JOIN, LEVEL, LOGIN, BIRTH)
     * @return CouponPolicy[]
     */
    public function getAutoIssuePolicies(int $domainId, string $trigger): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->where('coupon_type', '=', 'AUTO')
            ->where('auto_issue_trigger', '=', $trigger)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 다운로드 가능한 쿠폰 정책 조회 (DOWNLOAD 유형, 활성)
     *
     * @return CouponPolicy[]
     */
    public function getDownloadablePolicies(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->where('coupon_type', '=', 'DOWNLOAD')
            ->orderBy('coupon_group_id', 'DESC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 회원 쿠폰 + 정책 JOIN 조회 (적용 가능 쿠폰 판단용)
     */
    public function getMemberCouponsWithPolicy(int $memberId): array
    {
        $sql = "SELECT ci.*, cg.name, cg.coupon_method, cg.discount_type, cg.discount_value,
                       cg.max_discount, cg.min_order_amount, cg.duplicate_policy,
                       cg.use_limit_per_member, cg.allowed_member_levels, cg.first_order_only
                FROM {$this->issueTable} ci
                JOIN {$this->table} cg ON cg.coupon_group_id = ci.coupon_group_id
                WHERE ci.member_id = ? AND ci.status = 'ISSUED' AND ci.is_used = 0
                ORDER BY ci.valid_until ASC";

        return $this->getDb()->select($sql, [$memberId]);
    }

    /**
     * 쿠폰 발행 (shop_coupon_issue에 삽입)
     *
     * @param array $data 발행 데이터 (coupon_group_id, member_id, coupon_number, issued_at, valid_until 등)
     * @return int 생성된 coupon_id
     */
    public function issueCoupon(array $data): int
    {
        if (!isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        return $this->getDb()->table($this->issueTable)->insert($data);
    }

    /**
     * 발행 쿠폰 단일 조회 (coupon_id 기준)
     *
     * @param int $couponId 쿠폰 ID
     * @return array|null 쿠폰 데이터
     */
    public function findIssuedCoupon(int $couponId): ?array
    {
        return $this->getDb()->table($this->issueTable)
            ->where('coupon_id', '=', $couponId)
            ->first();
    }

    /**
     * 회원 쿠폰함 탭별 목록 조회
     *
     * 쿠폰함은 사용가능/사용완료/기간만료 탭으로 나뉜다. 만료 판정은 status='EXPIRED'에만
     * 의존하지 않고 valid_until로도 한다 — 만료 일괄 스윕(expireOverdueCoupons)이 상시
     * 도는 보장이 없어, 유효기간이 지났지만 아직 ISSUED인 쿠폰이 존재할 수 있기 때문이다.
     *
     * @param int $memberId 회원 ID
     * @param string $tab available|used|expired
     * @return array 쿠폰 목록 (raw arrays)
     */
    public function getMemberCouponsByTab(int $memberId, string $tab): array
    {
        $params = [$memberId];

        switch ($tab) {
            case 'used':
                // 사용 완료 — 사용 처리된 쿠폰 (만료 여부 무관)
                $cond = 'ci.is_used = 1';
                break;
            case 'expired':
                // 기간 만료 — 미사용인데 EXPIRED로 마킹됐거나 유효기간이 지난 쿠폰
                $cond = "ci.is_used = 0 AND (ci.status = 'EXPIRED' OR ci.valid_until < ?)";
                $params[] = date('Y-m-d H:i:s');
                break;
            case 'available':
            default:
                // 사용 가능 — 미사용 + 미만료
                $cond = "ci.is_used = 0 AND ci.status <> 'EXPIRED' AND ci.valid_until >= ?";
                $params[] = date('Y-m-d H:i:s');
                break;
        }

        // 회원 쿠폰함 표시에 정책 필드(name, discount_*, coupon_method,
        // min_order_amount, max_discount 등)가 필요하므로 정책 테이블과 JOIN.
        $sql = "SELECT ci.*, cg.name, cg.coupon_method, cg.discount_type, cg.discount_value,
                       cg.max_discount, cg.min_order_amount, cg.duplicate_policy,
                       cg.use_limit_per_member, cg.allowed_member_levels, cg.first_order_only
                FROM {$this->issueTable} ci
                JOIN {$this->table} cg ON cg.coupon_group_id = ci.coupon_group_id
                WHERE ci.member_id = ? AND ({$cond})
                ORDER BY ci.created_at DESC";

        return $this->getDb()->select($sql, $params);
    }

    /**
     * 쿠폰 사용 처리
     *
     * @param int $couponId 쿠폰 ID
     * @param string $orderNo 사용된 주문번호
     * @param int $amount 사용 금액
     * @return bool 성공 여부
     */
    public function useCoupon(int $couponId, string $orderNo, int $amount): bool
    {
        // 원자적 claim: 아직 미사용(is_used=0)인 경우에만 사용 처리한다.
        // 이 가드가 없으면 동시 요청/재시도로 단일사용 쿠폰이 여러 주문에 마킹되고(order_no 덮어씀),
        // affected>0가 항상 true라 재사용을 탐지하지 못한다. 이제 동시 요청 중 하나만 claim에 성공한다.
        $affected = $this->getDb()->table($this->issueTable)
            ->where('coupon_id', '=', $couponId)
            ->where('is_used', '=', 0)
            ->update([
                'is_used' => 1,
                'used_at' => date('Y-m-d H:i:s'),
                'order_no' => $orderNo,
                'used_amount' => $amount,
                'status' => 'USED',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $affected > 0;
    }

    /**
     * 특정 쿠폰 그룹의 전체 발행 수 조회
     */
    public function getTotalIssuedCount(int $couponGroupId): int
    {
        return $this->getDb()->table($this->issueTable)
            ->where('coupon_group_id', '=', $couponGroupId)
            ->count();
    }

    /**
     * 프로모션 코드로 쿠폰 정책 조회
     */
    public function findByPromotionCode(string $code): ?CouponPolicy
    {
        $row = $this->getDb()->table($this->table)
            ->where('promotion_code', '=', $code)
            ->where('is_active', '=', 1)
            ->first();

        return $row ? CouponPolicy::fromArray($row) : null;
    }

    /**
     * 특정 쿠폰 그룹의 회원별 발행 횟수 조회
     *
     * @param int $couponGroupId 쿠폰 그룹 ID
     * @param int $memberId 회원 ID
     * @return int 발행 횟수
     */
    public function getIssuedCount(int $couponGroupId, int $memberId): int
    {
        return $this->getDb()->table($this->issueTable)
            ->where('coupon_group_id', '=', $couponGroupId)
            ->where('member_id', '=', $memberId)
            ->count();
    }

    /**
     * 특정 쿠폰 그룹의 회원별 사용 횟수 조회
     */
    public function getUsedCount(int $couponGroupId, int $memberId): int
    {
        return $this->getDb()->table($this->issueTable)
            ->where('coupon_group_id', '=', $couponGroupId)
            ->where('member_id', '=', $memberId)
            ->where('is_used', '=', 1)
            ->count();
    }

    /**
     * 주문에 사용된 쿠폰 목록 조회
     */
    public function getCouponsByOrderNo(string $orderNo): array
    {
        $sql = "SELECT ci.*, cg.coupon_method, cg.duplicate_policy
                FROM {$this->issueTable} ci
                JOIN {$this->table} cg ON cg.coupon_group_id = ci.coupon_group_id
                WHERE ci.order_no = ? AND ci.is_used = 1";

        return $this->getDb()->select($sql, [$orderNo]);
    }

    /**
     * 쿠폰 사용 복원 (주문 취소 시)
     */
    public function restoreCoupon(int $couponId): bool
    {
        $affected = $this->getDb()->table($this->issueTable)
            ->where('coupon_id', '=', $couponId)
            ->update([
                'is_used' => 0,
                'used_at' => null,
                'order_no' => null,
                'used_amount' => 0,
                'status' => 'ISSUED',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $affected > 0;
    }

    /**
     * 만료된 쿠폰의 status를 EXPIRED로 변경 (복원 시 사용)
     */
    public function expireCoupon(int $couponId): bool
    {
        $affected = $this->getDb()->table($this->issueTable)
            ->where('coupon_id', '=', $couponId)
            ->update([
                'status' => 'EXPIRED',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $affected > 0;
    }

    /**
     * 만료된 ISSUED 쿠폰을 일괄 EXPIRED 처리
     *
     * @return int 처리된 행 수
     */
    public function expireOverdueCoupons(): int
    {
        $sql = "UPDATE {$this->issueTable}
                SET status = 'EXPIRED', updated_at = ?
                WHERE status = 'ISSUED' AND valid_until < ?";

        $now = date('Y-m-d H:i:s');
        return $this->getDb()->execute($sql, [$now, $now]);
    }
}
