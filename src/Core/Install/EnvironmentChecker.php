<?php

namespace Mublo\Core\Install;

use Closure;

/**
 * EnvironmentChecker
 *
 * 설치 환경 체크 (PHP 버전, 확장 모듈, 디렉토리 권한)
 */
class EnvironmentChecker
{
    /**
     * 필수 PHP 버전
     */
    private const REQUIRED_PHP_VERSION = '8.2.0';

    /**
     * 필수 PHP 확장 모듈
     */
    private const REQUIRED_EXTENSIONS = [
        'pdo',
        'pdo_mysql',
        'mysqli',
        'mbstring',
        'openssl',
        'json',
        'curl',
        'fileinfo',
        // gd: 코어 ImageProcessor 가 GD 함수(imagecreatefrom*, imagecopyresampled 등)를 직접 사용 →
        // 이미지 업로드/썸네일이 코어 기능이므로 필수. 없으면 설치는 되고 런타임에서 실패.
        'gd',
    ];

    /**
     * 권장 PHP 확장 모듈
     *
     * zip/xml: phpspreadsheet(엑셀)·dompdf(PDF) 리포트에 필요 — 해당 기능 미사용 시 없어도 됨.
     * intl: 국제화 유틸.
     */
    private const RECOMMENDED_EXTENSIONS = [
        'zip',
        'xml',
        'intl',
    ];

    /**
     * 쓰기 권한이 필요한 디렉토리
     */
    private array $writableDirectories = [];

    /** @var Closure(string): bool|null 테스트에서 실제 파일 probe를 대체할 때 사용 */
    private ?Closure $writeProbe;

    /**
     * @param array<string, string|array{path: string, purpose?: string}>|null $writableDirectories
     */
    public function __construct(?array $writableDirectories = null, ?callable $writeProbe = null)
    {
        $this->writeProbe = $writeProbe !== null ? Closure::fromCallable($writeProbe) : null;

        if ($writableDirectories === null) {
            // 웹 루트 하위 storage 라벨을 실제 디렉토리명으로 표시
            $publicDirName = basename(MUBLO_PUBLIC_PATH);
            $writableDirectories = [
                'config' => ['path' => MUBLO_CONFIG_PATH, 'purpose' => 'install'],
                'storage' => ['path' => MUBLO_STORAGE_PATH, 'purpose' => 'runtime'],
                $publicDirName . '/storage' => ['path' => MUBLO_PUBLIC_STORAGE_PATH, 'purpose' => 'runtime'],
            ];
        }

        foreach ($writableDirectories as $label => $directory) {
            $this->writableDirectories[$label] = is_array($directory)
                ? [
                    'path' => $directory['path'],
                    'purpose' => $directory['purpose'] ?? 'runtime',
                ]
                : ['path' => $directory, 'purpose' => 'runtime'];
        }
    }

    /**
     * 전체 환경 체크
     */
    public function checkAll(): array
    {
        $checks = [
            'php_version' => $this->checkPhpVersion(),
            'extensions'  => $this->checkExtensions(),
            'permissions' => $this->checkPermissions(),
        ];

        // ❗ 여기서만 종합 판단
        $checks['overall_status'] = $this->getOverallStatus($checks);

        return $checks;
    }

    /**
     * PHP 버전 체크
     */
    private function checkPhpVersion(): array
    {
        $currentVersion = PHP_VERSION;
        $isValid = version_compare($currentVersion, self::REQUIRED_PHP_VERSION, '>=');

        return [
            'required' => self::REQUIRED_PHP_VERSION,
            'current'  => $currentVersion,
            'status'   => $isValid ? 'OK' : 'FAIL',
            'message'  => $isValid
                ? "PHP {$currentVersion} (요구사항 충족)"
                : "PHP {$currentVersion} (최소 " . self::REQUIRED_PHP_VERSION . ' 필요)',
        ];
    }

    /**
     * PHP 확장 모듈 체크
     */
    private function checkExtensions(): array
    {
        $result = [
            'required'     => [],
            'recommended'  => [],
        ];

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $result['required'][$ext] = [
                'loaded'  => $loaded,
                'status'  => $loaded ? 'OK' : 'FAIL',
                'message' => $loaded ? '설치됨' : '미설치 (필수)',
            ];
        }

        foreach (self::RECOMMENDED_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $result['recommended'][$ext] = [
                'loaded'  => $loaded,
                'status'  => $loaded ? 'OK' : 'WARNING',
                'message' => $loaded ? '설치됨' : '미설치 (권장)',
            ];
        }

        // GD 능력 검사 — WebP 는 별도 확장이 아니라 GD 의 컴파일 타임 옵션이라
        // extension_loaded('gd') 로는 보이지 않는다. 포맷 정책(#39): WebP 는
        // 선택(썸네일 용량 최적화) — 없으면 PNG 폴백으로 동작하므로 설치를 막지
        // 않고, 결과가 어떻게 달라지는지만 알려준다.
        $webp = function_exists('imagewebp');
        $result['recommended']['gd (WebP)'] = [
            'loaded'  => $webp,
            'status'  => $webp ? 'OK' : 'WARNING',
            'message' => $webp ? '지원됨' : 'GD 에 WebP 지원 없음 — 썸네일이 PNG 로 저장됩니다',
        ];

        return $result;
    }

    /**
     * 디렉토리 권한 체크
     */
    private function checkPermissions(): array
    {
        $result = [];

        foreach ($this->writableDirectories as $label => $directory) {
            $path = $directory['path'];
            $purpose = $directory['purpose'];
            $exists   = file_exists($path);
            $isDirectory = $exists && is_dir($path);
            $readable = $isDirectory && is_readable($path);
            $reportedWritable = $isDirectory && is_writable($path);
            // ACL, PHP-FPM 실행 계정, open_basedir 등의 영향을 모두 반영하기 위해
            // 실제로 작은 파일을 만들고 지울 수 있는지를 최종 기준으로 삼는다.
            $writable = $readable && $this->probeWriteAccess($path);
            $identity = $this->inspectIdentity($path);

            if (!$exists) {
                $status  = 'FAIL';
                $message = '디렉토리가 존재하지 않음';
            } elseif (!$isDirectory) {
                $status  = 'FAIL';
                $message = '디렉토리가 아닌 경로';
            } elseif (!$readable) {
                $status  = 'FAIL';
                $message = '읽기 권한 없음';
            } elseif (!$writable) {
                $status  = 'FAIL';
                $message = '실제 파일 생성/삭제 불가';
            } else {
                $status  = 'OK';
                $message = '실제 파일 생성/삭제 가능';
            }

            $result[$label] = [
                'path'     => $path,
                'purpose'  => $purpose,
                'exists'   => $exists,
                'is_directory' => $isDirectory,
                'readable' => $readable,
                'writable' => $writable,
                'reported_writable' => $reportedWritable,
                'mode' => $identity['mode'],
                'owner' => $identity['owner'],
                'group' => $identity['group'],
                'php_user' => $identity['php_user'],
                'php_group' => $identity['php_group'],
                'access_class' => $identity['access_class'],
                'status'   => $status,
                'message'  => $message,
                'guidance' => $this->permissionGuidance(
                    $purpose,
                    $status,
                    $identity['access_class']
                ),
            ];
        }

        return $result;
    }

    /** 실제 파일 생성·쓰기·삭제가 모두 가능한지 확인한다. */
    private function probeWriteAccess(string $path): bool
    {
        if ($this->writeProbe !== null) {
            return (bool) ($this->writeProbe)($path);
        }

        try {
            $probePath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR
                . '.mublo-write-test-' . bin2hex(random_bytes(6));
        } catch (\Throwable) {
            return false;
        }

        $handle = @fopen($probePath, 'x+b');
        if ($handle === false) {
            return false;
        }

        $written = @fwrite($handle, 'mublo-permission-check') !== false;
        $closed = @fclose($handle);
        $deleted = @unlink($probePath);

        return $written && $closed && $deleted;
    }

    /**
     * Unix에서는 퍼미션이 적용되는 주체(owner/group/other)를 함께 보여준다.
     * POSIX 함수가 없는 Windows·일부 공유호스팅에서는 확인 가능한 값만 반환한다.
     *
     * @return array{mode: ?string, owner: ?string, group: ?string, php_user: ?string, php_group: ?string, access_class: string}
     */
    private function inspectIdentity(string $path): array
    {
        $mode = null;
        $owner = null;
        $group = null;
        $phpUser = null;
        $phpGroup = null;
        $accessClass = 'unknown';

        if (!file_exists($path)) {
            return [
                'mode' => $mode,
                'owner' => $owner,
                'group' => $group,
                'php_user' => $phpUser,
                'php_group' => $phpGroup,
                'access_class' => $accessClass,
            ];
        }

        $permissions = @fileperms($path);
        if ($permissions !== false) {
            $mode = substr(sprintf('%04o', $permissions & 07777), -4);
        }

        $ownerId = @fileowner($path);
        $groupId = @filegroup($path);
        if ($ownerId !== false) {
            $owner = $this->userLabel((int) $ownerId);
        }
        if ($groupId !== false) {
            $group = $this->groupLabel((int) $groupId);
        }

        if (function_exists('posix_geteuid')) {
            $phpUid = posix_geteuid();
            $phpUser = $this->userLabel($phpUid);
            if ($ownerId !== false && $phpUid === (int) $ownerId) {
                $accessClass = 'owner';
            }
        }

        if (function_exists('posix_getegid')) {
            $phpGid = posix_getegid();
            $phpGroup = $this->groupLabel($phpGid);
            $phpGroups = function_exists('posix_getgroups') ? posix_getgroups() : [$phpGid];
            if (!is_array($phpGroups)) {
                $phpGroups = [$phpGid];
            }
            if ($accessClass !== 'owner' && $groupId !== false && in_array((int) $groupId, $phpGroups, true)) {
                $accessClass = 'group';
            } elseif ($accessClass === 'unknown' && $ownerId !== false && $groupId !== false) {
                $accessClass = 'other';
            }
        }

        return [
            'mode' => $mode,
            'owner' => $owner,
            'group' => $group,
            'php_user' => $phpUser,
            'php_group' => $phpGroup,
            'access_class' => $accessClass,
        ];
    }

    private function userLabel(int $uid): string
    {
        if (function_exists('posix_getpwuid')) {
            $info = posix_getpwuid($uid);
            if (is_array($info) && is_string($info['name'] ?? null)) {
                return $info['name'] . ' (UID ' . $uid . ')';
            }
        }
        return 'UID ' . $uid;
    }

    private function groupLabel(int $gid): string
    {
        if (function_exists('posix_getgrgid')) {
            $info = posix_getgrgid($gid);
            if (is_array($info) && is_string($info['name'] ?? null)) {
                return $info['name'] . ' (GID ' . $gid . ')';
            }
        }
        return 'GID ' . $gid;
    }

    private function permissionGuidance(string $purpose, string $status, string $accessClass): string
    {
        if ($status === 'OK') {
            return $purpose === 'install'
                ? '설치 중에만 쓰기가 필요합니다. 설치 완료 후 config 디렉토리는 755로 되돌리세요.'
                : '운영 중 캐시·세션·업로드를 위해 계속 쓰기 가능해야 합니다.';
        }

        return match ($accessClass) {
            'owner' => 'PHP가 소유자로 보이지만 쓰지 못합니다. 호스팅 용량·ACL·open_basedir 또는 파일 잠금을 확인하세요.',
            'group' => 'PHP가 디렉토리 그룹으로 실행됩니다. 호스팅이 허용하면 디렉토리에 775를 적용하세요.',
            'other' => 'PHP가 소유자와 그룹에 속하지 않습니다. 이 공유호스팅 방식에서는 디렉토리를 707로 설정하세요. 설치 후 config는 755로 되돌려야 합니다.',
            default => '755와 775가 모두 실패하는 공유호스팅에서는 디렉토리를 707로 설정한 뒤 다시 확인하세요.',
        };
    }

    /**
     * 전체 상태 판단 (재귀 ❌, 분석 전용)
     */
    private function getOverallStatus(array $checks): string
    {
        if ($checks['php_version']['status'] === 'FAIL') {
            return 'FAIL';
        }

        foreach ($checks['extensions']['required'] as $info) {
            if ($info['status'] === 'FAIL') {
                return 'FAIL';
            }
        }

        foreach ($checks['permissions'] as $info) {
            if ($info['status'] === 'FAIL') {
                return 'FAIL';
            }
        }

        foreach ($checks['extensions']['recommended'] as $info) {
            if ($info['status'] === 'WARNING') {
                return 'WARNING';
            }
        }

        return 'OK';
    }

    /**
     * 설치 가능 여부 판단
     */
    public function canInstall(): bool
    {
        $checks = $this->checkAll();
        return in_array($checks['overall_status'], ['OK', 'WARNING'], true);
    }
}
