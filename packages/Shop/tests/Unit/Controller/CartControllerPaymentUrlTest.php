<?php
declare(strict_types=1);

namespace Tests\Shop\Unit\Controller;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Entity\Domain\Domain;
use Mublo\Packages\Shop\Controller\Front\CartController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class CartControllerPaymentUrlTest extends TestCase
{
    public function testPaymentBaseUrlUsesStoredCanonicalDomainInsteadOfRequestHost(): void
    {
        $request = new Request('POST', '/shop/checkout', [], [], [
            'HTTP_HOST' => 'attacker.example',
            'HTTPS' => 'on',
        ]);
        $context = new Context($request);
        $context->setDomain('attacker.example');
        $context->setDomainInfo(new Domain(7, 'shop.example.com'));

        $baseUrl = $this->invokeBuildPaymentBaseUrl($context);

        self::assertSame('https://shop.example.com', $baseUrl);
        self::assertStringNotContainsString('attacker.example', $baseUrl);
    }

    public function testPaymentBaseUrlFailsClosedWithoutStoredDomain(): void
    {
        $context = new Context(new Request('POST', '/shop/checkout', [], [], [
            'HTTP_HOST' => 'attacker.example',
        ]));

        self::assertSame('', $this->invokeBuildPaymentBaseUrl($context));
    }

    private function invokeBuildPaymentBaseUrl(Context $context): string
    {
        $reflection = new ReflectionClass(CartController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        return $reflection->getMethod('buildPaymentBaseUrl')->invoke($controller, $context);
    }
}
