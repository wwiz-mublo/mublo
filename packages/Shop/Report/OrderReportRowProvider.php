<?php

namespace Mublo\Packages\Shop\Report;

use Mublo\Core\Report\Contract\RowProviderInterface;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Packages\Shop\Enum\PaymentMethod;

/**
 * OrderReportRowProvider
 *
 * 주문 엑셀/CSV 내보내기의 행 공급자.
 * OrderService::getOrderList(복호화 + 상품요약)를 페이지 단위로 조회해 평평한 행으로 매핑한다.
 * getChunk(offset, limit)로 대용량도 코어 Report 청크 흐름에 태울 수 있다.
 */
class OrderReportRowProvider implements RowProviderInterface
{
    private const BATCH = 500;

    private ?int $total = null;

    public function __construct(
        private OrderService $orderService,
        private OrderStateResolver $stateResolver,
        private int $domainId,
        private array $filters
    ) {
    }

    public function getChunk(int $offset, int $limit): array
    {
        $limit = max(1, $limit);
        $page = (int) floor(max(0, $offset) / $limit) + 1;

        $result = $this->orderService->getOrderList($this->domainId, $this->filters, $page, $limit);
        if ($result->isFailure()) {
            return [];
        }

        $data = $result->getData();
        $this->total = (int) ($data['pagination']['total'] ?? $this->total ?? 0);

        return array_map(fn(array $order) => $this->mapRow($order), $data['items'] ?? []);
    }

    public function rows(): iterable
    {
        $offset = 0;
        do {
            $chunk = $this->getChunk($offset, self::BATCH);
            foreach ($chunk as $row) {
                yield $row;
            }
            $offset += self::BATCH;
        } while (count($chunk) === self::BATCH);
    }

    public function totalCount(): ?int
    {
        if ($this->total === null) {
            $result = $this->orderService->getOrderList($this->domainId, $this->filters, 1, 1);
            $this->total = $result->isSuccess()
                ? (int) ($result->getData()['pagination']['total'] ?? 0)
                : 0;
        }
        return $this->total;
    }

    public function isRewindable(): bool
    {
        return true;
    }

    /**
     * 주문 배열(복호화됨) → 내보내기 행
     */
    private function mapRow(array $order): array
    {
        $memberId = (int) ($order['member_id'] ?? 0);
        $itemCount = (int) ($order['item_count'] ?? 0);
        $firstItem = (string) ($order['first_item_name'] ?? '');
        $summary = $firstItem === ''
            ? ''
            : ($itemCount > 1 ? "{$firstItem} 외 " . ($itemCount - 1) . '건' : $firstItem);

        $method = (string) ($order['payment_method'] ?? '');
        $methodLabel = PaymentMethod::tryFrom($method)?->label() ?? $method;

        $statusId = (string) ($order['order_status'] ?? '');
        $statusLabel = $statusId !== '' ? $this->stateResolver->getLabel($this->domainId, $statusId) : '';

        $address = trim(
            ((string) ($order['shipping_address1'] ?? '')) . ' ' . ((string) ($order['shipping_address2'] ?? ''))
        );

        return [
            'order_no'         => (string) ($order['order_no'] ?? ''),
            'created_at'       => (string) ($order['created_at'] ?? ''),
            'member_type'      => $memberId > 0 ? '회원' : '비회원',
            'orderer_name'     => (string) ($order['orderer_name'] ?? ''),
            'orderer_phone'    => (string) ($order['orderer_phone'] ?? ''),
            'orderer_email'    => (string) ($order['orderer_email'] ?? ''),
            'recipient_name'   => (string) ($order['recipient_name'] ?? ''),
            'recipient_phone'  => (string) ($order['recipient_phone'] ?? ''),
            'shipping_zip'     => (string) ($order['shipping_zip'] ?? ''),
            'shipping_address' => $address,
            'product_summary'  => $summary,
            'total_price'      => (int) ($order['total_price'] ?? 0),
            'shipping_fee'     => (int) ($order['shipping_fee'] ?? 0),
            'coupon_discount'  => (int) ($order['coupon_discount'] ?? 0),
            'point_used'       => (int) ($order['point_used'] ?? 0),
            'final_amount'     => (int) ($order['final_amount'] ?? 0),
            'payment_method'   => $methodLabel,
            'payment_gateway'  => (string) ($order['payment_gateway'] ?? ''),
            'order_status'     => $statusLabel,
            'order_memo'       => (string) ($order['order_memo'] ?? ''),
            'courier'          => '', // 운송장 일괄 업로드용 빈 칸
            'invoice_no'       => '', // 운송장 일괄 업로드용 빈 칸
        ];
    }
}
