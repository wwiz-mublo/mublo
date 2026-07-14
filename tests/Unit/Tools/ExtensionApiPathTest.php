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
}
