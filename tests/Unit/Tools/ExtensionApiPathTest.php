<?php

namespace Tests\Unit\Tools;

use Mublo\Tools\ExtensionApiPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/ExtensionApiPath.php';

final class ExtensionApiPathTest extends TestCase
{
    #[DataProvider('pathProvider')]
    public function testItCalculatesPortableRootRelativePaths(
        string $path,
        string $basePath,
        string $expected
    ): void {
        $this->assertSame($expected, ExtensionApiPath::relativeTo($path, $basePath));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function pathProvider(): iterable
    {
        yield 'Windows mixed separators' => [
            'D:\\project\\mublo\\mublo/packages\\Board\\tests\\Unit\\ExampleTest.php',
            'D:\\project\\mublo\\mublo',
            'packages/Board/tests/Unit/ExampleTest.php',
        ];
        yield 'Windows drive letter casing' => [
            'd:/project/mublo/mublo/plugins/Manual/ManualProvider.php',
            'D:\\project\\mublo\\mublo\\',
            'plugins/Manual/ManualProvider.php',
        ];
        yield 'Unix separators' => [
            '/workspace/mublo/plugins/Manual/ManualProvider.php',
            '/workspace/mublo',
            'plugins/Manual/ManualProvider.php',
        ];
        yield 'Outside root remains absolute but normalized' => [
            'D:\\elsewhere\\Example.php',
            'D:\\project\\mublo\\mublo',
            'D:/elsewhere/Example.php',
        ];
    }

    #[DataProvider('classificationProvider')]
    public function testItClassifiesExtensionOwnershipAndTests(
        string $path,
        ?string $owner,
        ?string $baselineFile,
        ?string $nestedPlugin,
        bool $test
    ): void {
        $classification = ExtensionApiPath::classify($path);

        if ($owner === null) {
            $this->assertNull($classification);
            return;
        }

        $this->assertSame($owner, $classification['owner']);
        $this->assertSame($baselineFile, $classification['baselineFile']);
        $this->assertSame($nestedPlugin, $classification['nestedPlugin']);
        $this->assertSame($test, $classification['test']);
        $this->assertSame($owner, ExtensionApiPath::ownerOf($path));
        $this->assertSame($baselineFile, ExtensionApiPath::baselineFileFor($path));
        $this->assertSame($test, ExtensionApiPath::isTestPath($path));
    }

    /** @return iterable<string, array{string, ?string, ?string, ?string, bool}> */
    public static function classificationProvider(): iterable
    {
        yield 'Package operation code' => [
            'packages/Rental/Service/OrderService.php',
            'package-Rental',
            'package-Rental.json',
            null,
            false,
        ];
        yield 'Package test' => [
            'packages/Rental/tests/Unit/OrderServiceTest.php',
            'package-Rental',
            'package-Rental.json',
            null,
            true,
        ];
        yield 'Independent Plugin test with Windows separators' => [
            'plugins\\Banner\\tests\\Unit\\BannerTest.php',
            'plugin-Banner',
            'plugin-Banner.json',
            null,
            true,
        ];
        yield 'Nested Package Plugin operation code' => [
            'packages/Board/Plugins/BoardReport/Service/BoardReportService.php',
            'package-Board',
            'package-Board.json',
            'BoardReport',
            false,
        ];
        yield 'Nested Package Plugin test' => [
            'packages/Board/Plugins/BoardReport/tests/Unit/BoardReportTest.php',
            'package-Board',
            'package-Board.json',
            'BoardReport',
            true,
        ];
        yield 'Core path is not extension owned' => [
            'src/Service/Auth/AuthService.php',
            null,
            null,
            null,
            false,
        ];
    }
}
