<?php
/**
 * packages/Shop/tests/Unit/Enum/DiscountTypeTest.php
 *
 * DiscountType Enum 단위 테스트
 */

namespace Tests\Shop\Unit\Enum;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Enum\DiscountType;

class DiscountTypeTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $expected = ['NONE', 'DEFAULT', 'BASIC', 'LEVEL', 'PERCENTAGE', 'FIXED'];
        $actual   = array_map(fn(DiscountType $d) => $d->value, DiscountType::cases());

        foreach ($expected as $value) {
            $this->assertContains($value, $actual);
        }
    }

    public function testLabelReturnsKoreanString(): void
    {
        $this->assertSame('할인 없음', DiscountType::NONE->label());
        $this->assertSame('쇼핑몰 기본설정 적용', DiscountType::DEFAULT->label());
        $this->assertSame('기본 할인', DiscountType::BASIC->label());
        $this->assertSame('등급별 할인', DiscountType::LEVEL->label());
        $this->assertSame('정률 할인', DiscountType::PERCENTAGE->label());
        $this->assertSame('정액 할인', DiscountType::FIXED->label());
    }

    public function testIsApplicableReturnsFalseForNone(): void
    {
        $this->assertFalse(DiscountType::NONE->isApplicable());
    }

    public function testIsApplicableReturnsTrueForOthers(): void
    {
        $applicable = [
            DiscountType::DEFAULT, DiscountType::BASIC, DiscountType::LEVEL,
            DiscountType::PERCENTAGE, DiscountType::FIXED,
        ];

        foreach ($applicable as $type) {
            $this->assertTrue($type->isApplicable(), "{$type->value}는 적용 가능해야 합니다.");
        }
    }

    public function testProductOptionsContainsDefaultAndExcludesBasic(): void
    {
        $options = DiscountType::productOptions();

        // 상품 등록 폼에는 DEFAULT 포함
        $this->assertArrayHasKey('DEFAULT', $options);

        // BASIC은 내부용이므로 미포함
        $this->assertArrayNotHasKey('BASIC', $options);
    }

    public function testOptionsContainsAllCases(): void
    {
        $options = DiscountType::options();

        $this->assertCount(count(DiscountType::cases()), $options);
        $this->assertArrayHasKey('NONE', $options);
        $this->assertArrayHasKey('PERCENTAGE', $options);
        $this->assertArrayHasKey('FIXED', $options);
    }
}
