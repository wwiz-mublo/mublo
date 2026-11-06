<?php
declare(strict_types=1);

namespace Tests\Innopay\Support;

use Mublo\Contract\Payment\PaymentConsumerInterface;
use Mublo\Contract\Payment\PreparedPayment;
use Mublo\Core\Result\Result;

/**
 * 결제 소비 패키지 대역 — 호출을 기록하고 결과를 지정할 수 있다.
 */
final class FakeConsumer implements PaymentConsumerInterface
{
    public array $verifyCalls = [];
    public array $refundCalls = [];
    public ?Result $verifyResult = null;
    public ?Result $refundResult = null;
    public ?PreparedPayment $prepared = null;
    public bool $paidAfterVerify = false;

    public function findPreparedPayment(int $domainId, string $orderNo): ?PreparedPayment
    {
        if ($this->paidAfterVerify && $this->verifyCalls !== []) {
            return new PreparedPayment('PAID', 'innopay', 15000, true);
        }
        return $this->prepared;
    }

    public function verifyPayment(int $domainId, string $orderNo, string $pgKey, string $transactionId): Result
    {
        $this->verifyCalls[] = [$domainId, $orderNo, $pgKey, $transactionId];
        return $this->verifyResult ?? Result::success('결제가 검증되었습니다.');
    }

    public function recordExternalRefund(int $domainId, string $orderNo, string $pgKey, string $transactionId, int $amount, string $reason, bool $fullCancel): Result
    {
        $this->refundCalls[] = compact('domainId', 'orderNo', 'pgKey', 'transactionId', 'amount', 'reason', 'fullCancel');
        return $this->refundResult ?? Result::success('환불이 기록되었습니다.');
    }
}
