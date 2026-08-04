<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Packages\Shop\Repository\ShippingRepository;

/**
 * ShippingFeeCalculator
 *
 * 배송비 계산의 단일 진실 원천(single source of truth).
 *
 * 책임:
 * - 라인(상품별 합계) → 배송 템플릿별 그룹화
 * - 그룹별 배송비 계산 (PriceCalculator 위임)
 * - 전체 배송비 합산
 *
 * 배송 정책 해석 순서:
 *   ① 상품에 지정된 배송 템플릿
 *   ② 없으면 쇼핑몰 설정의 기본 배송 템플릿 (shop_config.default_shipping_template_id)
 *   ③ 둘 다 없으면 = 미설정(unresolved) → 배송비 산정 불가 (호출부가 결제 차단)
 *
 * 장바구니 목록/요약, 체크아웃, 바로구매, 실제 주문 청구가 모두 이 클래스를 거쳐
 * "표시 배송비 = 청구 배송비"를 보장한다.
 */
class ShippingFeeCalculator
{
    private ShippingRepository $shippingRepository;
    private ShopConfigService $shopConfigService;
    private PriceCalculator $priceCalculator;

    public function __construct(
        ShippingRepository $shippingRepository,
        ShopConfigService $shopConfigService,
        PriceCalculator $priceCalculator
    ) {
        $this->shippingRepository = $shippingRepository;
        $this->shopConfigService = $shopConfigService;
        $this->priceCalculator = $priceCalculator;
    }

    /**
     * 배송 그룹 키 생성 (계산기와 표시 로직이 동일 규칙을 공유)
     *
     * - COMBINED: 같은 템플릿끼리 한 그룹 (tpl_{id})
     * - SEPARATE: 상품별 독립 그룹 (tpl_{id}_g{goodsId})
     * - 미설정(templateId<=0): unset / unset_g{goodsId}
     */
    public static function groupKey(int $templateId, string $applyType, int $goodsId): string
    {
        $base = $templateId > 0 ? ('tpl_' . $templateId) : 'unset';
        return $applyType === 'SEPARATE' ? ($base . '_g' . $goodsId) : $base;
    }

    /**
     * 쇼핑몰 기본 배송 템플릿 ID 조회 (0 = 미지정)
     */
    public function resolveDefaultTemplateId(int $domainId): int
    {
        $config = $this->shopConfigService->getConfig($domainId)->get('config', []);
        return (int) ($config['default_shipping_template_id'] ?? 0);
    }

    /**
     * 라인 배열로부터 배송비 계산
     *
     * @param int $domainId 도메인 ID
     * @param array $lines 각 행: [
     *     'goods_id'             => int,
     *     'shipping_template_id' => ?int,    // null/0 이면 기본 템플릿 사용
     *     'shipping_apply_type'  => string,  // 'COMBINED' | 'SEPARATE'
     *     'total_price'          => int,
     *     'quantity'             => int,
     * ]
     * @param ?string $zipcode 배송지 우편번호 (있으면 도서산간 추가배송비 가산). null이면 기본배송비만.
     * @return array [
     *     'groups' => [groupKey => ['template_id'=>?int,'template_name'=>string,'shipping_fee'=>?int,'unresolved'=>bool,'extra_fee'=>int,'extra_label'=>string]],
     *     'total'  => ?int,   // 기본배송비+추가비 합계. unresolved 그룹이 하나라도 있으면 null
     *     'extra_total' => int,  // 추가배송비 합계
     *     'unresolved' => bool,
     * ]
     */
    public function calculate(int $domainId, array $lines, ?string $zipcode = null): array
    {
        if (empty($lines)) {
            return ['groups' => [], 'total' => 0, 'extra_total' => 0, 'unresolved' => false];
        }

        $defaultTemplateId = $this->resolveDefaultTemplateId($domainId);

        // 1) 그룹화
        $rawGroups = [];
        foreach ($lines as $line) {
            $goodsId = (int) ($line['goods_id'] ?? 0);
            $applyType = (string) ($line['shipping_apply_type'] ?? 'COMBINED');

            // 상품 템플릿 → (없으면) 기본 템플릿
            $templateId = (int) ($line['shipping_template_id'] ?? 0);
            if ($templateId <= 0) {
                $templateId = $defaultTemplateId;
            }

            $key = self::groupKey($templateId, $applyType, $goodsId);
            if (!isset($rawGroups[$key])) {
                $rawGroups[$key] = [
                    'template_id' => $templateId,
                    'total' => 0,
                    'quantity' => 0,
                    'goods_ids' => [],
                ];
            }
            $rawGroups[$key]['total'] += (int) ($line['total_price'] ?? 0);
            $rawGroups[$key]['quantity'] += (int) ($line['quantity'] ?? 0);
            if ($goodsId > 0 && !in_array($goodsId, $rawGroups[$key]['goods_ids'], true)) {
                $rawGroups[$key]['goods_ids'][] = $goodsId;
            }
        }

        // 2) 그룹별 배송비
        $templateCache = [];
        $groups = [];
        $total = 0;
        $extraTotal = 0;
        $anyUnresolved = false;

        foreach ($rawGroups as $key => $group) {
            $templateId = (int) $group['template_id'];
            $templateArr = $templateId > 0 ? $this->loadTemplate($domainId, $templateId, $templateCache) : null;

            // ③ 미설정: 배송 정책 해석 실패
            if ($templateArr === null) {
                $groups[$key] = [
                    'template_id'   => $templateId > 0 ? $templateId : null,
                    'template_name' => '배송 정책 미설정',
                    'shipping_fee'  => null,
                    'unresolved'    => true,
                    'goods_ids'     => $group['goods_ids'],
                ];
                $anyUnresolved = true;
                continue;
            }

            $fee = $this->priceCalculator->calculateShippingFee(
                $templateArr,
                (int) $group['total'],
                (int) $group['quantity']
            );

            // 도서산간 추가배송비 (기본배송비와 독립 가산 — 무료배송이어도 부과)
            $extra = $this->priceCalculator->calculateExtraShippingFee($templateArr, $zipcode);

            $groups[$key] = [
                'template_id'     => $templateId,
                'template_name'   => $templateArr['name'] ?? '기본배송',
                'shipping_fee'    => $fee + $extra['cost'],
                'base_fee'        => $fee,
                'extra_fee'       => $extra['cost'],
                'extra_label'     => $extra['label'],
                'unresolved'      => false,
                'shipping_method' => $templateArr['shipping_method'] ?? null,
                'free_threshold'  => (int) ($templateArr['free_threshold'] ?? 0),
                'group_total'     => (int) $group['total'],
                'goods_ids'       => $group['goods_ids'],
                'return_cost'     => max(0, (int) ($templateArr['return_cost'] ?? 0)),
                'exchange_cost'   => max(0, (int) ($templateArr['exchange_cost'] ?? 0)),
                'return_address'  => [
                    'zipcode' => (string) ($templateArr['return_zipcode'] ?? ''),
                    'address1' => (string) ($templateArr['return_address1'] ?? ''),
                    'address2' => (string) ($templateArr['return_address2'] ?? ''),
                ],
            ];
            $total += $fee + $extra['cost'];
            $extraTotal += $extra['cost'];
        }

        return [
            'groups'      => $groups,
            'total'       => $anyUnresolved ? null : $total,
            'extra_total' => $extraTotal,
            'unresolved'  => $anyUnresolved,
        ];
    }

    /**
     * 배송비 합계만 반환 (미설정이면 null)
     */
    public function calculateTotal(int $domainId, array $lines): ?int
    {
        return $this->calculate($domainId, $lines)['total'];
    }

    /**
     * 템플릿 조회 + 캐시 (배열 반환, 없으면 null)
     */
    private function loadTemplate(int $domainId, int $templateId, array &$cache): ?array
    {
        if (!array_key_exists($templateId, $cache)) {
            $tpl = $this->shippingRepository->findInDomain($domainId, $templateId, true);
            $cache[$templateId] = $tpl === null
                ? null
                : (is_array($tpl) ? $tpl : $tpl->toArray());
        }
        return $cache[$templateId];
    }
}
