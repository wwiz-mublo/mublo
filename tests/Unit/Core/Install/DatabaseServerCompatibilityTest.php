<?php

namespace Tests\Unit\Core\Install;

use Mublo\Core\Install\DatabaseServerCompatibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DatabaseServerCompatibilityTest extends TestCase
{
    private DatabaseServerCompatibility $compatibility;

    protected function setUp(): void
    {
        $this->compatibility = new DatabaseServerCompatibility();
    }

    #[DataProvider('supportedServers')]
    public function testSupportedServerVersions(string $raw, string $engine, string $version): void
    {
        $result = $this->compatibility->inspect($raw);

        $this->assertTrue($result['supported']);
        $this->assertSame($engine, $result['engine']);
        $this->assertSame($version, $result['version']);
    }

    public static function supportedServers(): array
    {
        return [
            'mysql minimum' => ['5.7.8-log', 'mysql', '5.7.8'],
            'mysql latest 5.7' => ['5.7.44-0ubuntu0.18.04.1', 'mysql', '5.7.44'],
            'mysql lts' => ['8.4.4', 'mysql', '8.4.4'],
            'mariadb minimum' => ['10.3.0-MariaDB', 'mariadb', '10.3.0'],
            'mariadb compatibility prefix' => ['5.5.5-10.3.39-MariaDB-0+deb10u2', 'mariadb', '10.3.39'],
            'mariadb lts' => ['11.4.5-MariaDB-ubu2404', 'mariadb', '11.4.5'],
        ];
    }

    #[DataProvider('unsupportedServers')]
    public function testUnsupportedServerVersions(
        string $raw,
        string $engine,
        ?string $version,
        ?string $minimum
    ): void {
        $result = $this->compatibility->inspect($raw);

        $this->assertFalse($result['supported']);
        $this->assertSame($engine, $result['engine']);
        $this->assertSame($version, $result['version']);
        $this->assertSame($minimum, $result['minimum']);
    }

    public static function unsupportedServers(): array
    {
        return [
            'mysql before json type' => ['5.7.7', 'mysql', '5.7.7', '5.7.8'],
            'old mysql' => ['5.6.51-log', 'mysql', '5.6.51', '5.7.8'],
            'old mariadb' => ['5.5.5-10.2.44-MariaDB', 'mariadb', '10.2.44', '10.3.0'],
            'unknown' => ['not-a-version', 'unknown', null, null],
        ];
    }

    public function testTwoPartVersionIsNormalized(): void
    {
        $result = $this->compatibility->inspect('8.4');

        $this->assertTrue($result['supported']);
        $this->assertSame('8.4.0', $result['version']);
    }
}
