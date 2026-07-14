<?php

namespace Tests\Unit\Core\Rendering;

use Mublo\Core\Rendering\ThemeSkinPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThemeSkinPolicyTest extends TestCase
{
    #[DataProvider('validNameProvider')]
    public function testAcceptsPortableSkinNames(string $skin): void
    {
        $this->assertTrue(ThemeSkinPolicy::isValidName($skin));
    }

    public static function validNameProvider(): array
    {
        return [
            ['basic'],
            ['mint-combo'],
            ['theme_2026'],
            [str_repeat('a', 64)],
        ];
    }

    #[DataProvider('invalidNameProvider')]
    public function testRejectsTraversalAndNonPortableNames(mixed $skin): void
    {
        $this->assertFalse(ThemeSkinPolicy::isValidName($skin));
    }

    public static function invalidNameProvider(): array
    {
        return [
            'empty' => [''],
            'dot' => ['.'],
            'dot dot' => ['..'],
            'linux separator' => ['../basic'],
            'windows separator' => ['..\\basic'],
            'absolute windows path' => ['C:\\basic'],
            'space' => ['mint combo'],
            'nul' => ["basic\0other"],
            'control' => ["basic\n"],
            'too long' => [str_repeat('a', 65)],
            'non string' => [['basic']],
        ];
    }

    public function testComponentMustExistUnderItsOwnViewGroup(): void
    {
        $this->assertTrue(ThemeSkinPolicy::componentExists('frame', 'basic'));
        $this->assertFalse(ThemeSkinPolicy::componentExists('frame', 'no-such-skin'));
        $this->assertFalse(ThemeSkinPolicy::componentExists('unknown', 'basic'));
    }

    public function testPollutedOrMissingStoredValueFailsClosedToBasic(): void
    {
        $this->assertSame('basic', ThemeSkinPolicy::resolveExisting('frame', '../basic'));
        $this->assertSame('basic', ThemeSkinPolicy::resolveExisting('frame', '..\\basic'));
        $this->assertSame('basic', ThemeSkinPolicy::resolveExisting('frame', 'no-such-skin'));
    }
}
