<?php

namespace Mublo\Infrastructure\Storage;

/**
 * SecureFileService
 *
 * 보안 파일 업로드/다운로드 Core 서비스
 *
 * 민감한 파일(회원 필드 첨부, 주문 서류, AutoForm 첨부 등)을
 * 웹 접근 불가 영역(storage/files/)에 저장하고,
 * HMAC 토큰 기반 다운로드 URL을 생성한다.
 *
 * 플러그인/패키지 개발자가 DI로 주입받아 동일한 API로 보안 파일을 처리.
 *
 * 사용법:
 *   // 임시 업로드 (AJAX)
 *   $result = $secureFile->uploadTemp($file, $domainId, ['max_size' => 5*1024*1024]);
 *
 *   // 최종 이동 (폼 제출 시)
 *   $stored = $secureFile->moveFinal($tempPath, $domainId, 'member-fields', '123');
 *   $metaJson = $stored->toMetaJson();  // DB 저장
 *
 *   // 다운로드 URL 생성
 *   $url = $secureFile->generateDownloadUrl($stored->relativePath);
 *
 *   // 토큰 검증 (DownloadController에서)
 *   $resolved = $secureFile->resolveToken($token);
 */
class SecureFileService
{
    private string $basePath;
    private string $secretKey;

    private array $defaultAllowedExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'zip', 'rar', '7z',
    ];

    private array $dangerousExtensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps',
        'exe', 'sh', 'bat', 'cmd', 'com', 'scr', 'pif',
        'js', 'vbs', 'wsf', 'asp', 'aspx', 'jsp', 'cgi', 'pl',
        'htaccess', 'htpasswd', 'html', 'htm', 'svg',
    ];

    public function __construct(?string $basePath = null, ?string $secretKey = null)
    {
        $this->basePath = $basePath
            ?? (defined('MUBLO_STORAGE_PATH') ? MUBLO_STORAGE_PATH . '/files' : 'storage/files');

        $this->secretKey = $secretKey ?? $this->loadSecretKey();
    }

    // =========================================================================
    // 업로드
    // =========================================================================

    /**
     * 임시 업로드 (AJAX 파일 선택 시)
     * 저장: storage/files/temp/D{domainId}/{hash}.ext
     */
    public function uploadTemp(UploadedFile $file, int $domainId, array $config = []): UploadResult
    {
        if (!$file->isValid()) {
            return UploadResult::failure($file->getErrorMessage());
        }

        $extension = strtolower($file->getExtension());

        // 위험 확장자 차단
        if ($this->isDangerousExtension($extension)) {
            return UploadResult::failure('보안상 허용되지 않는 파일 형식입니다.');
        }

        // 이중 확장자 차단 (example.php.pdf)
        $nameParts = explode('.', $file->getName());
        if (count($nameParts) > 2) {
            foreach (array_slice($nameParts, 0, -1) as $part) {
                if (in_array(strtolower($part), $this->dangerousExtensions, true)) {
                    return UploadResult::failure('보안상 허용되지 않는 파일명입니다.');
                }
            }
        }

        // 허용 확장자 검사
        $allowedExt = $config['allowed_ext'] ?? null;
        if ($allowedExt) {
            $allowed = is_string($allowedExt)
                ? array_map('trim', explode(',', $allowedExt))
                : $allowedExt;
            if (!in_array($extension, array_map('strtolower', $allowed), true)) {
                return UploadResult::failure('허용되지 않은 파일 형식입니다: ' . $extension);
            }
        } else {
            if (!in_array($extension, $this->defaultAllowedExtensions, true)) {
                return UploadResult::failure('허용되지 않은 파일 형식입니다: ' . $extension);
            }
        }

        // 크기 검사
        $maxSizeMb = (int) ($config['max_size'] ?? 10);
        $maxSizeBytes = $maxSizeMb * 1024 * 1024;
        if ($file->getSize() > $maxSizeBytes) {
            return UploadResult::failure("파일 크기가 {$maxSizeMb}MB를 초과했습니다.");
        }

        // MIME 타입 검증
        $mimeType = $this->detectMimeType($file->getTmpName());

        // 코어 정책 강제: 확장자 allowlist + 실제 MIME 일치 (확장자 위조/액티브 콘텐츠 차단).
        // 불일치/판별불가는 fail-closed로 조용히 거부. FileUploader와 동일 게이트.
        if (!UploadPolicy::matches($extension, $mimeType ?: null)) {
            return UploadResult::failure('허용되지 않는 파일 형식입니다: ' . $extension);
        }

        // 저장 경로: storage/files/temp/D{domainId}/
        $relativePath = 'temp/D' . $domainId;
        $fullDir = $this->basePath . '/' . $relativePath;
        $this->ensureDirectory($fullDir);

        // 해시 기반 파일명
        $storedName = $this->generateStoredName($file->getName(), $extension);
        $fullPath = $fullDir . '/' . $storedName;

        if (!move_uploaded_file($file->getTmpName(), $fullPath)) {
            return UploadResult::failure('파일 저장에 실패했습니다.');
        }

        return UploadResult::success([
            'stored_name'   => $storedName,
            'relative_path' => $relativePath,
            'full_path'     => $fullPath,
            'original_name' => $file->getName(),
            'extension'     => $extension,
            'mime_type'     => $mimeType ?: ($file->getType() ?? ''),
            'size'          => $file->getSize(),
        ]);
    }

    /**
     * 임시 → 최종 이동
     * 저장: storage/files/D{domainId}/{category}/{entityId}/{hash}.ext
     */
    public function moveFinal(
        string $tempRelativePath,
        int $domainId,
        string $category,
        string $entityId
    ): SecureStoredFile {
        $this->assertRelativePath($tempRelativePath);
        $this->assertPathSegment($category, 'category');
        $this->assertPathSegment($entityId, 'entityId');
        $this->assertOwnedTempPath($tempRelativePath, $domainId);

        $storedName = basename($tempRelativePath);
        $tempFullPath = $this->basePath . '/' . $tempRelativePath;

        if (!file_exists($tempFullPath)) {
            throw new \RuntimeException('임시 파일을 찾을 수 없습니다: ' . $tempRelativePath);
        }

        // 최종 경로
        $finalDir = $this->buildDirPath($domainId, $category, $entityId);
        $finalRelativePath = $this->buildPath($domainId, $category, $entityId, $storedName);
        $finalFullPath = $this->basePath . '/' . $finalRelativePath;

        $this->ensureDirectory($this->basePath . '/' . $finalDir);

        if (!rename($tempFullPath, $finalFullPath)) {
            throw new \RuntimeException('파일 이동에 실패했습니다.');
        }

        // 파일 메타 수집
        $extension = pathinfo($storedName, PATHINFO_EXTENSION);
        $mimeType = $this->detectMimeType($finalFullPath) ?: '';
        $size = filesize($finalFullPath) ?: 0;

        return new SecureStoredFile(
            storedName:   $storedName,
            relativePath: $finalRelativePath,
            originalName: $storedName,  // 원본명은 caller가 DTO에 별도 보관
            size:         $size,
            mimeType:     $mimeType,
            extension:    $extension,
        );
    }

    /**
     * 상대 경로 → 디스크 절대 경로 반환
     *
     * temp 파일 등을 다른 저장소(public)로 이동시킬 때 원본 절대 경로가 필요한 경우 사용.
     *
     * 주의: 사용자가 제출한 temp_path 를 그대로 넘기지 말 것. 소유권 검증 없이 basePath 하위 임의
     * 경로를 절대경로로 돌려주므로, 사용자 입력 temp 소스는 resolveOwnedTempPath() 를 써야 한다.
     * (DB 에 저장된 신뢰 경로 조회에만 사용.)
     */
    public function fullPathOf(string $relativePath): string
    {
        $this->assertRelativePath($relativePath);
        return $this->basePath . '/' . $relativePath;
    }

    /**
     * 사용자 제출 temp 경로를 '호출자 도메인 소유'로 검증한 뒤 절대 경로로 해석.
     *
     * 아바타 등 공개 저장소로 편입할 때, moveFinal 과 동일한 IDOR 방어를 적용한다.
     * (사용자가 temp_path 에 타 회원·타 도메인의 최종 파일 경로를 넣어 공개 스토리지로
     *  복사·노출하는 것을 차단.)
     */
    public function resolveOwnedTempPath(string $tempRelativePath, int $domainId): string
    {
        $this->assertRelativePath($tempRelativePath);
        $this->assertOwnedTempPath($tempRelativePath, $domainId);
        return $this->basePath . '/' . $tempRelativePath;
    }

    /**
     * 소스가 '호출자 도메인의 temp 디렉토리(temp/D{domainId}/) 바로 아래 파일'인지 강제.
     *
     * uploadTemp() 가 만드는 정상 경로는 temp/D{domainId}/{hash}.ext 뿐이다. 이 검증이 없으면
     * 사용자가 타 회원·타 도메인의 최종 파일 경로(예: D1/member-fields/999/x.pdf)를 temp_path 로
     * 넘겨 그 파일을 자기 소유로 rename/편입할 수 있다(IDOR). moveFinal·resolveOwnedTempPath 공용.
     */
    private function assertOwnedTempPath(string $tempRelativePath, int $domainId): void
    {
        $expectedPrefix = 'temp/D' . $domainId . '/';
        $normalized = str_replace('\\', '/', $tempRelativePath);
        $remainder = str_starts_with($normalized, $expectedPrefix)
            ? substr($normalized, strlen($expectedPrefix))
            : null;
        if ($remainder === null || $remainder === '' || str_contains($remainder, '/')) {
            throw new \InvalidArgumentException(
                "Temp source must be a file directly under temp/D{$domainId}/: {$tempRelativePath}"
            );
        }
    }

    // =========================================================================
    // 다운로드 토큰
    // =========================================================================

    /**
     * 다운로드 URL 생성 (HMAC 서명 토큰)
     */
    public function generateDownloadUrl(string $relativePath, int $ttl = 3600, array $options = []): string
    {
        $this->assertRelativePath($relativePath);

        $disposition = ($options['disposition'] ?? 'attachment') === 'inline' ? 'i' : 'a';
        $filename = $options['filename'] ?? null;
        $bindIp = $options['bind_ip'] ?? false;

        $payload = [
            'p' => $relativePath,
            'x' => time() + $ttl,
            'o' => $disposition,
        ];

        if ($filename) {
            $payload['f'] = $filename;
        }

        $clientIp = $options['client_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        if ($bindIp && is_string($clientIp) && $clientIp !== '') {
            $payload['ip'] = $clientIp;
        }

        // HMAC 서명
        $payload['s'] = $this->sign($payload);

        $token = $this->base64urlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));

        return '/download/' . $token;
    }

    /**
     * 토큰 검증 → 파일 정보 반환
     */
    public function resolveToken(string $token, ?string $clientIp = null): ?array
    {
        $json = $this->base64urlDecode($token);
        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);
        if (!$payload || empty($payload['p']) || empty($payload['x']) || empty($payload['s'])) {
            return null;
        }

        // 서명 검증
        $signature = $payload['s'];
        unset($payload['s']);
        if (!hash_equals($this->sign($payload), $signature)) {
            return null;
        }

        // 만료 검증
        if (time() > (int) $payload['x']) {
            return null;
        }

        // IP 바인딩 검증
        if (!empty($payload['ip'])) {
            $clientIp ??= $_SERVER['REMOTE_ADDR'] ?? '';
            if ($payload['ip'] !== $clientIp) {
                return null;
            }
        }

        // 경로 파싱
        $parsed = $this->parsePath($payload['p']);
        if (!$parsed) {
            return null;
        }

        // realpath 검증 (basePath 내부인지)
        $fullPath = $this->basePath . '/' . $payload['p'];
        $realPath = realpath($fullPath);
        $realBase = realpath($this->basePath);

        if ($realPath === false || $realBase === false) {
            return null;
        }

        // Windows에서 백슬래시를 슬래시로 통일
        $realPath = str_replace('\\', '/', $realPath);
        $realBase = str_replace('\\', '/', $realBase);

        if (strpos($realPath, $realBase . '/') !== 0) {
            return null;
        }

        return [
            'path'        => $realPath,
            'domainId'    => $parsed['domainId'],
            'category'    => $parsed['category'],
            'entityId'    => $parsed['entityId'],
            'disposition' => ($payload['o'] ?? 'a') === 'i' ? 'inline' : 'attachment',
            'filename'    => $payload['f'] ?? null,
            'bind_ip'     => $payload['ip'] ?? null,
        ];
    }

    // =========================================================================
    // 메타 헬퍼
    // =========================================================================

    /**
     * DB 메타 JSON → 다운로드 URL 포함 배열 반환
     */
    public function parseMetaWithUrl(?string $metaJson, int $ttl = 3600, array $options = []): ?array
    {
        if (empty($metaJson)) {
            return null;
        }

        $meta = json_decode($metaJson, true);
        if (!$meta || empty($meta['relative_path'])) {
            return null;
        }

        // 통일된 반환 구조 (parseFileMeta static과 동일 키)
        $result = [
            'filename'  => $meta['original_name'] ?? $meta['stored_name'] ?? '',
            'size'      => $meta['size'] ?? 0,
            'mime_type' => $meta['mime_type'] ?? '',
            'extension' => $meta['extension'] ?? '',
            'disk'      => $meta['disk'] ?? 'public',
        ];

        // disk가 secure가 아니면 기존 방식 (공개 파일)
        if (($meta['disk'] ?? '') !== 'secure') {
            $result['url'] = '/storage/' . $meta['relative_path'];
            if (!empty($meta['stored_name']) && !str_ends_with($meta['relative_path'], $meta['stored_name'])) {
                $result['url'] .= '/' . $meta['stored_name'];
            }
            return $result;
        }

        $result['url'] = $this->generateDownloadUrl(
            $meta['relative_path'],
            $ttl,
            array_merge($options, [
                'filename' => $meta['original_name'] ?? $meta['stored_name'] ?? null,
            ]),
        );

        return $result;
    }

    // =========================================================================
    // 삭제
    // =========================================================================

    /**
     * 보안 파일 삭제
     */
    public function delete(string $relativePath): bool
    {
        $this->assertRelativePath($relativePath);

        $fullPath = $this->basePath . '/' . $relativePath;

        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }

        return true;
    }

    /**
     * DB 메타 JSON으로 파일 삭제
     */
    public function deleteByMeta(?string $metaJson): void
    {
        if (empty($metaJson)) {
            return;
        }

        $meta = json_decode($metaJson, true);
        if (!$meta || empty($meta['relative_path']) || ($meta['disk'] ?? '') !== 'secure') {
            return;
        }

        $this->delete($meta['relative_path']);
    }

    // =========================================================================
    // 임시 파일 정리
    // =========================================================================

    /**
     * 만료된 임시 파일 정리
     */
    public function cleanupTemp(?int $domainId = null, int $maxAgeSeconds = 86400): int
    {
        $tempDir = $this->basePath . '/temp';

        if (!is_dir($tempDir)) {
            return 0;
        }

        $cutoff = time() - $maxAgeSeconds;
        $deleted = 0;

        // 도메인 필터
        $scanDirs = [];
        if ($domainId !== null) {
            $domainTempDir = $tempDir . '/D' . $domainId;
            if (is_dir($domainTempDir)) {
                $scanDirs[] = $domainTempDir;
            }
        } else {
            $entries = scandir($tempDir);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $tempDir . '/' . $entry;
                if (is_dir($path)) {
                    $scanDirs[] = $path;
                }
            }
        }

        foreach ($scanDirs as $dir) {
            $files = scandir($dir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $filePath = $dir . '/' . $file;
                if (is_file($filePath) && filemtime($filePath) < $cutoff) {
                    if (unlink($filePath)) {
                        $deleted++;
                    }
                }
            }

            // 빈 디렉토리 정리
            if (is_dir($dir) && count(scandir($dir)) === 2) {
                rmdir($dir);
            }
        }

        return $deleted;
    }

    // =========================================================================
    // 경로 빌드/파싱 (내부 전용)
    // =========================================================================

    /**
     * 디렉토리 경로 생성 (파일명 미포함)
     */
    private function buildDirPath(int $domainId, string $category, string $entityId): string
    {
        $this->assertPathSegment($category, 'category');
        $this->assertPathSegment($entityId, 'entityId');

        return "D{$domainId}/{$category}/{$entityId}";
    }

    /**
     * 파일 전체 경로 생성
     */
    private function buildPath(int $domainId, string $category, string $entityId, string $filename): string
    {
        $this->assertPathSegment($category, 'category');
        $this->assertPathSegment($entityId, 'entityId');
        $this->assertPathSegment($filename, 'filename');

        return "D{$domainId}/{$category}/{$entityId}/{$filename}";
    }

    /**
     * 상대 경로 → [domainId, category, entityId, filename] 파싱
     * 경로 형식: D{id}/{category}/{entityId}/{filename}
     */
    private function parsePath(string $relativePath): ?array
    {
        // D1/member-fields/123/3f84a9c1.pdf
        $parts = explode('/', $relativePath);
        if (count($parts) !== 4) {
            return null;
        }

        $domainPart = $parts[0]; // D1
        if (!preg_match('/^D(\d+)$/', $domainPart, $matches)) {
            return null;
        }

        return [
            'domainId' => (int) $matches[1],
            'category' => $parts[1],
            'entityId' => $parts[2],
            'filename' => $parts[3],
        ];
    }

    private function assertRelativePath(string $relativePath): void
    {
        $relativePath = str_replace('\\', '/', $relativePath);

        if ($relativePath === ''
            || str_starts_with($relativePath, '/')
            || str_contains($relativePath, '..')
            || !preg_match('/^[a-zA-Z0-9_\/.-]+$/', $relativePath)
        ) {
            throw new \InvalidArgumentException("Invalid secure file path: {$relativePath}");
        }

        foreach (explode('/', $relativePath) as $segment) {
            $this->assertPathSegment($segment, 'path');
        }
    }

    private function assertPathSegment(string $segment, string $label): void
    {
        if ($segment === ''
            || $segment === '.'
            || $segment === '..'
            || str_contains($segment, '/')
            || str_contains($segment, '\\')
            || !preg_match('/^[a-zA-Z0-9_.-]+$/', $segment)
        ) {
            throw new \InvalidArgumentException("Invalid secure file {$label}: {$segment}");
        }
    }

    // =========================================================================
    // Private 헬퍼
    // =========================================================================

    /**
     * HMAC 서명 생성
     */
    private function sign(array $payload): string
    {
        // 키를 정렬하여 일관된 서명
        ksort($payload);
        $data = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', $data, $this->secretKey);
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string|false
    {
        $base64 = strtr($data, '-_', '+/');
        $padding = (4 - (strlen($base64) % 4)) % 4;

        if ($padding > 0) {
            $base64 .= str_repeat('=', $padding);
        }

        return base64_decode($base64, true);
    }

    private function generateStoredName(string $originalName, string $extension): string
    {
        // 128비트 순수 난수 — 저장 파일명을 예측 불가능하게 만든다(경로 추측 방지).
        $hash = bin2hex(random_bytes(16));
        return $hash . '.' . $extension;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function isDangerousExtension(string $extension): bool
    {
        return in_array(strtolower($extension), $this->dangerousExtensions, true);
    }

    /**
     * finfo로 실제 MIME 타입 검출
     */
    private function detectMimeType(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($filePath);

        return $mime ?: null;
    }

    /**
     * 시크릿 키 로드
     */
    private function loadSecretKey(): string
    {
        if (defined('MUBLO_CONFIG_PATH')) {
            $securityConfig = @include MUBLO_CONFIG_PATH . '/security.php';
            if (is_array($securityConfig) && !empty($securityConfig['csrf']['token_key'])) {
                return $securityConfig['csrf']['token_key'];
            }
        }

        // 폴백: 랜덤 키 (경고 — 재시작 시 기존 토큰 무효화)
        return bin2hex(random_bytes(32));
    }
}
