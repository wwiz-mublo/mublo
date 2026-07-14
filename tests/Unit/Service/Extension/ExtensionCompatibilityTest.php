<?php

namespace Tests\Unit\Service\Extension;

use Mublo\Service\Extension\ExtensionCompatibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExtensionCompatibilityTest extends TestCase
{
    private ExtensionCompatibility $checker;

    protected function setUp(): void
    {
        $this->checker = new ExtensionCompatibility();
    }

    // ========================================
    // 제약 문법
    // ========================================

    #[DataProvider('satisfiedConstraints')]
    public function testConstraintIsSatisfied(string $version, string $constraint): void
    {
        $this->assertTrue(
            $this->checker->satisfies($version, $constraint),
            "{$version} 은(는) {$constraint} 을(를) 만족해야 한다"
        );
    }

    #[DataProvider('unsatisfiedConstraints')]
    public function testConstraintIsNotSatisfied(string $version, string $constraint): void
    {
        $this->assertFalse(
            $this->checker->satisfies($version, $constraint),
            "{$version} 은(는) {$constraint} 을(를) 만족하면 안 된다"
        );
    }

    public static function satisfiedConstraints(): array
    {
        return [
            'wildcard'          => ['1.0.0', '*'],
            'empty'             => ['1.0.0', ''],
            'exact'             => ['1.2.3', '1.2.3'],
            'gte equal'         => ['1.0.0', '>=1.0.0'],
            'gte greater'       => ['1.4.0', '>=1.0.0'],
            'gt'                => ['1.0.1', '>1.0.0'],
            'lte'               => ['1.0.0', '<=1.0.0'],
            'lt'                => ['0.9.0', '<1.0.0'],
            'neq'               => ['1.0.1', '!=1.0.0'],
            'spaced operator'   => ['1.4.0', '>= 1.0.0'],
            'caret same major'  => ['1.9.9', '^1.2.3'],
            'caret lower bound' => ['1.2.3', '^1.2.3'],
            'caret zero minor'  => ['0.2.9', '^0.2.3'],
            'tilde patch'       => ['1.2.9', '~1.2.3'],
            'tilde minor'       => ['1.9.0', '~1.2'],
            'and range'         => ['1.5.0', '>=1.0.0 <2.0.0'],
            'and comma'         => ['1.5.0', '>=1.0.0,<2.0.0'],
            'or first'          => ['1.0.0', '^1.0 || ^2.0'],
            'or second'         => ['2.3.0', '^1.0 || ^2.0'],
            'unparseable'       => ['1.0.0', 'dev-main'],
        ];
    }

    public static function unsatisfiedConstraints(): array
    {
        return [
            'exact mismatch'     => ['1.2.4', '1.2.3'],
            'gte below'          => ['0.9.0', '>=1.0.0'],
            'gt equal'           => ['1.0.0', '>1.0.0'],
            'lt equal'           => ['1.0.0', '<1.0.0'],
            'neq equal'          => ['1.0.0', '!=1.0.0'],
            'caret major bump'   => ['2.0.0', '^1.2.3'],
            'caret below base'   => ['1.2.2', '^1.2.3'],
            'caret zero minor'   => ['0.3.0', '^0.2.3'],
            'tilde minor bump'   => ['1.3.0', '~1.2.3'],
            'tilde major bump'   => ['2.0.0', '~1.2'],
            'and out of range'   => ['2.0.0', '>=1.0.0 <2.0.0'],
            'or neither'         => ['3.0.0', '^1.0 || ^2.0'],
        ];
    }

    /**
     * 파서가 못 읽는 제약 때문에 멀쩡한 확장을 막으면 안 된다.
     */
    public function testUnparseableConstraintDoesNotBlock(): void
    {
        $this->assertTrue($this->checker->satisfies('1.0.0', 'dev-main'));
        $this->assertTrue($this->checker->satisfies('1.0.0', '@stable'));
    }

    // ========================================
    // requires 검사
    // ========================================

    public function testNoRequiresMeansCompatible(): void
    {
        $this->assertSame([], $this->checker->check([], '1.0.0'));
        $this->assertSame([], $this->checker->check(['requires' => []], '1.0.0'));
    }

    public function testCoreVersionTooOldIsReported(): void
    {
        $reasons = $this->checker->check(['requires' => ['core' => '>=2.0.0']], '1.0.0');

        $this->assertCount(1, $reasons);
        $this->assertStringContainsString('코어 >=2.0.0', $reasons[0]);
        $this->assertStringContainsString('현재 1.0.0', $reasons[0]);
    }

    public function testCoreVersionSatisfiedIsSilent(): void
    {
        $this->assertSame([], $this->checker->check(['requires' => ['core' => '>=1.0.0']], '1.0.0'));
    }

    public function testMissingPackageDependencyIsReported(): void
    {
        $reasons = $this->checker->check(
            ['requires' => ['core' => '>=1.0.0', 'package:Shop' => '>=1.0.0']],
            '1.0.0'
        );

        $this->assertCount(1, $reasons);
        $this->assertStringContainsString("패키지 'Shop'", $reasons[0]);
        $this->assertStringContainsString('설치되어 있지 않습니다', $reasons[0]);
    }

    public function testPackageDependencyVersionTooOldIsReported(): void
    {
        $reasons = $this->checker->check(
            ['requires' => ['package:Shop' => '>=2.0.0']],
            '1.0.0',
            ['package:Shop' => '1.0.0']
        );

        $this->assertCount(1, $reasons);
        $this->assertStringContainsString("패키지 'Shop' >=2.0.0", $reasons[0]);
    }

    public function testSatisfiedPackageDependencyIsSilent(): void
    {
        $reasons = $this->checker->check(
            ['requires' => ['core' => '>=1.0.0', 'package:Board' => '^1.0']],
            '1.0.0',
            ['package:Board' => '1.2.0']
        );

        $this->assertSame([], $reasons);
    }

    public function testPluginDependencyUsesPluginLabel(): void
    {
        $reasons = $this->checker->check(['requires' => ['plugin:Banner' => '>=1.0.0']], '1.0.0');

        $this->assertStringContainsString("플러그인 'Banner'", $reasons[0]);
    }

    public function testMultipleFailuresAreAllReported(): void
    {
        $reasons = $this->checker->check(
            ['requires' => ['core' => '>=2.0.0', 'package:Shop' => '>=1.0.0']],
            '1.0.0'
        );

        $this->assertCount(2, $reasons);
    }

    public function testUnknownRequiresKeyIsIgnored(): void
    {
        $this->assertSame([], $this->checker->check(['requires' => ['php' => '>=8.9']], '1.0.0'));
    }

    public function testNonStringConstraintIsIgnored(): void
    {
        $this->assertSame([], $this->checker->check(['requires' => ['core' => ['>=2.0.0']]], '1.0.0'));
    }

    /**
     * 번들 확장이 모두 현재 코어와 호환되어야 한다 — 회귀 방지.
     */
    public function testBundledExtensionsAreCompatibleWithCurrentCore(): void
    {
        $core = \Mublo\Core\App\Application::VERSION;
        $files = array_merge(
            glob(MUBLO_ROOT_PATH . '/plugins/*/manifest.json') ?: [],
            glob(MUBLO_ROOT_PATH . '/packages/*/manifest.json') ?: []
        );

        $this->assertNotEmpty($files, '번들 확장을 찾지 못했다');

        $installedVersions = [];
        foreach ($files as $file) {
            $installedManifest = json_decode((string) file_get_contents($file), true);
            $kind = basename(dirname(dirname($file))) === 'packages' ? 'package' : 'plugin';
            $name = (string) ($installedManifest['name'] ?? basename(dirname($file)));
            $installedVersions[$kind . ':' . $name] = (string) ($installedManifest['version'] ?? '0.0.0');
        }

        foreach ($files as $file) {
            $manifest = json_decode(file_get_contents($file), true);
            $reasons = $this->checker->check($manifest, $core, $installedVersions);

            $this->assertSame([], $reasons, basename(dirname($file)) . ': ' . implode(' / ', $reasons));
        }
    }

    public function testBundledTopLevelExtensionsDoNotDependOnOtherExtensions(): void
    {
        $files = array_merge(
            glob(MUBLO_ROOT_PATH . '/plugins/*/manifest.json') ?: [],
            glob(MUBLO_ROOT_PATH . '/packages/*/manifest.json') ?: []
        );

        foreach ($files as $file) {
            $manifest = json_decode((string) file_get_contents($file), true);
            $requires = is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [];
            $crossDependencies = array_values(array_filter(
                array_keys($requires),
                static fn(string $target): bool => str_starts_with($target, 'package:')
                    || str_starts_with($target, 'plugin:'),
            ));
            $this->assertSame(
                [],
                $crossDependencies,
                basename(dirname($file)) . ' manifest는 다른 확장에 의존할 수 없습니다.',
            );
        }
    }
}
