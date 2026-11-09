<?php
declare(strict_types=1);

namespace Mublo\Contract\Payment;

use Mublo\Core\Result\Result;

/**
 * 가상계좌를 받는 결제 소비자 — PaymentConsumerInterface 의 선택 확장.
 *
 * 가상계좌는 결제가 두 단계로 나뉜다.
 *   1. 발급: PG 승인 = 계좌 발급. 아직 돈은 들어오지 않았다 → recordVirtualAccountIssued
 *   2. 입금: PG 가 입금을 통보하면 플러그인이 확인한 뒤 → PaymentConsumerInterface::verifyPayment
 *
 * 1 과 2 사이에 주문은 "입금 대기"로 남아 있어야 한다. 미결제 주문 정리(스윕)에 지워지면
 * 입금이 들어와도 붙일 주문이 없다. 그래서 발급 사실을 소비자에게 따로 알린다.
 *
 * 이 인터페이스를 구현하지 않은 소비자에게 PG 플러그인은 가상계좌 결제를 받지 않는다
 * (발급된 계좌는 닫는다). 기존 소비자는 아무것도 바꾸지 않아도 된다.
 *
 * verifyPayment 는 입금 대기 상태의 주문도 결제 완료로 옮길 수 있어야 한다.
 * 이때 발급 때 받은 거래번호와 같은 거래인지 확인한다.
 */
interface VirtualAccountConsumerInterface
{
    /**
     * 가상계좌 발급을 주문에 기록하고 주문을 입금 대기로 둔다.
     *
     * 같은 거래로 다시 불려도 안전해야 한다(멱등).
     */
    public function recordVirtualAccountIssued(
        int $domainId,
        string $orderNo,
        string $pgKey,
        string $transactionId,
        VirtualAccountIssue $account,
    ): Result;
}
