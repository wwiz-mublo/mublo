<?php
/**
 * packages/Shop/tests/Unit/Enum/OptionModeTest.php
 *
 * OptionMode Enum 단위 테스트
 */

namespace Tests\Shop\Unit\Enum;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Enum\OptionMode;

class OptionModeTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $values = array_map(fn(OptionMode $m) => $m->value, OptionMode::cases());

        $this->assertContains('NONE', $values);
        $this->assertContains('SINGLE', $values);
        $this->assertContains('COMBINATION', $values);
    }

    public function testLabelReturnsKoreanString(): void
    {
        $this->assertSame('옵션 없음', OptionMode::NONE->label());
        $this->assertSame('단독형', OptionMode::SINGLE->label());
        $this->assertSame('조합형', OptionMode::COMBINATION->label());
    }

    public function testHasOptionsReturnsFalseForNone(): void
    {
        $this->assertFalse(OptionMode::NONE->hasOptions());
    }

    public function testHasOptionsReturnsTrueForOthers(): void
    {
        $this->assertTrue(OptionMode::SINGLE->hasOptions());
        $this->assertTrue(OptionMode::COMBINATION->hasOptions());
    }

    public function testOptionsContainsAllCases(): void
    {
        $options = OptionMode::options();

        $this->assertCount(3, $options);
        $this->assertArrayHasKey('NONE', $options);
        $this->assertArrayHasKey('SINGLE', $options);
        $this->assertArrayHasKey('COMBINATION', $options);
    }
}
