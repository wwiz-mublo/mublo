<?php
declare(strict_types=1);

namespace Tests\PayApp\Support;

use Mublo\Contract\Payment\PaymentConsumerInterface;
use Mublo\Contract\Payment\PreparedPayment;
use Mublo\Core\Result\Result;

/**
 * 결제 소비 패키지 대역 — 호출을 기록하고 결과를 지정할 수 있다.
 */
class FakeConsumer implements PaymentConsumerInterface
{
    /** @var list<array<int, mixed>> */
    public array $verifyCalls = [];

    /** @var list<array<string, mixed>> */
    public array $refundCalls = [];

    /** @var list<Result|\Throwable> 차례로 돌려줄 검증 결과. 비면 성공 */
    public array $verifyResults = [];

    public Result|\Throwable|null $refundResult = null;

    public ?PreparedPayment $prepared = null;

    public function findPreparedPayment(int $domainId, string $orderNo): ?PreparedPayment
    {
        return $this->prepared;
    }

    public function verifyPayment(int $domainId, string $orderNo, string $pgKey, string $transactionId): Result
    {
        $this->verifyCalls[] = [$domainId, $orderNo, $pgKey, $transactionId];
        $next = array_shift($this->verifyResults) ?? Result::success('결제가 검증되었습니다.');
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }

    public function recordExternalRefund(int $domainId, string $orderNo, string $pgKey, string $transactionId, int $amount, string $reason, bool $fullCancel): Result
    {
        $this->refundCalls[] = compact('domainId', 'orderNo', 'pgKey', 'transactionId', 'amount', 'reason', 'fullCancel');
        if ($this->refundResult instanceof \Throwable) {
            throw $this->refundResult;
        }
        return $this->refundResult ?? Result::success('환불이 기록되었습니다.');
    }
}
