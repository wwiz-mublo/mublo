<?php

namespace Tests\Unit\Tools;

use Mublo\Tools\ExtensionApiBaseline;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/ExtensionApiPath.php';
require_once dirname(__DIR__, 3) . '/tools/ExtensionApiBaseline.php';

final class ExtensionApiBaselineTest extends TestCase
{
    private string $basePath;
    private string $baselineDirectory;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mublo-extension-api-' . bin2hex(random_bytes(8));
        $this->baselineDirectory = $this->basePath . '/tools/extension-api-baseline';
        mkdir($this->basePath . '/packages/Rental', 0777, true);
        mkdir($this->basePath . '/plugins/Banner', 0777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->basePath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->basePath);
    }

    public function testItWritesOwnerShardsInDeterministicOrderAndReadsThem(): void
    {
        $result = ExtensionApiBaseline::rewrite($this->baselineDirectory, [
            ['file' => 'packages/Rental/Z.php', 'symbol' => 'Mublo\\Service\\Menu\\MenuService'],
            ['file' => 'plugins/Banner/A.php', 'symbol' => 'Mublo\\Service\\Block\\BlockRenderService'],
            ['file' => 'packages/Rental/A.php', 'symbol' => 'Mublo\\Service\\Auth\\AuthService'],
        ]);

        $this->assertSame(['files' => 2, 'occurrences' => 3], $result);
        $this->assertFileExists($this->baselineDirectory . '/package-Rental.json');
        $this->assertFileExists($this->baselineDirectory . '/plugin-Banner.json');

        $rental = json_decode(
            (string) file_get_contents($this->baselineDirectory . '/package-Rental.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame('packages/Rental/A.php', $rental['occurrences'][0]['file']);
        $this->assertSame('packages/Rental/Z.php', $rental['occurrences'][1]['file']);

        $loaded = ExtensionApiBaseline::read($this->baselineDirectory, $this->basePath);
        $this->assertSame([], $loaded['errors']);
        $this->assertCount(3, $loaded['occurrences']);
        $this->assertSame(2, $loaded['files']);
    }

    public function testItDeletesAZeroOccurrenceShard(): void
    {
        ExtensionApiBaseline::rewrite($this->baselineDirectory, [
            ['file' => 'packages/Rental/A.php', 'symbol' => 'Mublo\\Service\\Auth\\AuthService'],
        ]);
        $this->assertFileExists($this->baselineDirectory . '/package-Rental.json');

        ExtensionApiBaseline::rewrite($this->baselineDirectory, []);

        $this->assertFileDoesNotExist($this->baselineDirectory . '/package-Rental.json');
    }

    public function testItRejectsWrongOwnershipEmptyAndOrphanShards(): void
    {
        mkdir($this->baselineDirectory, 0777, true);
        $this->writeBaseline('plugin-Banner', [
            ['file' => 'packages/Rental/A.php', 'symbol' => 'Mublo\\Service\\Auth\\AuthService'],
        ]);
        $this->writeBaseline('package-Rental', []);
        $this->writeBaseline('package-Gone', [
            ['file' => 'packages/Gone/A.php', 'symbol' => 'Mublo\\Service\\Menu\\MenuService'],
        ]);

        $loaded = ExtensionApiBaseline::read($this->baselineDirectory, $this->basePath);
        $messages = implode("\n", $loaded['errors']);

        $this->assertStringContainsString('잘못된 소유 occurrence', $messages);
        $this->assertStringContainsString('occurrence가 0건인 빈 baseline 파일', $messages);
        $this->assertStringContainsString('고아 baseline 파일', $messages);
    }

    /** @param array<int, array{file: string, symbol: string}> $occurrences */
    private function writeBaseline(string $owner, array $occurrences): void
    {
        file_put_contents(
            $this->baselineDirectory . '/' . $owner . '.json',
            json_encode(
                ['owner' => $owner, 'occurrences' => $occurrences],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n"
        );
    }
}
