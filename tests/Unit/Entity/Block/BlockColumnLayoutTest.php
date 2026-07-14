<?php

namespace Tests\Unit\Entity\Block;

use Mublo\Entity\Block\BlockColumn;
use PHPUnit\Framework\TestCase;

class BlockColumnLayoutTest extends TestCase
{
    public function testLayoutDefaultsAreStableWhenConfigIsEmpty(): void
    {
        $column = BlockColumn::fromArray(['content_config' => '{}']);

        $this->assertSame('list', $column->getPcStyle());
        $this->assertSame('list', $column->getMoStyle());
        $this->assertSame('4', $column->getPcCols());
        $this->assertSame('2', $column->getMoCols());
        $this->assertSame(0, $column->getPcAutoplay());
        $this->assertFalse($column->getPcLoop());
    }

    public function testInvalidPersistedLayoutValuesFallBackToSafeRuntimeValues(): void
    {
        $column = BlockColumn::fromArray(['content_config' => [
            'pc_style' => 'javascript:alert(1)',
            'mo_style' => 3,
            'pc_cols' => '999',
            'mo_cols' => '2.5',
            'pc_autoplay' => -100,
            'mo_autoplay' => 999999,
            'pc_loop' => 'false',
            'mo_loop' => 'true',
            'pc_slide_cover' => '0',
            'mo_slide_cover' => '1',
            'aos_duration' => 999999,
        ]]);

        $this->assertSame('list', $column->getPcStyle());
        $this->assertSame('list', $column->getMoStyle());
        $this->assertSame('4', $column->getPcCols());
        $this->assertSame('2', $column->getMoCols());
        $this->assertSame(0, $column->getPcAutoplay());
        $this->assertSame(30000, $column->getMoAutoplay());
        $this->assertFalse($column->getPcLoop());
        $this->assertTrue($column->getMoLoop());
        $this->assertFalse($column->getPcSlideCover());
        $this->assertTrue($column->getMoSlideCover());
        $this->assertSame(3000, $column->getAosDuration());
    }

    public function testValidLayoutValuesAndAutoColumnsArePreserved(): void
    {
        $column = BlockColumn::fromArray(['content_config' => [
            'pc_style' => 'slide',
            'mo_style' => 'none',
            'pc_cols' => 'auto',
            'mo_cols' => 12,
            'pc_autoplay' => '5000',
            'pc_loop' => true,
        ]]);

        $this->assertSame('slide', $column->getPcStyle());
        $this->assertSame('none', $column->getMoStyle());
        $this->assertSame('auto', $column->getPcCols());
        $this->assertSame('12', $column->getMoCols());
        $this->assertSame(5000, $column->getPcAutoplay());
        $this->assertTrue($column->getPcLoop());
    }

    public function testDataAttributesOnlyContainNormalizedLayoutValues(): void
    {
        $column = BlockColumn::fromArray(['content_config' => [
            'pc_style' => '\" onmouseover=\"alert(1)',
            'mo_style' => 'slide',
            'pc_cols' => '<script>',
            'mo_cols' => 'auto',
            'mo_loop' => 'true',
        ]]);

        $attributes = $column->getLayoutDataAttributes();

        $this->assertStringContainsString('data-pc-style="list"', $attributes);
        $this->assertStringContainsString('data-mo-style="slide"', $attributes);
        $this->assertStringContainsString('data-pc-cols="4"', $attributes);
        $this->assertStringContainsString('data-mo-cols="auto"', $attributes);
        $this->assertStringContainsString('data-mo-loop="true"', $attributes);
        $this->assertStringNotContainsString('onmouseover', $attributes);
        $this->assertStringNotContainsString('<script>', $attributes);
    }
}
