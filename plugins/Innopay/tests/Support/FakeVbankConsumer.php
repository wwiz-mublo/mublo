<?php
declare(strict_types=1);

namespace Tests\Innopay\Support;

use Mublo\Contract\Payment\VirtualAccountConsumerInterface;
use Mublo\Contract\Payment\VirtualAccountIssue;
use Mublo\Core\Result\Result;

/**
 * 가상계좌를 받는 결제 소비 패키지 대역
 */
final class FakeVbankConsumer extends FakeConsumer implements VirtualAccountConsumerInterface
{
    /** @var list<array{orderNo: string, tid: string, account: VirtualAccountIssue}> */
    public array $issueCalls = [];
    public ?Result $issueResult = null;

    public function recordVirtualAccountIssued(int $domainId, string $orderNo, string $pgKey, string $transactionId, VirtualAccountIssue $account): Result
    {
        $this->issueCalls[] = ['orderNo' => $orderNo, 'tid' => $transactionId, 'account' => $account];
        return $this->issueResult ?? Result::success('입금 대기');
    }
}
