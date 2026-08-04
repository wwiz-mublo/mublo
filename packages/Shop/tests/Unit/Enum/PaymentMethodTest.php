<?php
/**
 * packages/Shop/tests/Unit/Enum/PaymentMethodTest.php
 *
 * PaymentMethod Enum 단위 테스트
 */

namespace Tests\Shop\Unit\Enum;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Enum\PaymentMethod;

class PaymentMethodTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $expected = ['CARD', 'PHONE', 'VBANK', 'BANK', 'FREE'];
        $actual   = array_map(fn(PaymentMethod $m) => $m->value, PaymentMethod::cases());

        $this->assertSame($expected, $actual);
    }

    public function testLabelReturnsKoreanString(): void
    {
        $this->assertSame('신용카드', PaymentMethod::CARD->label());
        $this->assertSame('휴대폰 결제', PaymentMethod::PHONE->label());
        $this->assertSame('가상계좌', PaymentMethod::VBANK->label());
        $this->assertSame('무통장입금', PaymentMethod::BANK->label());
        $this->assertSame('0원 결제', PaymentMethod::FREE->label());
    }

    public function testOptionsContainsSelectableCases(): void
    {
        $options = PaymentMethod::options();

        $this->assertCount(4, $options);
        $this->assertArrayHasKey('CARD', $options);
        $this->assertArrayHasKey('PHONE', $options);
        $this->assertArrayHasKey('VBANK', $options);
        $this->assertArrayHasKey('BANK', $options);
    }

    public function testOptionsExcludesSystemOnlyFree(): void
    {
        // FREE 는 시스템 전용(0원 결제) — 사용자 선택 목록에 노출되면 안 된다.
        $this->assertArrayNotHasKey('FREE', PaymentMethod::options());
    }

    public function testOptionsValuesAreLabels(): void
    {
        $options = PaymentMethod::options();

        $this->assertSame('신용카드', $options['CARD']);
        $this->assertSame('무통장입금', $options['BANK']);
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        $this->assertNull(PaymentMethod::tryFrom('CRYPTO'));
        $this->assertNull(PaymentMethod::tryFrom(''));
    }
}
