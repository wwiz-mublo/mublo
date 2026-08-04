<?php

namespace Tests\Unit\Core\Install;

use PHPUnit\Framework\TestCase;
use Mublo\Core\Install\EnvironmentChecker;

/**
 * EnvironmentCheckerTest
 *
 * EnvironmentChecker 단위 테스트
 * - checkAll() 구조 검증
 * - PHP 버전 체크 결과 구조
 * - 확장 모듈 체크 결과 구조
 * - 디렉토리 권한 체크 구조
 * - canInstall() 동작
 * - overall_status 판단 로직
 */
class EnvironmentCheckerTest extends TestCase
{
    private EnvironmentChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new EnvironmentChecker();
    }

    // =========================================================================
    // checkAll() 구조
    // =========================================================================

    public function testCheckAllReturnsExpectedStructure(): void
    {
        $result = $this->checker->checkAll();

        $this->assertArrayHasKey('php_version', $result);
        $this->assertArrayHasKey('extensions', $result);
        $this->assertArrayHasKey('permissions', $result);
        $this->assertArrayHasKey('overall_status', $result);
    }

    public function testPhpVersionCheckStructure(): void
    {
        $result = $this->checker->checkAll();
        $phpVersion = $result['php_version'];

        $this->assertArrayHasKey('required', $phpVersion);
        $this->assertArrayHasKey('current', $phpVersion);
        $this->assertArrayHasKey('status', $phpVersion);
        $this->assertArrayHasKey('message', $phpVersion);

        $this->assertEquals('8.2.0', $phpVersion['required']);
        $this->assertEquals(PHP_VERSION, $phpVersion['current']);
    }

    public function testExtensionsCheckStructure(): void
    {
        $result = $this->checker->checkAll();
        $extensions = $result['extensions'];

        $this->assertArrayHasKey('required', $extensions);
        $this->assertArrayHasKey('recommended', $extensions);

        // 필수 모듈 확인 (gd: 코어 ImageProcessor 가 GD 함수를 직접 사용 → 필수)
        $requiredExtensions = ['pdo', 'pdo_mysql', 'mysqli', 'mbstring', 'openssl', 'json', 'curl', 'fileinfo', 'gd'];
        foreach ($requiredExtensions as $ext) {
            $this->assertArrayHasKey($ext, $extensions['required'],
                "필수 확장 '{$ext}'이 결과에 포함되어야 합니다");

            $extResult = $extensions['required'][$ext];
            $this->assertArrayHasKey('loaded', $extResult);
            $this->assertArrayHasKey('status', $extResult);
            $this->assertArrayHasKey('message', $extResult);
        }

        // 권장 모듈 확인
        $recommendedExtensions = ['zip', 'xml', 'intl'];
        foreach ($recommendedExtensions as $ext) {
            $this->assertArrayHasKey($ext, $extensions['recommended'],
                "권장 확장 '{$ext}'이 결과에 포함되어야 합니다");

            $extResult = $extensions['recommended'][$ext];
            $this->assertArrayHasKey('loaded', $extResult);
            $this->assertArrayHasKey('status', $extResult);
        }
    }

    public function testPermissionsCheckStructure(): void
    {
        $result = $this->checker->checkAll();
        $permissions = $result['permissions'];

        $this->assertIsArray($permissions);

        // 각 항목의 구조 확인
        foreach ($permissions as $label => $info) {
            $this->assertArrayHasKey('path', $info, "권한 항목 '{$label}'에 path가 있어야 합니다");
            $this->assertArrayHasKey('purpose', $info);
            $this->assertArrayHasKey('exists', $info);
            $this->assertArrayHasKey('is_directory', $info);
            $this->assertArrayHasKey('readable', $info);
            $this->assertArrayHasKey('writable', $info);
            $this->assertArrayHasKey('reported_writable', $info);
            $this->assertArrayHasKey('mode', $info);
            $this->assertArrayHasKey('owner', $info);
            $this->assertArrayHasKey('group', $info);
            $this->assertArrayHasKey('php_user', $info);
            $this->assertArrayHasKey('php_group', $info);
            $this->assertArrayHasKey('access_class', $info);
            $this->assertArrayHasKey('status', $info);
            $this->assertArrayHasKey('message', $info);
            $this->assertArrayHasKey('guidance', $info);

            $this->assertContains($info['status'], ['OK', 'FAIL'],
                "상태는 OK 또는 FAIL이어야 합니다");
        }
    }

    public function testOverallStatusIsValidValue(): void
    {
        $result = $this->checker->checkAll();
        $this->assertContains($result['overall_status'], ['OK', 'WARNING', 'FAIL']);
    }

    public function testPermissionProbeIsTheFinalWritableCriterion(): void
    {
        $directory = sys_get_temp_dir();
        $checker = new EnvironmentChecker(
            ['storage' => ['path' => $directory, 'purpose' => 'runtime']],
            static fn (string $path): bool => false
        );

        $permission = $checker->checkAll()['permissions']['storage'];

        $this->assertFalse($permission['writable']);
        $this->assertSame('FAIL', $permission['status']);
        $this->assertSame('실제 파일 생성/삭제 불가', $permission['message']);
        $this->assertNotSame('', $permission['guidance']);
    }

    public function testPermissionPurposeAndSuccessfulProbeAreReported(): void
    {
        $checker = new EnvironmentChecker(
            ['config' => ['path' => sys_get_temp_dir(), 'purpose' => 'install']],
            static fn (string $path): bool => is_dir($path)
        );

        $permission = $checker->checkAll()['permissions']['config'];

        $this->assertSame('install', $permission['purpose']);
        $this->assertTrue($permission['writable']);
        $this->assertSame('OK', $permission['status']);
        $this->assertStringContainsString('설치 완료 후', $permission['guidance']);
    }

    public function testRealPermissionProbeLeavesNoDiagnosticFile(): void
    {
        $directory = sys_get_temp_dir() . '/mublo-env-check-' . bin2hex(random_bytes(5));
        mkdir($directory, 0700);

        try {
            $checker = new EnvironmentChecker([
                'storage' => ['path' => $directory, 'purpose' => 'runtime'],
            ]);
            $permission = $checker->checkAll()['permissions']['storage'];

            $this->assertTrue($permission['writable']);
            $this->assertSame([], glob($directory . '/.mublo-write-test-*') ?: []);
        } finally {
            @rmdir($directory);
        }
    }

    // =========================================================================
    // PHP 버전 체크
    // =========================================================================

    public function testPhpVersionIsAtLeastRequired(): void
    {
        // 테스트 환경은 PHP 8.2+ 이므로 OK여야 함
        $result = $this->checker->checkAll();
        $phpVersion = $result['php_version'];

        $this->assertEquals('OK', $phpVersion['status'],
            "테스트 환경의 PHP {$phpVersion['current']}는 8.2.0 이상이어야 합니다");
    }

    // =========================================================================
    // 확장 모듈 상태
    // =========================================================================

    public function testLoadedExtensionsHaveOkStatus(): void
    {
        $result = $this->checker->checkAll();
        $requiredExts = $result['extensions']['required'];

        foreach ($requiredExts as $extName => $info) {
            if ($info['loaded']) {
                $this->assertEquals('OK', $info['status'],
                    "설치된 확장 '{$extName}'의 상태는 OK여야 합니다");
            } else {
                $this->assertEquals('FAIL', $info['status'],
                    "미설치 필수 확장 '{$extName}'의 상태는 FAIL이어야 합니다");
            }
        }
    }

    public function testRecommendedExtensionHasWarningWhenNotLoaded(): void
    {
        $result = $this->checker->checkAll();
        $recommendedExts = $result['extensions']['recommended'];

        foreach ($recommendedExts as $extName => $info) {
            // 상태는 OK 또는 WARNING이어야 함 (권장이므로 FAIL 없음)
            $this->assertContains($info['status'], ['OK', 'WARNING'],
                "권장 확장 '{$extName}'의 상태는 OK 또는 WARNING이어야 합니다");
            if (!$info['loaded']) {
                $this->assertEquals('WARNING', $info['status'],
                    "미설치 권장 확장 '{$extName}'의 상태는 WARNING이어야 합니다");
            }
        }
    }

    // =========================================================================
    // canInstall()
    // =========================================================================

    public function testCanInstallReturnsBool(): void
    {
        $result = $this->checker->canInstall();
        $this->assertIsBool($result);
    }

    public function testCanInstallIsTrueWhenStatusIsOk(): void
    {
        $result = $this->checker->checkAll();

        if ($result['overall_status'] === 'OK') {
            $this->assertTrue($this->checker->canInstall());
        } elseif ($result['overall_status'] === 'WARNING') {
            $this->assertTrue($this->checker->canInstall(),
                'WARNING 상태에서도 설치 가능해야 합니다');
        } else {
            $this->assertFalse($this->checker->canInstall(),
                'FAIL 상태에서는 설치 불가능해야 합니다');
        }
    }

    public function testCanInstallReturnsConsistentWithOverallStatus(): void
    {
        $checks = $this->checker->checkAll();
        $canInstall = $this->checker->canInstall();

        if ($checks['overall_status'] === 'FAIL') {
            $this->assertFalse($canInstall, 'overall_status가 FAIL이면 canInstall은 false');
        } else {
            $this->assertTrue($canInstall, 'overall_status가 OK/WARNING이면 canInstall은 true');
        }
    }

    // =========================================================================
    // JSON 직렬화 가능성 (API 응답용)
    // =========================================================================

    public function testCheckAllResultIsJsonSerializable(): void
    {
        $result = $this->checker->checkAll();
        $json = json_encode($result);

        $this->assertNotFalse($json);
        $this->assertJson($json);
    }
}
