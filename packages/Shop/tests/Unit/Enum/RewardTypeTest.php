<?php
/**
 * packages/Shop/tests/Unit/Enum/RewardTypeTest.php
 *
 * RewardType Enum 단위 테스트
 */

namespace Tests\Shop\Unit\Enum;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Enum\RewardType;

class RewardTypeTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $expected = ['NONE', 'DEFAULT', 'BASIC', 'LEVEL', 'PERCENTAGE', 'FIXED'];
        $actual   = array_map(fn(RewardType $r) => $r->value, RewardType::cases());

        foreach ($expected as $value) {
            $this->assertContains($value, $actual);
        }
    }

    public function testLabelReturnsKoreanString(): void
    {
        $this->assertSame('적립 없음', RewardType::NONE->label());
        $this->assertSame('쇼핑몰 기본설정 적용', RewardType::DEFAULT->label());
        $this->assertSame('기본 적립', RewardType::BASIC->label());
        $this->assertSame('등급별 적립', RewardType::LEVEL->label());
        $this->assertSame('정률 적립', RewardType::PERCENTAGE->label());
        $this->assertSame('정액 적립', RewardType::FIXED->label());
    }

    public function testIsApplicableReturnsFalseForNone(): void
    {
        $this->assertFalse(RewardType::NONE->isApplicable());
    }

    public function testIsApplicableReturnsTrueForOthers(): void
    {
        $applicable = [
            RewardType::DEFAULT, RewardType::BASIC, RewardType::LEVEL,
            RewardType::PERCENTAGE, RewardType::FIXED,
        ];

        foreach ($applicable as $type) {
            $this->assertTrue($type->isApplicable(), "{$type->value}는 적용 가능해야 합니다.");
        }
    }

    public function testProductOptionsContainsDefaultAndExcludesBasic(): void
    {
        $options = RewardType::productOptions();

        $this->assertArrayHasKey('DEFAULT', $options);
        $this->assertArrayNotHasKey('BASIC', $options);
    }

    public function testOptionsContainsAllCases(): void
    {
        $options = RewardType::options();

        $this->assertCount(count(RewardType::cases()), $options);
    }
}
