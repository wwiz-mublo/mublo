<?php
declare(strict_types=1);
namespace Mublo\Service\Extension;

use Mublo\Core\App\Application;
use Mublo\Core\Extension\NestedPlugin;
use Mublo\Core\Result\Result;

/**
 * ExtensionInstaller
 *
 * 관리자가 업로드한 zip 을 검증하고 plugins/·packages/ 에 설치한다.
 *
 * 확장은 composer 가 아니라 커뮤니티 zip 으로 배포된다(ExtensionCompatibility 참조).
 * FTP 업로드 중심의 설치 흐름을 관리자 화면 안으로 가져오되,
 * 압축 해제 전에 구조·manifest·호환성을 모두 검사해 잘못된 zip 이 코드 트리를
 * 오염시키지 못하게 한다.
 *
 * 책임:
 * - zip 구조 검증 (경로 탈출 차단, 단일 루트 디렉토리 강제, 압축 폭탄 가드)
 * - canonical payload checksum 및 publisher 서명·설치 출처 검증
 * - manifest.json 사전 검증 (파싱, 종류 일치, requires 호환성)
 * - 임시 디렉토리 해제 후 최종 위치로 원자적 이동
 *
 * 하지 않는 것:
 * - 활성화. 설치 후 목록 노출은 파일시스템 스캔(ExtensionService)이,
 *   활성화는 기존 saveExtensionConfig 흐름이 담당한다.
 * - 덮어쓰기. 같은 이름의 확장이 이미 있으면 실패한다 (업데이트는 별도 절차).
 */
class ExtensionInstaller
{
    use ArchiveJunkPolicy;

    /** zip 항목 수 상한 (압축 폭탄·비정상 zip 가드) */
    private const MAX_ENTRIES = 20000;

    /** 압축 해제 후 총 크기 상한 (bytes) */
    private const MAX_UNCOMPRESSED_SIZE = 200 * 1024 * 1024;

    private ExtensionCompatibility $compatibility;
    private string $pluginPath;
    private string $packagePath;
    private ExtensionPackageVerifier $packageVerifier;

    public function __construct(
        ExtensionCompatibility $compatibility,
        ExtensionPackageVerifier $packageVerifier,
        ?string $pluginPath = null,
        ?string $packagePath = null
    ) {
        $this->compatibility = $compatibility;
        $this->pluginPath = $pluginPath ?? MUBLO_PLUGIN_PATH;
        $this->packagePath = $packagePath ?? MUBLO_PACKAGE_PATH;
        $this->packageVerifier = $packageVerifier;
    }

    /**
     * zip 파일을 검증하고 설치한다.
     *
     * @param string $zipPath 업로드된 zip 의 로컬 경로 (업로드 자체 검증은 호출자 책임)
     * @param string $type 'plugin' 또는 'package'
     */
    public function installFromZip(
        string $zipPath,
        string $type,
        string $source = 'manual-upload'
    ): Result
    {
        if (!in_array($type, ['plugin', 'package'], true)) {
            return Result::failure('확장 종류는 plugin 또는 package 여야 합니다.');
        }

        if (!class_exists('ZipArchive')) {
            return Result::failure('서버에 zip 확장이 설치되어 있지 않습니다.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return Result::failure('zip 파일을 열 수 없습니다. 올바른 zip 인지 확인하세요.');
        }

        try {
            $structure = $this->validateStructure($zip);
            if (!$structure->isSuccess()) {
                return $structure;
            }
            $rootDir = (string) $structure->get('rootDir');

            $verificationResult = $this->packageVerifier->verify($zip, $rootDir, $source);
            if (!$verificationResult->isSuccess()) {
                return $verificationResult;
            }
            $verification = $verificationResult->get('verification');

            $manifestResult = $this->validateManifest($zip, $rootDir, $type);
            if (!$manifestResult->isSuccess()) {
                return $manifestResult;
            }
            $manifest = $manifestResult->get('manifest');

            $basePath = $type === 'plugin' ? $this->pluginPath : $this->packagePath;
            $targetDir = $basePath . '/' . $rootDir;

            if (is_dir($targetDir)) {
                return Result::failure(
                    "'{$rootDir}' 확장이 이미 설치되어 있습니다. 업데이트하려면 기존 디렉토리를 백업 후 제거하고 다시 업로드하세요."
                );
            }

            if (!is_dir($basePath) || !is_writable($basePath)) {
                $dirLabel = $type === 'plugin' ? 'plugins/' : 'packages/';
                return Result::failure("{$dirLabel} 디렉토리에 쓰기 권한이 없습니다. 서버 퍼미션을 확인하세요.");
            }

            return $this->extract(
                $zip,
                $rootDir,
                $basePath,
                $targetDir,
                $manifest,
                $type,
                $verification
            );
        } finally {
            $zip->close();
        }
    }

    /**
     * zip 항목 전체를 훑어 경로 안전성과 "단일 루트 디렉토리" 구조를 검증한다.
     *
     * 성공 시 data: ['rootDir' => 확장 디렉토리명]
     */
    private function validateStructure(\ZipArchive $zip): Result
    {
        if ($zip->numFiles < 1) {
            return Result::failure('zip 이 비어 있습니다.');
        }
        if ($zip->numFiles > self::MAX_ENTRIES) {
            return Result::failure('zip 항목 수가 허용 범위를 초과했습니다.');
        }

        $rootDir = null;
        $totalSize = 0;
        $seenEntries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                return Result::failure('zip 항목을 읽을 수 없습니다.');
            }

            $entry = (string) $stat['name'];

            if (isset($seenEntries[$entry])) {
                return Result::failure("zip 에 중복된 경로가 있습니다: {$entry}");
            }
            $seenEntries[$entry] = true;

            // 경로 탈출(zip slip)·절대경로·드라이브·역슬래시 차단.
            // 여기서 걸러지므로 이후 압축 해제는 저장된 경로를 그대로 믿어도 된다.
            if (
                str_contains($entry, '\\')
                || str_starts_with($entry, '/')
                || preg_match('#^[A-Za-z]:#', $entry)
                || preg_match('#(^|/)\.\.(/|$)#', $entry)
            ) {
                return Result::failure("zip 에 허용되지 않는 경로가 있습니다: {$entry}");
            }

            // macOS Finder 압축이 끼워 넣는 메타데이터는 구조 판정에서 제외한다
            // (해제 단계에서도 rootDir 밖 항목은 복사하지 않는다)
            if ($this->isJunkEntry($entry)) {
                continue;
            }

            $totalSize += (int) ($stat['size'] ?? 0);
            if ($totalSize > self::MAX_UNCOMPRESSED_SIZE) {
                return Result::failure('압축 해제 후 크기가 허용 범위를 초과했습니다.');
            }

            $top = explode('/', $entry, 2)[0];
            if ($top === '') {
                return Result::failure("zip 에 허용되지 않는 경로가 있습니다: {$entry}");
            }

            if ($rootDir === null) {
                $rootDir = $top;
            } elseif ($rootDir !== $top) {
                return Result::failure(
                    'zip 루트에는 확장 디렉토리 하나만 있어야 합니다. (예: Banner/manifest.json)'
                );
            }

            // 루트에 파일이 바로 놓인 경우 (디렉토리로 감싸지지 않은 zip)
            if (!str_contains($entry, '/')) {
                return Result::failure(
                    'zip 루트에는 확장 디렉토리 하나만 있어야 합니다. (예: Banner/manifest.json)'
                );
            }
        }

        if ($rootDir === null) {
            return Result::failure('zip 루트에는 확장 디렉토리 하나만 있어야 합니다. (예: Banner/manifest.json)');
        }

        // 디렉토리명이 곧 확장 이름이고 Provider 네임스페이스로 조립된다.
        // 규칙은 스캐너(ExtensionService)와 공유하는 단일 진실을 쓴다.
        if (!NestedPlugin::isValidName((string) $rootDir)) {
            return Result::failure("확장 디렉토리명 '{$rootDir}' 이(가) 올바르지 않습니다. 영문으로 시작하는 영문/숫자 이름이어야 합니다.");
        }

        return Result::success('', ['rootDir' => $rootDir]);
    }

    /**
     * 압축 해제 전에 zip 안의 manifest.json 을 읽어 사전 검증한다.
     *
     * 성공 시 data: ['manifest' => array]
     */
    private function validateManifest(\ZipArchive $zip, string $rootDir, string $type): Result
    {
        $raw = $zip->getFromName($rootDir . '/manifest.json');
        if ($raw === false) {
            return Result::failure("zip 안에 {$rootDir}/manifest.json 이 없습니다. 확장 zip 인지 확인하세요.");
        }

        $manifest = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($manifest)) {
            return Result::failure('manifest.json 을 해석할 수 없습니다: ' . json_last_error_msg());
        }

        // 종류가 다른 곳에 설치되면 Provider 조립이 어긋나 조용히 깨진다.
        // type 이 없으면 관리자 선택값을 믿는 수밖에 없어 검사가 무력화되므로 명시를 강제한다.
        $manifestType = $manifest['type'] ?? null;
        if (!in_array($manifestType, ['plugin', 'package'], true)) {
            return Result::failure("manifest.json 에 type('plugin' 또는 'package')이 명시되어 있어야 합니다.");
        }
        if ($manifestType !== $type) {
            $selected = $type === 'plugin' ? '플러그인' : '패키지';
            $actual = $manifestType === 'plugin' ? '플러그인' : '패키지';
            return Result::failure("이 확장은 {$actual}입니다. 종류를 '{$actual}'(으)로 선택해서 다시 업로드하세요. (현재 선택: {$selected})");
        }

        $reasons = $this->compatibility->check($manifest, Application::VERSION, $this->installedVersions());
        if ($reasons !== []) {
            return Result::failure('호환되지 않는 확장입니다: ' . implode(' / ', $reasons));
        }

        return Result::success('', ['manifest' => $manifest]);
    }

    /**
     * 임시 디렉토리에 해제한 뒤 최종 위치로 이동한다.
     *
     * 최종 위치에 직접 풀다 실패하면 반쯤 설치된 디렉토리가 스캔에 잡히므로,
     * 같은 볼륨의 임시 디렉토리에 모두 푼 다음 rename 으로 한 번에 옮긴다.
     */
    private function extract(
        \ZipArchive $zip,
        string $rootDir,
        string $basePath,
        string $targetDir,
        array $manifest,
        string $type,
        array $verification
    ): Result {
        $tempDir = $basePath . '/.upload-' . bin2hex(random_bytes(6));

        if (!@mkdir($tempDir, 0755, true)) {
            return Result::failure('임시 디렉토리를 생성할 수 없습니다.');
        }

        try {
            $copied = $this->extractEntries($zip, $rootDir, $tempDir);
            if (!$copied->isSuccess()) {
                return $copied;
            }

            $extracted = $tempDir . '/' . $rootDir;
            if (!is_dir($extracted) || !@rename($extracted, $targetDir)) {
                return Result::failure('확장 디렉토리 이동에 실패했습니다.');
            }
        } finally {
            $this->removeDir($tempDir);
        }

        $label = (string) ($manifest['label'] ?? $rootDir);
        $typeLabel = $type === 'plugin' ? '플러그인' : '패키지';
        $verified = ($verification['status'] ?? '') === 'verified';
        $trustMessage = $verified
            ? " 서명 확인: {$verification['publisher']}."
            : ' 서명되지 않은 수동 업로드입니다.';

        error_log(sprintf(
            '[ExtensionInstaller] installed type=%s name=%s source=%s status=%s publisher=%s key_id=%s payload_sha256=%s',
            $type,
            $rootDir,
            (string) ($verification['source'] ?? 'unknown'),
            (string) ($verification['status'] ?? 'unknown'),
            (string) ($verification['publisher'] ?? '-'),
            (string) ($verification['key_id'] ?? '-'),
            (string) ($verification['payload_sha256'] ?? '-')
        ));

        return Result::success(
            "{$typeLabel} '{$label}' 이(가) 설치되었습니다.{$trustMessage} 목록에서 체크하고 저장하면 활성화됩니다.",
            [
                'name' => $rootDir,
                'type' => $type,
                'label' => $label,
                'verification' => $verification,
            ]
        );
    }

    /**
     * rootDir 아래 항목만 스트림 복사로 해제한다.
     *
     * extractTo() 대신 항목별 스트림 복사를 쓰는 이유:
     * 사전 스캔의 크기 검증은 zip 헤더의 "신고" 크기를 합산할 뿐이라 거짓 기재로
     * 우회할 수 있다. 실제 기록되는 바이트를 세면서 상한을 강제해야
     * 압축 폭탄이 디스크를 고갈시키지 못한다. junk 항목(__MACOSX 등)은 복사하지 않는다.
     */
    private function extractEntries(\ZipArchive $zip, string $rootDir, string $tempDir): Result
    {
        $written = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);

            if (!str_starts_with($entry, $rootDir . '/')) {
                continue; // validateStructure 가 허용한 junk 항목
            }

            // rootDir 안쪽의 junk(.DS_Store/Thumbs.db/__MACOSX)도 추출하지 않는다.
            // digest(ExtensionPackageVerifier)는 junk 를 서명에서 제외하므로, 추출까지 하면
            // 서명되지 않은 파일이 'verified' 설치본에 섞여 서명 커버리지가 우회된다.
            if ($this->isJunkEntry($entry)) {
                continue;
            }

            $dest = $tempDir . '/' . $entry;

            if (str_ends_with($entry, '/')) {
                if (!is_dir($dest) && !@mkdir($dest, 0755, true)) {
                    return Result::failure('압축 해제에 실패했습니다.');
                }
                continue;
            }

            $destDir = dirname($dest);
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
                return Result::failure('압축 해제에 실패했습니다.');
            }

            $in = $zip->getStreamIndex($i);
            if ($in === false) {
                return Result::failure('압축 해제에 실패했습니다.');
            }

            $out = @fopen($dest, 'wb');
            if ($out === false) {
                fclose($in);
                return Result::failure('압축 해제에 실패했습니다.');
            }

            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    fclose($in);
                    fclose($out);
                    return Result::failure('압축 해제에 실패했습니다.');
                }

                $written += strlen($chunk);
                if ($written > self::MAX_UNCOMPRESSED_SIZE) {
                    fclose($in);
                    fclose($out);
                    return Result::failure('압축 해제 후 크기가 허용 범위를 초과했습니다.');
                }

                // short-write 처리: fwrite 는 디스크 포화 등에서 요청보다 적은 양수를 반환할 수
                // 있다. === false 만 보면 잘린 파일이 그대로 rename 되므로, 전량 기록될 때까지 루프.
                $chunkLen = strlen($chunk);
                $offset = 0;
                while ($offset < $chunkLen) {
                    $bytesWritten = fwrite($out, substr($chunk, $offset));
                    if ($bytesWritten === false || $bytesWritten === 0) {
                        fclose($in);
                        fclose($out);
                        return Result::failure('압축 해제에 실패했습니다.');
                    }
                    $offset += $bytesWritten;
                }
            }

            fclose($in);
            fclose($out);
        }

        return Result::success();
    }


    /**
     * 설치된 확장의 버전 맵 ("plugin:Name"/"package:Name" => version).
     * ExtensionCompatibility::check 의 installedVersions 입력용.
     */
    private function installedVersions(): array
    {
        $versions = [];

        foreach (['plugin' => $this->pluginPath, 'package' => $this->packagePath] as $type => $basePath) {
            if (!is_dir($basePath)) {
                continue;
            }
            foreach (glob($basePath . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $manifestFile = $dir . '/manifest.json';
                if (!is_file($manifestFile)) {
                    continue;
                }
                $manifest = json_decode((string) file_get_contents($manifestFile), true);
                if (!is_array($manifest)) {
                    continue;
                }
                $versions["{$type}:" . basename($dir)] = (string) ($manifest['version'] ?? '1.0.0');
            }
        }

        return $versions;
    }

    /**
     * 디렉토리를 재귀 삭제한다 (임시 디렉토리 정리용).
     */
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
