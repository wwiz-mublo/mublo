<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Packages\Shop\Repository\PaymentTransactionRepository;
use Mublo\Packages\Shop\Service\PaymentReceiptService;
use PHPUnit\Framework\TestCase;

class PaymentReceiptServiceTest extends TestCase
{
    public function testReceiptLookupUsesCurrentDomain(): void
    {
        $repository = $this->createMock(PaymentTransactionRepository::class);
        $repository->expects($this->once())
            ->method('getByOrderNoInDomain')
            ->with(7, 'ORDER-1')
            ->willReturn([[
                'transaction_type' => 'PAYMENT',
                'transaction_status' => 'SUCCESS',
                'pg_response' => json_encode(['receipt_url' => 'https://pay.example/receipt/1']),
            ]]);

        $url = (new PaymentReceiptService($repository))->getReceiptUrl(7, 'ORDER-1');

        $this->assertSame('https://pay.example/receipt/1', $url);
    }
}
