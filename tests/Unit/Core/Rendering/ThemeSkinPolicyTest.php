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

    /**
     * `super_` 프리픽스 = SUPER 전용 스킨 관례
     *
     * `_` 프리픽스(스킨이 아닌 디렉터리 숨김 — _assets·_shared)와는 별개
     * 선언이다. super_ 스킨은 스킨이다 — SUPER 의 목록에는 나타나고, 그 외 도메인
     * 목록·저장에서 걸러진다.
     */
    public function testSuperOnlyConventionIsThePrefix(): void
    {
        $this->assertTrue(ThemeSkinPolicy::isSuperOnly('super_saas'));
        $this->assertTrue(ThemeSkinPolicy::isSuperOnly('super_'));

        $this->assertFalse(ThemeSkinPolicy::isSuperOnly('basic'));
        $this->assertFalse(ThemeSkinPolicy::isSuperOnly('superman'), '프리픽스는 super_ 다 — super 로 시작한다고 전용이 아니다');
        $this->assertFalse(ThemeSkinPolicy::isSuperOnly('_assets'), '_ 는 비스킨 숨김 관례 — 섞이면 안 된다');
        $this->assertFalse(ThemeSkinPolicy::isSuperOnly(null));
        $this->assertFalse(ThemeSkinPolicy::isSuperOnly(['super_x']));
    }
}
