<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * 탈퇴를 막아야 할 거래가 남았는지 조회한다.
 *
 * 판단 기준은 "돈이 오갈 여지가 남았는가" 다. 결제만 끝나고 이행이 안 끝난 주문이나
 * 심사 중인 클레임이 있으면, 탈퇴 뒤 취소·반품이 나왔을 때 환불할 곳이 없다 —
 * 탈퇴는 포인트 잔액을 0으로 정리하고 회원 쪽 창구도 함께 닫는다.
 *
 * 어떤 주문 상태가 "이행 중" 인지는 이 클래스가 정하지 않는다. 주문 상태는 도메인마다
 * 설정으로 바뀌므로(ShopConfig 의 order_states) 호출자가 그 목록을 넘긴다.
 */
class WithdrawalBlockerRepository
{
    /**
     * 아직 끝나지 않은 클레임 상태.
     *
     * 주문이 배송완료로 보여도 반품 심사가 열려 있을 수 있어 따로 본다.
     * 거절·취소·종결은 더 처리할 것이 없다.
     */
    private const OPEN_CLAIM_STATUSES = [
        'REQUESTED', 'ACCEPTED', 'COLLECTING', 'COLLECTED', 'INSPECTING',
        'READY_TO_SHIP', 'RESHIPPING', 'READY_TO_REFUND', 'REJECTED', 'RETURNING',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * 이행이 끝나지 않은 주문.
     *
     * @param string[] $unfinishedStates 이행 중으로 볼 주문 상태 id
     * @param string[] $pendingStates 결제 전 상태 id — 최근 것만 막는다
     * @param int $pendingGraceSeconds 결제 진행 중으로 볼 시간
     * @return list<array{order_no: string, status: string}>
     */
    public function findUnfinishedOrders(
        int $memberId,
        array $unfinishedStates,
        array $pendingStates,
        int $pendingGraceSeconds,
    ): array {
        $conditions = [];
        $params = [$memberId];

        if ($unfinishedStates !== []) {
            $conditions[] = 'order_status IN (' . $this->placeholders($unfinishedStates) . ')';
            $params = array_merge($params, $unfinishedStates);
        }

        // 결제 전 주문은 돈이 움직이지 않아 막을 이유가 약하다. 다만 방금 만들어진 것은
        // 결제창이 떠 있는 중일 수 있어, 그 사이 탈퇴하면 결제는 들어오는데 회원이 없다.
        // 오래된 것은 버려진 주문으로 보고 통과시킨다 — 그러지 않으면 방치된 건 하나가
        // 탈퇴를 영영 막는다.
        if ($pendingStates !== []) {
            $conditions[] = '(order_status IN (' . $this->placeholders($pendingStates) . ') AND created_at > ?)';
            $params = array_merge($params, $pendingStates, [
                date('Y-m-d H:i:s', time() - $pendingGraceSeconds),
            ]);
        }

        if ($conditions === []) {
            return [];
        }

        $sql = 'SELECT order_no, order_status
                FROM `shop_orders`
                WHERE member_id = ? AND (' . implode(' OR ', $conditions) . ')
                ORDER BY created_at DESC';

        return $this->fetch($sql, $params, 'order_status');
    }

    /**
     * 아직 끝나지 않은 클레임.
     *
     * @return list<array{order_no: string, status: string}>
     */
    public function findOpenClaims(int $memberId): array
    {
        $sql = 'SELECT order_no, return_status
                FROM `shop_returns`
                WHERE member_id = ? AND return_status IN (' . $this->placeholders(self::OPEN_CLAIM_STATUSES) . ')
                ORDER BY created_at DESC';

        return $this->fetch(
            $sql,
            array_merge([$memberId], self::OPEN_CLAIM_STATUSES),
            'return_status',
        );
    }

    /**
     * @param array<int, string> $params
     * @return list<array{order_no: string, status: string}>
     */
    private function fetch(string $sql, array $params, string $statusColumn): array
    {
        $statement = $this->db->getPdo()->prepare($sql);
        $statement->execute($params);

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'order_no' => (string) $row['order_no'],
                'status' => (string) $row[$statusColumn],
            ];
        }

        return $rows;
    }

    /** @param array<int, string> $values */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
