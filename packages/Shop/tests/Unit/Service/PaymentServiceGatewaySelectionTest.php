<?php

namespace Tests\Shop\Unit\Service;

use PHPUnit\Framework\TestCase;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Contract\Payment\PaymentGatewayInterface;
use Mublo\Contract\Payment\PaymentGatewayResponse;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\PaymentCompletionService;
use Mublo\Packages\Shop\Service\PaymentService;
use Mublo\Packages\Shop\Service\PriceCalculator;

class PaymentServiceGatewaySelectionTest extends TestCase
{
    private ContractRegistry $registry;
    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ContractRegistry();
        $this->service = new PaymentService(
            $this->registry,
            $this->createMock(OrderRepository::class),
            $this->createMock(OrderService::class),
            $this->createMock(PriceCalculator::class),
            $this->createMock(PaymentCompletionService::class),
            null
        );
    }

    public function testSelectGatewayPrefersRequestedKey(): void
    {
        $this->registerGateway('alpha');
        $this->registerGateway('beta');

        $selected = $this->service->selectGatewayKey(['alpha', 'beta'], 'beta', 'alpha');

        $this->assertSame('beta', $selected);
    }

    public function testSelectGatewayFallsBackToDefaultKeyWhenRequestedIsInvalid(): void
    {
        $this->registerGateway('alpha');
        $this->registerGateway('beta');

        $selected = $this->service->selectGatewayKey(['alpha', 'beta'], 'missing', 'alpha');

        $this->assertSame('alpha', $selected);
    }

    public function testSelectGatewayFallsBackToFirstEnabledKey(): void
    {
        $this->registerGateway('alpha');
        $this->registerGateway('beta');

        $selected = $this->service->selectGatewayKey(['beta', 'alpha'], '', '');

        $this->assertSame('beta', $selected);
    }

    public function testSelectGatewayFallsBackToFirstRegisteredKeyWhenEnabledIsEmpty(): void
    {
        $this->registerGateway('alpha');
        $this->registerGateway('beta');

        $selected = $this->service->selectGatewayKey([], '', '');

        $this->assertSame('alpha', $selected);
    }

    public function testSelectGatewayReturnsNullWhenNoGatewayExists(): void
    {
        $selected = $this->service->selectGatewayKey([], '', '');

        $this->assertNull($selected);
    }

    private function registerGateway(string $key): void
    {
        $this->registry->register(
            PaymentGatewayInterface::class,
            $key,
            new PaymentGatewayStub(),
            ['label' => strtoupper($key)]
        );
    }
}

class PaymentGatewayStub implements PaymentGatewayInterface
{
    public function prepare(array $orderData): PaymentGatewayResponse
    {
        return new PaymentGatewayResponse(true, '');
    }

    public function verify(string $transactionId): PaymentGatewayResponse
    {
        return new PaymentGatewayResponse(true, $transactionId);
    }

    public function cancel(
        string $transactionId,
        int $amount,
        string $reason = '',
        ?int $remainingAmount = null,
    ): PaymentGatewayResponse
    {
        return new PaymentGatewayResponse(true, $transactionId, cancelAmount: $amount);
    }

    public function getClientConfig(): array
    {
        return [];
    }

    public function getCheckoutScript(): ?string
    {
        return null;
    }
}
