<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Helper;

/**
 * 옵션/조합의 프론트 전달 형태
 *
 * 프론트로 나가는 옵션 데이터의 키와 타입을 정하는 단일 지점이다.
 *
 * 왜 한 곳에 두는가: 상품 상세와 장바구니가 같은 옵션 데이터를 서로 다른 경로로
 * 내려주고 있었다. 상세는 ProductPresenter 를 거쳐 정규화된 값을, 장바구니는
 * Repository raw 행을 그대로 넘겼다. 그 결과 같은 `is_active` 가 한쪽은 boolean,
 * 다른 쪽은 "1" 이 되어 소비자 JS 가 두 타입을 다 감당해야 했다(#568).
 * 갈라진 채로 두면 어느 쪽 타입이 계약인지 판단할 근거가 남지 않는다.
 *
 * 정하는 것:
 *  - 공개 키만 통과시킨다. created_at 같은 내부 컬럼은 프론트로 나가지 않는다.
 *  - 플래그는 boolean, 식별자·금액은 int 로 고정한다.
 *  - stock_quantity 의 null 은 보존한다. "재고 미관리"와 "재고 0"은 다른 상태다.
 */
final class ProductOptionPresenter
{
    /**
     * 옵션 그룹 목록 정규화
     *
     * @param array<int,array<string,mixed>> $options 각 항목에 values 를 포함한 옵션 배열
     * @return list<array<string,mixed>>
     */
    public static function toOptionList(array $options): array
    {
        return array_values(array_map(static function (array $option): array {
            $values = is_array($option['values'] ?? null) ? $option['values'] : [];

            return [
                'option_id' => (int) ($option['option_id'] ?? 0),
                'option_name' => (string) ($option['option_name'] ?? ''),
                'option_type' => (string) ($option['option_type'] ?? 'BASIC'),
                'is_required' => (bool) ($option['is_required'] ?? true),
                'sort_order' => (int) ($option['sort_order'] ?? 0),
                'values' => array_values(array_map(static fn(array $value): array => [
                    'value_id' => (int) ($value['value_id'] ?? 0),
                    'value_name' => (string) ($value['value_name'] ?? ''),
                    'extra_price' => (int) ($value['extra_price'] ?? 0),
                    'stock_quantity' => ($value['stock_quantity'] ?? null) === null
                        ? null : (int) $value['stock_quantity'],
                    'is_active' => (bool) ($value['is_active'] ?? true),
                    'sort_order' => (int) ($value['sort_order'] ?? 0),
                ], $values)),
            ];
        }, $options));
    }

    /**
     * 조합 목록 정규화
     *
     * @param array<int,array<string,mixed>> $combos
     * @return list<array<string,mixed>>
     */
    public static function toComboList(array $combos): array
    {
        return array_values(array_map(static fn(array $combo): array => [
            'combo_id' => (int) ($combo['combo_id'] ?? 0),
            'combination_key' => (string) ($combo['combination_key'] ?? ''),
            'extra_price' => (int) ($combo['extra_price'] ?? 0),
            'stock_quantity' => ($combo['stock_quantity'] ?? null) === null
                ? null : (int) $combo['stock_quantity'],
            'is_active' => (bool) ($combo['is_active'] ?? true),
        ], $combos));
    }
}
