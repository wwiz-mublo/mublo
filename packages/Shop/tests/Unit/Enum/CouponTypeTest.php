<?php
/**
 * packages/Shop/tests/Unit/Enum/CouponTypeTest.php
 *
 * CouponType Enum 단위 테스트
 */

namespace Tests\Shop\Unit\Enum;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Enum\CouponType;

class CouponTypeTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $expected = ['ADMIN', 'AUTO', 'DOWNLOAD'];
        $actual   = array_map(fn(CouponType $c) => $c->value, CouponType::cases());

        $this->assertSame($expected, $actual);
    }

    public function testLabelReturnsKoreanString(): void
    {
        $this->assertSame('관리자 발행', CouponType::ADMIN->label());
        $this->assertSame('자동 발행', CouponType::AUTO->label());
        $this->assertSame('다운로드', CouponType::DOWNLOAD->label());
    }

    public function testOptionsContainsAllCases(): void
    {
        $options = CouponType::options();

        $this->assertCount(3, $options);
        $this->assertArrayHasKey('ADMIN', $options);
        $this->assertArrayHasKey('AUTO', $options);
        $this->assertArrayHasKey('DOWNLOAD', $options);
    }

    public function testOptionsValuesAreLabels(): void
    {
        $options = CouponType::options();

        $this->assertSame('관리자 발행', $options['ADMIN']);
        $this->assertSame('다운로드', $options['DOWNLOAD']);
    }
}
