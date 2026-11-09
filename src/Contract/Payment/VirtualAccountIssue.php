<?php
declare(strict_types=1);

namespace Mublo\Contract\Payment;

/**
 * PG 가 발급한 가상계좌 — 구매자가 입금할 계좌 정보.
 */
final readonly class VirtualAccountIssue
{
    public function __construct(
        public string $bankName,
        public string $accountNumber,
        /** 입금할 금액 */
        public int $amount,
        /** 입금 기한 (Y-m-d 또는 Y-m-d H:i:s). 모르면 빈 문자열 */
        public string $dueDate = '',
        /** 예금주(가맹점명 등). 모르면 빈 문자열 */
        public string $holder = '',
    ) {
    }

    /** @return array{bank: string, account: string, holder: string, amount: int, due_date: string} */
    public function toArray(): array
    {
        return [
            'bank' => $this->bankName,
            'account' => $this->accountNumber,
            'holder' => $this->holder,
            'amount' => $this->amount,
            'due_date' => $this->dueDate,
        ];
    }
}
