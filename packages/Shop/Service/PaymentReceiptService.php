<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Packages\Shop\Repository\PaymentTransactionRepository;

/** 고객에게 노출할 결제 영수증 정보를 도메인 경계 안에서 조회한다. */
class PaymentReceiptService
{
    public function __construct(private PaymentTransactionRepository $transactions) {}

    public function getReceiptUrl(int $domainId, string $orderNo): string
    {
        if ($orderNo === '') {
            return '';
        }

        foreach ($this->transactions->getByOrderNoInDomain($domainId, $orderNo) as $transaction) {
            if (($transaction['transaction_type'] ?? '') !== 'PAYMENT'
                || ($transaction['transaction_status'] ?? '') !== 'SUCCESS') {
                continue;
            }

            $decoded = json_decode((string) ($transaction['pg_response'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }

            $url = (string) ($decoded['receipt_url'] ?? ($decoded['receipt']['url'] ?? ''));
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                return $url;
            }
        }

        return '';
    }
}
