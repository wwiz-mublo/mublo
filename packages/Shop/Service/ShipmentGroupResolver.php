<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

/**
 * ShipmentGroupResolver
 *
 * 주문에 얼린 배송비 그룹(shop_orders.shipping_breakdown)을 출고 단위로 해석한다.
 *
 * 배송비 그룹은 상품별 배송 템플릿으로 갈리고, 템플릿마다 반품지가 따로
 * 스냅샷된다(CartCheckoutService::buildShippingBreakdown). 반품지가 다르다는 건
 * 출고지가 다르다는 뜻이므로, 배송지가 하나여도 그룹이 둘이면 송장은 둘이다.
 * 그래서 그룹을 송장의 기본 단위로 삼는다.
 *
 * 책임:
 * - 주문 → 출고 그룹 목록 (관리자 송장 입력 단위)
 * - 송장 → 그 송장이 싣고 있는 주문상품 id
 *
 * 금지:
 * - 배송비 재계산 (ShippingFeeCalculator 담당)
 * - 상태 변경 (OrderService 담당)
 */
final class ShipmentGroupResolver
{
    /**
     * 주문의 출고 그룹 목록.
     *
     * 그룹이 하나뿐이면 key를 null로 정규화한다 — 쪼갤 것이 없는 주문은
     * 기존과 똑같이 "주문 전체 묶음배송"으로 다루는 편이 관리자에게도 쉽다.
     *
     * 브레이크다운이 전체 상품을 덮지 못하면(정책 미설정으로 그룹이 빠진 주문 등)
     * 쪼개기를 포기하고 단일 그룹으로 돌려준다. 반쪽짜리 정보로 송장을 갈라
     * 엉뚱한 상품에 붙이는 것보다 낫다.
     *
     * @param array $order 주문 레코드 (shipping_breakdown 포함)
     * @param array $items 주문상품 목록
     * @return array<int, array{key:?string, label:string, fee:int, separate:bool, detail_ids:int[], item_names:string[]}>
     */
    public function resolve(array $order, array $items): array
    {
        $allDetailIds = [];
        $detailIdsByGoods = [];
        $nameByDetailId = [];
        foreach ($items as $item) {
            $detailId = (int) ($item['order_detail_id'] ?? 0);
            if ($detailId <= 0) {
                continue;
            }
            $allDetailIds[] = $detailId;
            $detailIdsByGoods[(int) ($item['goods_id'] ?? 0)][] = $detailId;
            $nameByDetailId[$detailId] = trim((string) ($item['goods_name'] ?? ''));
        }
        if ($allDetailIds === []) {
            return [];
        }

        $groups = [];
        $covered = [];
        foreach ($this->breakdown($order) as $index => $entry) {
            $detailIds = [];
            foreach ((array) ($entry['goods_ids'] ?? []) as $goodsId) {
                foreach ($detailIdsByGoods[(int) $goodsId] ?? [] as $detailId) {
                    $detailIds[] = $detailId;
                    $covered[$detailId] = true;
                }
            }
            $detailIds = array_values(array_unique($detailIds));
            if ($detailIds === []) {
                continue;
            }
            $groups[] = [
                // group_key는 2026-08 이후 주문에만 있다. 그 전 주문은 배열 순서를
                // 키로 쓴다 — 얼린 스냅샷이라 순서가 변하지 않는다.
                'key' => (string) ($entry['group_key'] ?? $index),
                'label' => $this->label($entry),
                'fee' => (int) ($entry['base_fee'] ?? 0) + (int) ($entry['extra_fee'] ?? 0),
                // 개별 배송 상품은 그룹 키에 상품 id가 붙는다(ShippingFeeCalculator::groupKey).
                // 같은 템플릿을 쓰는 일반 상품과 나란히 있어도 이걸로만 정확히 갈린다.
                // 키가 없는 옛 주문은 알 수 없으므로 표시하지 않는다 — 잘못 붙이는 것보다 낫다.
                'separate' => (bool) preg_match('/_g\d+$/', (string) ($entry['group_key'] ?? '')),
                'detail_ids' => $detailIds,
                'item_names' => array_values(array_filter(array_map(
                    static fn(int $detailId): string => $nameByDetailId[$detailId] ?? '',
                    $detailIds
                ))),
            ];
        }

        $allDetailIds = array_values(array_unique($allDetailIds));
        if ($groups === [] || count($covered) !== count($allDetailIds)) {
            return [$this->wholeOrderGroup($allDetailIds, $nameByDetailId)];
        }
        if (count($groups) === 1) {
            $groups[0]['key'] = null;
        }
        return $groups;
    }

    /**
     * 송장이 싣고 있는 주문상품 id.
     *
     * 품목 지정(order_detail_id) > 그룹 지정(shipping_group_key) > 주문 전체 순으로
     * 좁은 지정을 우선한다. 지정이 없거나 해석되지 않으면 주문 전체로 본다 —
     * 그게 이 컬럼들이 없던 시절 송장의 의미였다.
     *
     * @return int[]
     */
    public function detailIdsForShipment(array $shipment, array $order, array $items): array
    {
        $detailId = (int) ($shipment['order_detail_id'] ?? 0);
        if ($detailId > 0) {
            return [$detailId];
        }

        $groups = $this->resolve($order, $items);
        $groupKey = $shipment['shipping_group_key'] ?? null;
        if ($groupKey !== null && $groupKey !== '') {
            foreach ($groups as $group) {
                if ((string) $group['key'] === (string) $groupKey) {
                    return $group['detail_ids'];
                }
            }
        }

        $detailIds = [];
        foreach ($groups as $group) {
            foreach ($group['detail_ids'] as $id) {
                $detailIds[] = $id;
            }
        }
        return array_values(array_unique($detailIds));
    }

    /** 주문에 존재하는 그룹 키인지 (관리자 입력 검증용). */
    public function hasGroupKey(array $order, array $items, string $groupKey): bool
    {
        foreach ($this->resolve($order, $items) as $group) {
            if ($group['key'] !== null && (string) $group['key'] === $groupKey) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param int[] $detailIds
     * @param array<int, string> $nameByDetailId
     * @return array{key:null, label:string, fee:int, separate:bool, detail_ids:int[], item_names:string[]}
     */
    private function wholeOrderGroup(array $detailIds, array $nameByDetailId): array
    {
        return [
            'key' => null,
            'label' => '주문 전체',
            'fee' => 0,
            'separate' => false,
            'detail_ids' => $detailIds,
            'item_names' => array_values(array_filter(array_map(
                static fn(int $detailId): string => $nameByDetailId[$detailId] ?? '',
                $detailIds
            ))),
        ];
    }

    private function label(array $entry): string
    {
        $name = trim((string) ($entry['template_name'] ?? ''));
        $fee = (int) ($entry['base_fee'] ?? 0) + (int) ($entry['extra_fee'] ?? 0);
        $feeLabel = $fee > 0 ? number_format($fee) . '원' : '무료배송';
        return ($name !== '' ? $name : '배송') . ' · ' . $feeLabel;
    }

    /** @return array<int, array> */
    private function breakdown(array $order): array
    {
        $breakdown = $order['shipping_breakdown'] ?? [];
        if (is_string($breakdown)) {
            $breakdown = json_decode($breakdown, true);
        }
        if (!is_array($breakdown)) {
            return [];
        }
        return array_values(array_filter($breakdown, 'is_array'));
    }
}
