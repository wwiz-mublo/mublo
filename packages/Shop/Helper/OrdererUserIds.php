<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Helper;

use Mublo\Contract\Member\MemberQueryInterface;

/**
 * 주문 목록의 회원 주문자 아이디(members.user_id)를 한 번에 조회한다.
 *
 * 관리자 주문 목록·주문 엑셀이 페이지/청크 단위로 호출한다 (주문마다 조회하지 않는다).
 * 비회원 주문(member_id 0)은 결과에 들어가지 않는다.
 */
final class OrdererUserIds
{
    /**
     * @param array<int, array> $orders 주문 행 목록 (member_id 포함)
     * @return array<int, string> memberId => userId
     */
    public static function lookup(?MemberQueryInterface $memberQuery, array $orders): array
    {
        if ($memberQuery === null) {
            return [];
        }

        $memberIds = [];
        foreach ($orders as $order) {
            $memberId = (int) ($order['member_id'] ?? 0);
            if ($memberId > 0) {
                $memberIds[$memberId] = $memberId;
            }
        }
        if ($memberIds === []) {
            return [];
        }

        $map = [];
        foreach ($memberQuery->findProfiles(array_values($memberIds)) as $profile) {
            $map[$profile->memberId] = $profile->userId;
        }

        return $map;
    }
}
