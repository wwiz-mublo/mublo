<?php

namespace Mublo\Packages\Shop\Report;

use Mublo\Core\Report\Contract\ReportDefinitionInterface;
use Mublo\Core\Report\Document\ReportDocument;
use Mublo\Core\Report\Document\ColumnDefinition;
use Mublo\Core\Report\Document\Section\TableSection;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\OrderStateResolver;

/**
 * ShopOrderReportDefinition
 *
 * 주문 목록 엑셀/CSV 내보내기 정의 (코어 Report 프레임워크).
 * 운영자가 출력할 필드를 지정하면(filters['columns']) 그 컬럼만 구성한다(필드 피커).
 * 미지정 시 기본 컬럼셋을 사용한다.
 */
class ShopOrderReportDefinition implements ReportDefinitionInterface
{
    /** 출력 가능한 전체 필드: key => [label, type, align] */
    public const FIELDS = [
        'order_no'         => ['주문번호', 'string', 'left'],
        'created_at'       => ['주문일시', 'string', 'left'],
        'member_type'      => ['회원구분', 'string', 'center'],
        'orderer_name'     => ['주문인', 'string', 'left'],
        'orderer_phone'    => ['주문인 연락처', 'string', 'left'],
        'orderer_email'    => ['주문인 이메일', 'string', 'left'],
        'recipient_name'   => ['수령인', 'string', 'left'],
        'recipient_phone'  => ['수령인 연락처', 'string', 'left'],
        'shipping_zip'     => ['우편번호', 'string', 'left'],
        'shipping_address' => ['배송주소', 'string', 'left'],
        'product_summary'  => ['상품', 'string', 'left'],
        'total_price'      => ['상품합계', 'number', 'right'],
        'shipping_fee'     => ['배송비', 'number', 'right'],
        'coupon_discount'  => ['쿠폰할인', 'number', 'right'],
        'point_used'       => ['포인트사용', 'number', 'right'],
        'final_amount'     => ['결제금액', 'number', 'right'],
        'payment_method'   => ['결제수단', 'string', 'center'],
        'payment_gateway'  => ['PG', 'string', 'center'],
        'order_status'     => ['주문상태', 'string', 'center'],
        'order_memo'       => ['주문메모', 'string', 'left'],
        'courier'          => ['택배사', 'string', 'center'],
        'invoice_no'       => ['송장번호', 'string', 'left'],
    ];

    /** 피커 미지정 시 기본 출력 컬럼 */
    public const DEFAULT_COLUMNS = [
        'order_no', 'created_at', 'orderer_name', 'recipient_name', 'recipient_phone',
        'shipping_zip', 'shipping_address', 'product_summary', 'final_amount',
        'payment_method', 'order_status',
    ];

    public function __construct(
        private OrderService $orderService,
        private OrderStateResolver $stateResolver
    ) {
    }

    public function name(): string
    {
        return 'shop_orders';
    }

    public function build(array $filters): ReportDocument
    {
        $domainId = (int) ($filters['domain_id'] ?? 0);

        // 필드 피커: 선택 컬럼만(알 수 없는 키 무시), 없으면 기본셋
        $selected = array_values(array_filter(
            array_map('strval', (array) ($filters['columns'] ?? [])),
            fn(string $key) => isset(self::FIELDS[$key])
        ));
        if (empty($selected)) {
            $selected = self::DEFAULT_COLUMNS;
        }

        $columns = array_map(function (string $key): ColumnDefinition {
            [$label, $type, $align] = self::FIELDS[$key];
            return new ColumnDefinition($key, $label, $type, $align);
        }, $selected);

        $provider = new OrderReportRowProvider(
            $this->orderService,
            $this->stateResolver,
            $domainId,
            $filters
        );

        return ReportDocument::create('주문 목록')
            ->addSection(new TableSection($columns, $provider));
    }
}
