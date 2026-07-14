<?php
/**
 * packages/Shop/tests/Unit/Enum/ShippingMethodTest.php
 *
 * ShippingMethod Enum 단위 테스트
 */

namespace Tests\Shop\Unit\Enum;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Enum\ShippingMethod;

class ShippingMethodTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $expected = ['FREE', 'COND', 'PAID', 'QUANTITY', 'AMOUNT'];
        $actual   = array_map(fn(ShippingMethod $m) => $m->value, ShippingMethod::cases());

        foreach ($expected as $value) {
            $this->assertContains($value, $actual);
        }
    }

    public function testLabelReturnsKoreanString(): void
    {
        $this->assertSame('무료 배송', ShippingMethod::FREE->label());
        $this->assertSame('조건부 무료', ShippingMethod::COND->label());
        $this->assertSame('유료 배송', ShippingMethod::PAID->label());
        $this->assertSame('수량별 배송', ShippingMethod::QUANTITY->label());
        $this->assertSame('금액별 배송', ShippingMethod::AMOUNT->label());
    }

    public function testIsFreeOnlyForFreeMethod(): void
    {
        $this->assertTrue(ShippingMethod::FREE->isFree());

        $notFree = [ShippingMethod::COND, ShippingMethod::PAID, ShippingMethod::QUANTITY, ShippingMethod::AMOUNT];
        foreach ($notFree as $method) {
            $this->assertFalse($method->isFree(), "{$method->value}는 무료 배송이 아니어야 합니다.");
        }
    }

    public function testOptionsContainsAllCases(): void
    {
        $options = ShippingMethod::options();

        $this->assertCount(5, $options);
        foreach (['FREE', 'COND', 'PAID', 'QUANTITY', 'AMOUNT'] as $key) {
            $this->assertArrayHasKey($key, $options);
        }
    }
}
