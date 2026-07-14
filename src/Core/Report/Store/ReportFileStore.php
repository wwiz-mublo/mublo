<?php

namespace Mublo\Core\Report\Store;

use Mublo\Core\Report\Exception\ReportOutputException;

class ReportFileStore
{
    private string $baseDir;
    private string $exportDir;
    private string $chunkDir;
    private string $metaDir;

    public function __construct(?string $storagePath = null)
    {
        $storagePath ??= MUBLO_STORAGE_PATH;
        $this->baseDir = rtrim($storagePath, '/\\') . '/report';
        $this->exportDir = $this->baseDir . '/exports';
        $this->chunkDir = $this->baseDir . '/chunks';
        $this->metaDir = $this->baseDir . '/meta';

        $this->ensureDirectories();
    }

    public function createExportPath(string $extension): string
    {
        $extension = ltrim($extension, '.');
        $name = 'rep_' . date('Ymd_His') . '_' . bin2hex(random_bytes(16));
        return $this->exportDir . '/' . $name . '.' . $extension;
    }

    public function saveChunk(array $rows, int $domainId): string
    {
        if ($domainId < 1) {
            throw new ReportOutputException('잘못된 chunk 소유 도메인입니다.');
        }

        $ref = 'tmp_chunk_' . date('Ymd_His') . '_' . bin2hex(random_bytes(16));
        $path = $this->chunkDir . '/' . $ref . '.json';

        $encoded = json_encode([
            'domain_id' => $domainId,
            'rows' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new ReportOutputException('chunk 인코딩에 실패했습니다.');
        }

        if (file_put_contents($path, $encoded) === false) {
            throw new ReportOutputException('chunk 저장에 실패했습니다.');
        }

        return $ref;
    }

    public function loadChunk(string $ref, int $domainId): array
    {
        if (!$this->isChunkRef($ref) || $domainId < 1) {
            throw new ReportOutputException('잘못된 chunk 참조입니다.');
        }

        $path = $this->chunkDir . '/' . $ref . '.json';

        if (!$this->isPathWithin($path, $this->chunkDir)) {
            throw new ReportOutputException('잘못된 chunk 참조입니다.');
        }

        if (!is_file($path)) {
            throw new ReportOutputException('chunk 파일이 없습니다.');
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new ReportOutputException('chunk 파일을 읽을 수 없습니다.');
        }

        $payload = json_decode($content, true);
        if (!is_array($payload)
            || (int) ($payload['domain_id'] ?? 0) !== $domainId
            || !is_array($payload['rows'] ?? null)) {
            throw new ReportOutputException('chunk 형식이 올바르지 않습니다.');
        }

        return $payload['rows'];
    }

    /**
     * @return array{fileId:string,downloadUrl:string,expiresAt:string,sizeBytes:int}
     */
    public function registerMergedFile(
        string $filePath,
        string $filename,
        int $domainId,
        string $menuCode,
        int $ttlSeconds = 3600
    ): array {
        if (!$this->isPathWithin($filePath, $this->exportDir)) {
            throw new ReportOutputException('허용되지 않는 파일 경로입니다.');
        }

        if (!is_file($filePath)) {
            throw new ReportOutputException('병합 파일이 존재하지 않습니다.');
        }

        $fileId = 'rep_' . date('Ymd_His') . '_' . bin2hex(random_bytes(16));
        $expiresAt = time() + $ttlSeconds;
        $meta = [
            'file_path' => $filePath,
            'filename' => $filename,
            'domain_id' => $domainId,
            'menu_code' => $menuCode,
            'expires_at' => $expiresAt,
        ];

        $metaPath = $this->metaDir . '/' . $fileId . '.json';
        $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($metaPath, $encoded) === false) {
            throw new ReportOutputException('메타 파일 저장에 실패했습니다.');
        }

        return [
            'fileId' => $fileId,
            'downloadUrl' => '/admin/report/files/' . $fileId,
            'expiresAt' => date('c', $expiresAt),
            'sizeBytes' => filesize($filePath) ?: 0,
        ];
    }

    /**
     * @return array{file_path:string,filename:string,domain_id:int,menu_code:string}|null
     */
    public function resolveMergedFile(string $fileId): ?array
    {
        if (!$this->isFileId($fileId)) {
            return null;
        }

        $metaPath = $this->metaDir . '/' . $fileId . '.json';

        if (!$this->isPathWithin($metaPath, $this->metaDir)) {
            return null;
        }

        if (!is_file($metaPath)) {
            return null;
        }

        $content = file_get_contents($metaPath);
        if ($content === false) {
            return null;
        }

        $meta = json_decode($content, true);
        if (!is_array($meta)) {
            return null;
        }

        // 만료 확인
        if ((int) ($meta['expires_at'] ?? 0) < time()) {
            $this->safeUnlink($meta['file_path'] ?? '', $this->exportDir);
            $this->safeUnlink($metaPath, $this->metaDir);
            return null;
        }

        $path = $meta['file_path'] ?? '';
        if (!is_string($path) || !$this->isPathWithin($path, $this->exportDir) || !is_file($path)) {
            $this->safeUnlink($metaPath, $this->metaDir);
            return null;
        }

        return [
            'file_path' => $path,
            'filename' => (string) ($meta['filename'] ?? basename($path)),
            'domain_id' => (int) ($meta['domain_id'] ?? 0),
            'menu_code' => (string) ($meta['menu_code'] ?? ''),
        ];
    }

    /** 생성 또는 메타 등록에 실패한 export 파일을 안전한 경계 안에서 폐기한다. */
    public function discardExport(string $filePath): void
    {
        $this->safeUnlink($filePath, $this->exportDir);
    }

    /**
     * 만료된 파일 정리 (cron 등에서 호출)
     */
    public function cleanupExpired(): int
    {
        $count = 0;

        // 만료된 메타 + 내보내기 파일
        foreach (glob($this->metaDir . '/*.json') ?: [] as $metaPath) {
            $content = file_get_contents($metaPath);
            if ($content === false) {
                continue;
            }

            $meta = json_decode($content, true);
            if (!is_array($meta)) {
                $this->safeUnlink($metaPath, $this->metaDir);
                $count++;
                continue;
            }

            if ((int) ($meta['expires_at'] ?? 0) < time()) {
                $this->safeUnlink($meta['file_path'] ?? '', $this->exportDir);
                $this->safeUnlink($metaPath, $this->metaDir);
                $count++;
            }
        }

        // 1시간 이상 된 chunk 파일
        $threshold = time() - 3600;
        foreach (glob($this->chunkDir . '/*.json') ?: [] as $chunkPath) {
            if (filemtime($chunkPath) < $threshold) {
                $this->safeUnlink($chunkPath, $this->chunkDir);
                $count++;
            }
        }

        // 렌더/병합 도중 실패해 메타 없이 남은 export 파일. 전송 중인 파일과
        // 경합하지 않도록 chunk와 동일하게 1시간의 유예를 둔다.
        $referencedExports = [];
        foreach (glob($this->metaDir . '/*.json') ?: [] as $metaPath) {
            $content = file_get_contents($metaPath);
            $meta = $content !== false ? json_decode($content, true) : null;
            if (is_array($meta) && is_string($meta['file_path'] ?? null)) {
                $real = realpath($meta['file_path']);
                if ($real !== false) {
                    $referencedExports[$real] = true;
                }
            }
        }
        foreach (glob($this->exportDir . '/*') ?: [] as $exportPath) {
            $real = realpath($exportPath);
            $modified = filemtime($exportPath);
            if ($real !== false && !isset($referencedExports[$real])
                && $modified !== false && $modified < $threshold) {
                $this->safeUnlink($exportPath, $this->exportDir);
                $count++;
            }
        }

        return $count;
    }

    /**
     * 경로가 허용된 디렉토리 내에 있는지 검증
     */
    private function isPathWithin(string $path, string $allowedDir): bool
    {
        $realPath = realpath($path);
        $realDir = realpath($allowedDir);

        // 파일이 아직 존재하지 않으면 부모 디렉토리로 검증
        if ($realPath === false) {
            $realPath = realpath(dirname($path));
            if ($realPath === false) {
                return false;
            }
        }

        if ($realDir === false) {
            return false;
        }

        $realPath = rtrim($realPath, '/\\');
        $realDir = rtrim($realDir, '/\\');

        if (PHP_OS_FAMILY === 'Windows') {
            $realPath = strtolower($realPath);
            $realDir = strtolower($realDir);
        }

        return $realPath === $realDir
            || str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR);
    }

    private function safeUnlink(mixed $path, string $allowedDir): void
    {
        if (is_string($path) && $path !== ''
            && $this->isPathWithin($path, $allowedDir) && is_file($path)) {
            unlink($path);
        }
    }

    private function isChunkRef(string $ref): bool
    {
        return preg_match('/\Atmp_chunk_\d{8}_\d{6}_[a-f0-9]{32}\z/D', $ref) === 1;
    }

    private function isFileId(string $fileId): bool
    {
        return preg_match('/\Arep_\d{8}_\d{6}_[a-f0-9]{32}\z/D', $fileId) === 1;
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->baseDir, $this->exportDir, $this->chunkDir, $this->metaDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new ReportOutputException('리포트 저장소 디렉토리를 생성할 수 없습니다: ' . $dir);
            }
        }
    }
}
