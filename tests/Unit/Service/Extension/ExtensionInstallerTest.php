<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Extension;

use Mublo\Service\Extension\ExtensionCompatibility;
use Mublo\Service\Extension\ExtensionInstaller;
use Mublo\Service\Extension\ExtensionPackageVerifier;
use PHPUnit\Framework\TestCase;

/**
 * 확장 zip 설치 계약
 *
 * 확장은 커뮤니티 zip 으로 배포된다. 압축 해제는 곧 코드 설치이므로
 * 경로 탈출·구조 위반·비호환 zip 은 어떤 파일도 남기지 않고 거부되어야 한다.
 */
class ExtensionInstallerTest extends TestCase
{
    private string $basePath;
    private string $pluginPath;
    private string $packagePath;
    private string $zipDir;

    protected function setUp(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip 확장이 없습니다.');
        }

        $this->basePath = sys_get_temp_dir() . '/mublo-installer-' . bin2hex(random_bytes(6));
        $this->pluginPath = $this->basePath . '/plugins';
        $this->packagePath = $this->basePath . '/packages';
        $this->zipDir = $this->basePath . '/zips';

        mkdir($this->pluginPath, 0777, true);
        mkdir($this->packagePath, 0777, true);
        mkdir($this->zipDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->basePath);
    }

    // =========================================================================
    // 정상 설치
    // =========================================================================

    public function testInstallsValidPluginZip(): void
    {
        $zip = $this->makeZip([
            'Banner/manifest.json' => json_encode(['type' => 'plugin', 'label' => '배너', 'version' => '1.0.0']),
            'Banner/BannerProvider.php' => '<?php // provider',
            'Banner/views/Block/banner/basic/basic.php' => '<?php // skin',
        ]);

        $result = $this->installer()->installFromZip($zip, 'plugin');

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertSame('Banner', $result->getData()['name']);
        $this->assertSame('unsigned', $result->getData()['verification']['status']);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $result->getData()['verification']['payload_sha256']
        );
        $this->assertFileExists($this->pluginPath . '/Banner/manifest.json');
        $this->assertFileExists($this->pluginPath . '/Banner/views/Block/banner/basic/basic.php');
        $this->assertSame([], glob($this->pluginPath . '/.upload-*'), '임시 디렉토리가 정리되어야 한다');
    }

    public function testInstallsPackageIntoPackagePath(): void
    {
        $zip = $this->makeZip([
            'Shop2/manifest.json' => json_encode(['type' => 'package', 'label' => '샵2']),
        ]);

        $result = $this->installer()->installFromZip($zip, 'package');

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertFileExists($this->packagePath . '/Shop2/manifest.json');
        $this->assertDirectoryDoesNotExist($this->pluginPath . '/Shop2');
    }

    public function testRequiredSignaturePolicyRejectsUnsignedZip(): void
    {
        $zip = $this->makeZip([
            'Unsigned/manifest.json' => json_encode(['type' => 'plugin', 'label' => 'Unsigned']),
        ]);
        $verifier = new ExtensionPackageVerifier([
            'require_signature' => true,
            'publishers' => [],
        ]);

        $result = $this->installer($verifier)->installFromZip($zip, 'plugin');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('서명된 확장만', $result->getMessage());
    }

    public function testInstallsZipSignedByTrustedPublisher(): void
    {
        [$privateKey, $publicKey] = $this->makeSigningKeyPair();
        $verifier = $this->trustedVerifier($publicKey);
        $zip = $this->makeSignedZip([
            'Signed/manifest.json' => json_encode(['type' => 'plugin', 'label' => 'Signed']),
            'Signed/SignedProvider.php' => '<?php // signed provider',
        ], $privateKey, $verifier, 'Signed');

        $result = $this->installer($verifier)->installFromZip(
            $zip,
            'plugin',
            'official-marketplace'
        );

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertSame('verified', $result->getData()['verification']['status']);
        $this->assertSame('Test Publisher', $result->getData()['verification']['publisher']);
        $this->assertSame('test:key-1', $result->getData()['verification']['key_id']);
    }

    public function testRejectsSignedZipWhenPayloadWasModified(): void
    {
        [$privateKey, $publicKey] = $this->makeSigningKeyPair();
        $verifier = $this->trustedVerifier($publicKey);
        $zipPath = $this->makeSignedZip([
            'Tampered/manifest.json' => json_encode(['type' => 'plugin', 'label' => 'Tampered']),
            'Tampered/TamperedProvider.php' => '<?php // original',
        ], $privateKey, $verifier, 'Tampered');

        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $zip->addFromString('Tampered/TamperedProvider.php', '<?php // modified');
        $zip->close();

        $result = $this->installer($verifier)->installFromZip(
            $zipPath,
            'plugin',
            'official-marketplace'
        );

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('checksum', $result->getMessage());
        $this->assertDirectoryDoesNotExist($this->pluginPath . '/Tampered');
    }

    public function testRejectsPublisherFromDisallowedSource(): void
    {
        [$privateKey, $publicKey] = $this->makeSigningKeyPair();
        $verifier = $this->trustedVerifier($publicKey);
        $zip = $this->makeSignedZip([
            'SourceBound/manifest.json' => json_encode(['type' => 'plugin']),
        ], $privateKey, $verifier, 'SourceBound');

        $result = $this->installer($verifier)->installFromZip($zip, 'plugin', 'manual-upload');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('허용되지 않은 설치 출처', $result->getMessage());
    }

    public function testIgnoresMacosMetadataEntries(): void
    {
        // macOS Finder 압축은 __MACOSX/ 와 .DS_Store 를 끼워 넣는다 — 정상 zip 으로 취급해야 한다
        $zip = $this->makeZip([
            'Banner/manifest.json' => json_encode(['type' => 'plugin', 'label' => '배너']),
            'Banner/.DS_Store' => 'junk',
            '__MACOSX/Banner/._manifest.json' => 'junk',
            '__MACOSX/._Banner' => 'junk',
        ]);

        $result = $this->installer()->installFromZip($zip, 'plugin');

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertFileExists($this->pluginPath . '/Banner/manifest.json');
        $this->assertDirectoryDoesNotExist($this->pluginPath . '/__MACOSX');
        $this->assertDirectoryDoesNotExist($this->basePath . '/__MACOSX');
        // rootDir 안쪽 junk 도 추출되지 않아야 한다(서명 커버리지 == 추출 커버리지 불변식).
        // 이 단언이 없으면 extractEntries 의 junk 스킵을 되돌려도 회귀가 잡히지 않는다.
        $this->assertFileDoesNotExist($this->pluginPath . '/Banner/.DS_Store');
    }

    // =========================================================================
    // 경로 안전성 (zip slip)
    // =========================================================================

    public function testRejectsPathTraversalEntries(): void
    {
        $zip = $this->makeZip([
            'Evil/manifest.json' => json_encode(['type' => 'plugin']),
            'Evil/../../outside.php' => '<?php // escape',
        ]);

        $result = $this->installer()->installFromZip($zip, 'plugin');

        $this->assertFalse($result->isSuccess());
        $this->assertFileDoesNotExist($this->basePath . '/outside.php');
        $this->assertDirectoryDoesNotExist($this->pluginPath . '/Evil');
    }

    public function testRejectsAbsolutePathEntries(): void
    {
        $zip = $this->makeZip([
            '/etc/evil.php' => '<?php',
            'Evil/manifest.json' => json_encode(['type' => 'plugin']),
        ]);

        $result = $this->installer()->installFromZip($zip, 'plugin');

        $this->assertFalse($result->isSuccess());
    }

    public function testRejectsBackslashEntries(): void
    {
        $zip = $this->makeZip([
            'Evil\\manifest.json' => json_encode(['type' => 'plugin']),
        ]);

        $result = $this->installer()->installFromZip($zip, 'plugin');

        $this->assertFalse($result->isSuccess());
    }

    // =========================================================================
    // 구조 검증
    // =========================================================================

    public function testRejectsZipWithoutRootDirectory(): void
    {
        // 디렉토리로 감싸지 않고 파일을 바로 압축한 흔한 실수
        $zip = $this->makeZip([
            'manifest.json' => json_encode(['type' => 'plugin']),
            'Provider.php' => '<?php',
        ]);

        $result = $this->installer()->installFromZip($zip, 'plugin');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('확장 디렉토리 하나만', $result->getMessage());
    }

    public function testRejectsMultipleRootDirectories(): void
    {
        $zip = $this->makeZip([
            'One/manifest.json' => json_encode(['type' => 'plugin']),
            'Two/manifest.json' => json_encode(['type' => 'plugin']),
        ]);

        $result = $this->installer()->installFromZip($zip, 'plugin');

        $this->assertFalse($result->isSuccess());
    }

    public function testRejectsInvalidDirectoryName(): void
    {
        // 디렉토리명이 Provider 네임스페이스(Mublo\Plugin\{Name})로 조립되므로
        // PHP 식별자 규칙을 어기면 로드가 불가능하다
        $zip = $this->makeZip([
            'bad name/manifest.json' => json_encode(['type' => 'plugin']),
        ]);

        $result = $this->installer()->installFromZip($zip, 'plugin');

        $this->assertFalse($result->isSuccess());
    }

    // =========================================================================
    // manifest 검증
    // =========================================================================

    public function testRejectsMissingManifest(): void
    {
        $zip = $this->makeZip([
            'NoManifest/Provider.php' => '<?php',
        ]);

        $result = $this->installer()->installFromZip($zip, 'plugin');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('manifest.json', $result->getMessage());
        $this->assertDirectoryDoesNotExist($this->pluginPath . '/NoManifest');
    }

    public function testRejectsBrokenManifest(): void
    {
        $zip = $this->makeZip([
            'Broken/manifest.json' => '{invalid json',
        ]);

        $result = $this->installer()->installFromZip($zip, 'plugin');

        $this->assertFalse($result->isSuccess());
    }

    public function testRejectsManifestWithoutType(): void
    {
        // type 이 없으면 관리자 선택값을 믿는 수밖에 없어 종류 검사가 무력화된다 — 명시 강제
        $zip = $this->makeZip([
            'NoType/manifest.json' => json_encode(['label' => '타입 없음']),
        ]);

        $result = $this->installer()->installFromZip($zip, 'plugin');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('type', $result->getMessage());
        $this->assertDirectoryDoesNotExist($this->pluginPath . '/NoType');
    }

    public function testRejectsTypeMismatch(): void
    {
        // 패키지 zip 을 플러그인으로 설치하면 Provider 조립이 어긋나 조용히 깨진다
        $zip = $this->makeZip([
            'Shop2/manifest.json' => json_encode(['type' => 'package']),
        ]);

        $result = $this->installer()->installFromZip($zip, 'plugin');

        $this->assertFalse($result->isSuccess());
        $this->assertDirectoryDoesNotExist($this->pluginPath . '/Shop2');
    }

    public function testRejectsIncompatibleCoreVersion(): void
    {
        $zip = $this->makeZip([
            'Future/manifest.json' => json_encode(['type' => 'plugin', 'requires' => ['core' => '>=99.0.0']]),
        ]);

        $result = $this->installer()->installFromZip($zip, 'plugin');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('호환', $result->getMessage());
    }

    public function testChecksRequiredPackageAgainstInstalledOnes(): void
    {
        // 부모 패키지가 디스크에 있으면 requires["package:X"] 를 통과해야 한다
        mkdir($this->packagePath . '/Board', 0777, true);
        file_put_contents(
            $this->packagePath . '/Board/manifest.json',
            json_encode(['type' => 'package', 'version' => '1.2.0'])
        );

        $zip = $this->makeZip([
            'BoardAddon/manifest.json' => json_encode([
                'type' => 'plugin',
                'requires' => ['package:Board' => '^1.0'],
            ]),
        ]);

        $result = $this->installer()->installFromZip($zip, 'plugin');

        $this->assertTrue($result->isSuccess(), $result->getMessage());
    }

    // =========================================================================
    // 중복/입력 검증
    // =========================================================================

    public function testRejectsAlreadyInstalledExtension(): void
    {
        mkdir($this->pluginPath . '/Banner', 0777, true);
        file_put_contents($this->pluginPath . '/Banner/manifest.json', '{}');

        $zip = $this->makeZip([
            'Banner/manifest.json' => json_encode(['type' => 'plugin']),
        ]);

        $result = $this->installer()->installFromZip($zip, 'plugin');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('이미 설치', $result->getMessage());
    }

    public function testRejectsInvalidType(): void
    {
        $zip = $this->makeZip(['X/manifest.json' => '{}']);

        $result = $this->installer()->installFromZip($zip, 'theme');

        $this->assertFalse($result->isSuccess());
    }

    public function testRejectsNonZipFile(): void
    {
        $notZip = $this->zipDir . '/fake.zip';
        file_put_contents($notZip, 'this is not a zip');

        $result = $this->installer()->installFromZip($notZip, 'plugin');

        $this->assertFalse($result->isSuccess());
    }

    // =========================================================================
    // 헬퍼
    // =========================================================================

    private function installer(?ExtensionPackageVerifier $verifier = null): ExtensionInstaller
    {
        return new ExtensionInstaller(
            new ExtensionCompatibility(),
            $verifier ?? new ExtensionPackageVerifier([]),
            $this->pluginPath,
            $this->packagePath
        );
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} */
    private function makeSigningKeyPair(): array
    {
        $options = [
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ];
        $configPath = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (is_file($configPath)) {
            $options['config'] = $configPath;
        }

        $privateKey = openssl_pkey_new($options);
        $this->assertInstanceOf(\OpenSSLAsymmetricKey::class, $privateKey);
        $details = openssl_pkey_get_details($privateKey);
        $this->assertIsArray($details);

        return [$privateKey, $details['key']];
    }

    private function trustedVerifier(string $publicKey): ExtensionPackageVerifier
    {
        return new ExtensionPackageVerifier([
            'require_signature' => true,
            'publishers' => [
                'test:key-1' => [
                    'name' => 'Test Publisher',
                    'public_key' => $publicKey,
                    'sources' => ['official-marketplace'],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, string> $files
     */
    private function makeSignedZip(
        array $files,
        \OpenSSLAsymmetricKey $privateKey,
        ExtensionPackageVerifier $verifier,
        string $rootDir
    ): string {
        $path = $this->makeZip($files);
        $zip = new \ZipArchive();
        $zip->open($path);
        $digest = $verifier->calculatePayloadDigest($zip, $rootDir);
        $this->assertNotNull($digest);
        $this->assertTrue(openssl_sign($digest, $signature, $privateKey, OPENSSL_ALGO_SHA256));
        $zip->addFromString(
            $rootDir . '/' . ExtensionPackageVerifier::SIGNATURE_FILE,
            json_encode([
                'schema' => 1,
                'algorithm' => 'rsa-sha256',
                'key_id' => 'test:key-1',
                'payload_sha256' => $digest,
                'signature' => base64_encode($signature),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        $zip->close();

        return $path;
    }

    /**
     * @param array<string, string> $files entry => content
     */
    private function makeZip(array $files): string
    {
        $path = $this->zipDir . '/' . bin2hex(random_bytes(6)) . '.zip';

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();

        return $path;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
