<?php
namespace Mublo\Contract\Payment;

/**
 * PG 구현체가 반환하는 공급자 비종속 결제 결과.
 *
 * 공통 검증 필드는 타입으로 고정하고, PG 고유 응답은 data에 격리한다.
 */
final readonly class PaymentGatewayResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public bool $success,
        public string $transactionId,
        public ?string $orderNo = null,
        public ?int $amount = null,
        public ?string $status = null,
        public ?string $paymentMethod = null,
        public ?string $approvalNo = null,
        public ?int $cancelAmount = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $data = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [
            'success' => $this->success,
            'transaction_id' => $this->transactionId,
        ];

        foreach ([
            'order_no' => $this->orderNo,
            'amount' => $this->amount,
            'status' => $this->status,
            'payment_method' => $this->paymentMethod,
            'approval_no' => $this->approvalNo,
            'cancel_amount' => $this->cancelAmount,
        ] as $key => $value) {
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        if ($this->errorCode !== null || $this->errorMessage !== null) {
            $result['error'] = [
                'code' => $this->errorCode ?? 'PG_ERROR',
                'message' => $this->errorMessage ?? 'PG 요청에 실패했습니다.',
            ];
        }

        return $result + $this->data;
    }
}
