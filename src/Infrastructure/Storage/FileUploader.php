<?php
namespace Mublo\Infrastructure\Storage;

use Mublo\Core\Context\Context;

/**
 * FileUploader
 *
 * 파일 업로드 인프라 클래스
 * - 멀티 도메인 지원 (D{domain_id} 폴더 구조)
 * - 파일 저장/삭제/이동
 * - 확장자/크기 검증
 *
 * 저장 구조:
 * public/storage/D{domain_id}/{subdirectory}/{year}/{month}/{stored_name}
 *
 * @see .claude/skills/storage-path-rules.md
 */
class FileUploader
{
    private string $basePath;
    private array $defaultAllowedExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'zip', 'rar', '7z',
    ];

    // 보안상 절대 허용하지 않는 확장자
    private array $dangerousExtensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps',
        'exe', 'sh', 'bat', 'cmd', 'com', 'scr', 'pif',
        'js', 'vbs', 'wsf', 'asp', 'aspx', 'jsp', 'cgi', 'pl',
        'htaccess', 'htpasswd',
    ];

    // 내용 검증 대상: 실제로 getimagesize로 파싱돼야 하는 래스터 이미지 확장자
    // (확장자만 이미지로 위조한 svg/html/스크립트 업로드를 차단)
    private array $rasterImageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('MUBLO_PUBLIC_STORAGE_PATH') ? MUBLO_PUBLIC_STORAGE_PATH : 'public/storage');
    }

    /**
     * 파일 업로드
     *
     * @param UploadedFile $file 업로드된 파일
     * @param int $domainId 도메인 ID
     * @param array $options 옵션 [
     *   'allowed_extensions' => [],  // 허용 확장자 (비어있으면 기본값 사용)
     *   'max_size' => 10485760,      // 최대 크기 (bytes, 기본 10MB)
     *   'subdirectory' => '',        // 추가 하위 디렉토리 (예: 'board', 'avatar')
     *   'shared' => false,           // true이면 D{domainId}/ 접두사 없이 패키지 공유 경로 사용
     * ]
     * @return UploadResult
     */
    public function upload(UploadedFile $file, int $domainId, array $options = []): UploadResult
    {
        // 기본 옵션
        $allowedExtensions = $options['allowed_extensions'] ?? $this->defaultAllowedExtensions;
        // 코어 allowlist로 캡 — 호출자가 넘긴 목록이라도 코어 밖 타입(svg 등)은 허용하지 않는다
        $allowedExtensions = array_values(array_intersect(
            array_map('strtolower', $allowedExtensions),
            UploadPolicy::allowedExtensions()
        ));
        $maxSize = $options['max_size'] ?? 10 * 1024 * 1024; // 10MB
        $subdirectory = $this->normalizeSubdirectory((string) ($options['subdirectory'] ?? ''));

        // 파일 유효성 검사
        if (!$file->isValid()) {
            return UploadResult::failure($file->getErrorMessage());
        }

        // 확장자 검사
        $extension = $file->getExtension();
        if (!$this->isExtensionAllowed($extension, $allowedExtensions)) {
            return UploadResult::failure("허용되지 않은 파일 형식입니다: {$extension}");
        }

        // 위험한 확장자 차단
        if ($this->isDangerousExtension($extension)) {
            return UploadResult::failure('보안상 허용되지 않는 파일 형식입니다.');
        }

        // 크기 검사
        if ($file->getSize() > $maxSize) {
            $maxMb = round($maxSize / 1024 / 1024, 1);
            return UploadResult::failure("파일 크기가 {$maxMb}MB를 초과했습니다.");
        }

        // 코어 정책 강제: 실제 MIME(finfo)과 확장자 일치 확인 — 확장자 위조(svg→.jpg 등) 차단.
        // 불일치/판별불가는 fail-closed로 조용히 거부(예외 없이 이동/디렉토리 생성 전 종료).
        $mimeType = $file->getMimeType();
        if (!UploadPolicy::matches($extension, $mimeType)) {
            return UploadResult::failure("허용되지 않는 파일 형식입니다: {$extension}");
        }

        // 저장 경로 생성
        $shared = $options['shared'] ?? false;
        if ($shared) {
            // 패키지 공유: {subdirectory}/{year}/{month} (도메인 접두사 없음)
            $relativePath = trim($subdirectory, '/');
        } else {
            // 도메인 격리: D{domain_id}/{subdirectory}/{year}/{month}
            $relativePath = 'D' . $domainId;
            if ($subdirectory) {
                $relativePath .= '/' . trim($subdirectory, '/');
            }
        }
        if ($options['include_date'] ?? true) {
            $relativePath .= '/' . date('Y/m');
        }

        $fullDir = $this->basePath . '/' . $relativePath;
        if (!$this->ensureDirectory($fullDir)) {
            return UploadResult::failure('업로드 디렉토리를 생성할 수 없습니다.');
        }

        // 저장 파일명 생성 (해시 기반)
        $storedName = $this->generateStoredName($file->getName(), $extension);
        $fullPath = $fullDir . '/' . $storedName;

        // 이미지 메타 추출 (move_uploaded_file 후엔 tmp가 사라져 getimagesize가 실패하므로 이동 전에 읽는다.
        // $mimeType은 위 정책 검사에서 이미 확보)
        $imageWidth = null;
        $imageHeight = null;
        if ($file->isImage()) {
            $imageInfo = $file->getImageInfo();
            if ($imageInfo) {
                $imageWidth = $imageInfo['width'];
                $imageHeight = $imageInfo['height'];
            }
        }

        // 위조 차단: 래스터 이미지 확장자인데 실제 내용이 이미지로 파싱되지 않으면 거부.
        // (스크립트가 든 svg/html을 .jpg 등으로 위장한 업로드 → getimagesize 실패 → 차단)
        if ($imageWidth === null && in_array(strtolower($extension), $this->rasterImageExtensions, true)) {
            return UploadResult::failure("이미지 파일 형식이 올바르지 않습니다: {$extension}");
        }

        // 파일 이동
        if (!move_uploaded_file($file->getTmpName(), $fullPath)) {
            return UploadResult::failure('파일 저장에 실패했습니다.');
        }

        return UploadResult::success([
            'stored_name' => $storedName,
            'relative_path' => $relativePath,
            'full_path' => $fullPath,
            'original_name' => $file->getName(),
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size' => $file->getSize(),
            'image_width' => $imageWidth,
            'image_height' => $imageHeight,
        ]);
    }

    /**
     * 이미 디스크에 존재하는 파일을 public 저장소로 편입(이동)
     *
     * temp 영역(예: SecureFileService temp)에 검증을 마치고 올라온 파일을
     * 공개 저장 경로(public/storage/...)로 옮길 때 사용. upload()와 동일한
     * 경로 규칙(D{domain}/{subdirectory}/{Y/m})과 해시 파일명을 따른다.
     *
     * @param string $sourceFullPath 원본 절대 경로
     * @param int $domainId 도메인 ID
     * @param array $options ['subdirectory'=>'avatar', 'original_name'=>..., 'extension'=>..., 'include_date'=>true, 'shared'=>false]
     */
    public function adoptFile(string $sourceFullPath, int $domainId, array $options = []): UploadResult
    {
        if (!is_file($sourceFullPath)) {
            return UploadResult::failure('원본 파일을 찾을 수 없습니다.');
        }

        $originalName = (string) ($options['original_name'] ?? basename($sourceFullPath));
        $extension = strtolower((string) ($options['extension'] ?? pathinfo($sourceFullPath, PATHINFO_EXTENSION)));

        // 코어 allowlist 강제 + 실제 내용(finfo) 재검증.
        // adoptFile은 클라이언트가 제어하는 temp 메타(extension/mime)로 호출될 수 있으므로
        // 그 값을 신뢰하지 않는다. upload()/uploadTemp()와 동일하게 allowlist + finfo MIME 일치를
        // 강제해, 확장자 위조(폴리글롯 .jpg → .html/.pht)로 웹루트에 실행/HTML 파일이 편입되어
        // 저장형 XSS·RCE로 이어지는 것을 원천 차단한다.
        if ($extension === '' || !UploadPolicy::isAllowedExtension($extension) || $this->isDangerousExtension($extension)) {
            return UploadResult::failure('보안상 허용되지 않는 파일 형식입니다.');
        }

        $detectedMime = $this->detectMime($sourceFullPath);
        if (!UploadPolicy::matches($extension, $detectedMime)) {
            return UploadResult::failure("허용되지 않는 파일 형식입니다: {$extension}");
        }

        // 래스터 이미지 확장자는 실제 이미지로 파싱되는지까지 확인(스크립트가 든 폴리글롯 차단)
        if (in_array($extension, $this->rasterImageExtensions, true) && @getimagesize($sourceFullPath) === false) {
            return UploadResult::failure("이미지 파일 형식이 올바르지 않습니다: {$extension}");
        }

        // 저장 경로 생성 (upload()와 동일 규칙)
        $subdirectory = $this->normalizeSubdirectory((string) ($options['subdirectory'] ?? ''));
        if ($options['shared'] ?? false) {
            $relativePath = trim($subdirectory, '/');
        } else {
            $relativePath = 'D' . $domainId;
            if ($subdirectory) {
                $relativePath .= '/' . trim($subdirectory, '/');
            }
        }
        if ($options['include_date'] ?? true) {
            $relativePath .= '/' . date('Y/m');
        }

        $fullDir = $this->basePath . '/' . $relativePath;
        if (!$this->ensureDirectory($fullDir)) {
            return UploadResult::failure('업로드 디렉토리를 생성할 수 없습니다.');
        }

        $storedName = $this->generateStoredName($originalName, $extension);
        $fullPath = $fullDir . '/' . $storedName;

        $size = filesize($sourceFullPath) ?: 0;

        if (!rename($sourceFullPath, $fullPath)) {
            return UploadResult::failure('파일 이동에 실패했습니다.');
        }

        return UploadResult::success([
            'stored_name'   => $storedName,
            'relative_path' => $relativePath,
            'full_path'     => $fullPath,
            'original_name' => $originalName,
            'extension'     => $extension,
            'mime_type'     => $detectedMime,
            'size'          => $size,
        ]);
    }

    /**
     * 파일 삭제
     *
     * @param string $relativePath 상대 경로 (D1/2024/01)
     * @param string $storedName 저장된 파일명
     * @return bool
     */
    public function delete(string $relativePath, string $storedName): bool
    {
        $fullPath = $this->basePath . '/' . $relativePath . '/' . $storedName;

        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }

        return true; // 이미 없으면 성공으로 처리
    }

    /**
     * 전체 경로로 파일 삭제
     */
    public function deleteByFullPath(string $fullPath): bool
    {
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        return true;
    }

    /**
     * 파일 이동
     */
    public function move(string $fromPath, string $toPath): bool
    {
        if (!file_exists($fromPath)) {
            return false;
        }

        $toDir = dirname($toPath);
        if (!$this->ensureDirectory($toDir)) {
            return false;
        }

        return rename($fromPath, $toPath);
    }

    /**
     * 파일 복사
     */
    public function copy(string $fromPath, string $toPath): bool
    {
        if (!file_exists($fromPath)) {
            return false;
        }

        $toDir = dirname($toPath);
        if (!$this->ensureDirectory($toDir)) {
            return false;
        }

        return copy($fromPath, $toPath);
    }

    /**
     * 파일 존재 확인
     */
    public function exists(string $relativePath, string $storedName): bool
    {
        $fullPath = $this->basePath . '/' . $relativePath . '/' . $storedName;
        return file_exists($fullPath);
    }

    /**
     * 전체 경로 반환
     */
    public function getFullPath(string $relativePath, string $storedName): string
    {
        return $this->basePath . '/' . $relativePath . '/' . $storedName;
    }

    /**
     * URL 경로 반환 (웹 접근용)
     */
    public function getUrl(string $relativePath, string $storedName): string
    {
        return '/storage/' . $relativePath . '/' . $storedName;
    }

    /**
     * 도메인별 총 사용량 계산 (bytes)
     */
    public function getDomainUsage(int $domainId): int
    {
        $domainPath = $this->basePath . '/D' . $domainId;

        if (!is_dir($domainPath)) {
            return 0;
        }

        return $this->getDirectorySize($domainPath);
    }

    /**
     * 확장자 허용 여부 확인
     */
    public function isExtensionAllowed(string $extension, array $allowed = []): bool
    {
        if (empty($allowed)) {
            $allowed = $this->defaultAllowedExtensions;
        }

        return in_array(strtolower($extension), array_map('strtolower', $allowed), true);
    }

    /**
     * 위험한 확장자인지 확인
     */
    public function isDangerousExtension(string $extension): bool
    {
        return in_array(strtolower($extension), $this->dangerousExtensions, true);
    }

    /**
     * 디스크 파일의 실제 MIME을 finfo로 감지한다. 판별 불가 시 null.
     */
    private function detectMime(string $fullPath): ?string
    {
        if (!is_file($fullPath) || !function_exists('finfo_open')) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_file($finfo, $fullPath);
        finfo_close($finfo);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    /**
     * 저장 파일명 생성
     */
    private function generateStoredName(string $originalName, string $extension): string
    {
        $hash = md5(uniqid($originalName, true) . microtime(true) . random_bytes(8));
        return $hash . '.' . $extension;
    }

    /**
     * 저장 하위 경로 정규화 및 검증.
     */
    private function normalizeSubdirectory(string $subdirectory): string
    {
        $subdirectory = trim(str_replace('\\', '/', $subdirectory), '/');

        if ($subdirectory === '') {
            return '';
        }

        if (str_contains($subdirectory, '..') || !preg_match('/^[a-zA-Z0-9_\/-]+$/', $subdirectory)) {
            throw new \InvalidArgumentException("Invalid upload subdirectory: {$subdirectory}");
        }

        return $subdirectory;
    }

    /**
     * 디렉토리 생성 (없으면)
     */
    private function ensureDirectory(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }

        return mkdir($path, 0755, true);
    }

    /**
     * 디렉토리 크기 계산 (재귀)
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }
}
