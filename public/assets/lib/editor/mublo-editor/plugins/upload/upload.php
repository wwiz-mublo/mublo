<?php
/**
 * MubloEditor 이미지 업로드 핸들러
 *
 * 플러그인 구조:
 * plugins/
 *   upload/
 *     upload.php      - 이 파일 (업로드 핸들러)
 *
 * 설정:
 * - config.php: 기본 설정
 * - config.local.php: 프레임워크별 오버라이드
 *
 * 사용법:
 * 1. config.local.php로 storage_path, storage_url 설정
 * 2. MubloEditor에서 uploadUrl 지정
 */

class MubloEditorUploader {
    private static ?array $config = null;

    /**
     * 설정 로드
     *
     * config.php는 필수이며, storage_path와 storage_url을 반드시 포함해야 합니다.
     * config.local.php로 프레임워크별 설정을 오버라이드할 수 있습니다.
     *
     * 프레임워크에서 EditorHelper::configure() 등으로 주입한 런타임 설정
     * ($GLOBALS['_Mublo_editor_config'])이 있으면 가장 우선 적용됩니다.
     * 이를 통해 도메인별 저장 경로(storage_path/storage_url)가 반영됩니다.
     */
    public static function getConfig(): array
    {
        // 런타임 오버라이드가 있으면 매 호출마다 병합 (도메인 전환 대응)
        $runtime = $GLOBALS['_Mublo_editor_config'] ?? [];

        if (self::$config === null) {
            $configPath = dirname(__DIR__, 2) . '/config.php';
            $localPath = dirname(__DIR__, 2) . '/config.local.php';

            // config.php 필수 로드
            if (!file_exists($configPath)) {
                throw new \RuntimeException('config.php is required: ' . $configPath);
            }

            $config = require $configPath;

            // config.local.php 오버라이드 (있으면)
            if (file_exists($localPath)) {
                $config = array_merge($config, require $localPath);
            }

            // 경로가 아닌 설정만 기본값 적용
            $defaults = [
                'temp_folder' => 'temp',
                'max_file_size' => 5 * 1024 * 1024,
                'allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                'include_domain' => false,
                'temp_expire_hours' => 24,
            ];

            self::$config = array_merge($defaults, $config);
        }

        // 런타임 값이 있으면 병합 (도메인별 storage_path/url, upload_url, csrf_token 등)
        $merged = $runtime
            ? array_merge(self::$config, array_intersect_key($runtime, array_flip([
                'storage_path', 'storage_url', 'temp_folder',
                'max_file_size', 'allowed_types', 'allowed_extensions',
                'include_domain', 'temp_expire_hours',
            ])))
            : self::$config;

        // 필수 설정 검증 (config.php + 런타임 모두 반영 후)
        if (empty($merged['storage_path'])) {
            throw new \RuntimeException('storage_path is required in config.');
        }
        if (empty($merged['storage_url'])) {
            throw new \RuntimeException('storage_url is required in config.');
        }

        return $merged;
    }

    /**
     * HTML 본문을 분석하여 임시 폴더의 이미지를 실제 폴더로 이동하고 경로를 수정합니다.
     *
     * 이동이 실패하면 해당 이미지의 URL은 재작성하지 않고 원본 유지합니다.
     * (깨진 이미지를 DB에 저장하는 사일런트 오류 방지)
     *
     * 사용법:
     *   기본: $cleanHtml = MubloEditorUploader::processHtml($rawHtml, 'board/notice/2026-01-31');
     *   커스텀 경로: $cleanHtml = MubloEditorUploader::processHtml($rawHtml, 'shop/product/FOOD/G-20260210-0001',
     *                    '/path/to/storage/uploads/D1', '/storage/uploads/D1');
     *   이동 추적: $cleanHtml = MubloEditorUploader::processHtml($rawHtml, 'board/...', null, null, $movedFiles);
     *
     * @param string $html 에디터 원본 HTML
     * @param string $targetFolder 이동할 대상 폴더 (예: 'board/notice/2026-01-31')
     * @param string|null $targetBasePath 커스텀 대상 기본 경로 (null이면 config storage_path 사용)
     * @param string|null $targetBaseUrl 커스텀 대상 기본 URL (null이면 config storage_url 사용)
     * @param array|null &$moved (out) 이동된 파일 목록 [['source' => ..., 'dest' => ...], ...]
     * @return string 경로가 수정된 HTML
     */
    public static function processHtml(string $html, string $targetFolder, ?string $targetBasePath = null, ?string $targetBaseUrl = null, ?array &$moved = null): string
    {
        if ($moved === null) {
            $moved = [];
        }

        if (empty($html)) {
            return $html;
        }

        $targetFolder = self::sanitizeFolder($targetFolder);
        if ($targetFolder === '') {
            return $html;
        }

        try {
            $config = self::getConfig();
        } catch (\Throwable $e) {
            return $html;
        }

        // 임시 파일 소스는 항상 editor config 기준
        $storagePath = rtrim($config['storage_path'], '/\\');
        $storageUrl = rtrim($config['storage_url'], '/');
        $tempFolder = $config['temp_folder'] ?? 'temp';

        // 대상 경로: 커스텀 지정 시 해당 경로, 미지정 시 editor 기본 경로
        $destBasePath = $targetBasePath !== null ? rtrim($targetBasePath, '/\\') : $storagePath;
        $destBaseUrl = $targetBaseUrl !== null ? rtrim($targetBaseUrl, '/') : $storageUrl;

        // Windows/Unix 호환 경로
        $ds = DIRECTORY_SEPARATOR;
        $tempDir = $storagePath . $ds . $tempFolder . $ds;
        $targetDir = $destBasePath . $ds . str_replace(['/', '\\'], $ds, $targetFolder) . $ds;

        // HTML에서 temp 이미지 URL이 있는지 먼저 확인
        if (strpos($html, $storageUrl . '/' . $tempFolder . '/') === false) {
            return $html;
        }

        // 대상 디렉토리 생성 (실제 이동 직전에 시도)
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            // 디렉토리 생성 실패 → 이동 자체가 불가능하므로 원본 HTML 반환
            return $html;
        }

        // URL 패턴: /storage/editor/temp/filename.jpg
        $escapedUrl = preg_quote($storageUrl, '#');
        $escapedTemp = preg_quote($tempFolder, '#');

        /**
         * 파일 이동 콜백 — 성공 시 true, 실패 시 false
         *
         * @param array &$moved (out) 성공 시 이동 내역 추가
         */
        $moveFile = function(string $fileName) use ($tempDir, $targetDir, &$moved): bool {
            $cleanFileName = preg_replace('/\?.*$/', '', $fileName);
            $sourcePath = $tempDir . $cleanFileName;
            $destPath = $targetDir . $cleanFileName;

            // 이미 대상 경로에 존재하면 성공으로 간주
            if (file_exists($destPath)) {
                return true;
            }

            if (!file_exists($sourcePath)) {
                return false;
            }

            if (@rename($sourcePath, $destPath)) {
                $moved[] = ['source' => $sourcePath, 'dest' => $destPath];
                return true;
            }

            if (@copy($sourcePath, $destPath)) {
                @unlink($sourcePath);
                $moved[] = ['source' => $sourcePath, 'dest' => $destPath];
                return true;
            }

            return false;
        };

        // src, data-src 속성에서 임시 폴더 이미지 찾기
        $attrPattern = '#((?:src|data-src)\s*=\s*["\']?)(' . $escapedUrl . '/' . $escapedTemp . '/)([^"\'>\s]+)(["\']?)#i';

        $html = preg_replace_callback($attrPattern, function($matches) use ($moveFile, $targetFolder, $destBaseUrl) {
            $prefix = $matches[1];
            $tempPrefix = $matches[2];
            $fileName = $matches[3];
            $suffix = $matches[4];

            if (!$moveFile($fileName)) {
                // 이동 실패 → 원본 URL 유지
                return $prefix . $tempPrefix . $fileName . $suffix;
            }

            return $prefix . $destBaseUrl . '/' . $targetFolder . '/' . $fileName . $suffix;
        }, $html) ?? $html;

        // srcset 속성에서 임시 폴더 이미지 찾기 (여러 URL 포함 가능)
        $srcsetPattern = '#(srcset\s*=\s*["\'])([^"\']+)(["\'])#i';

        $tempUrlPrefix = $storageUrl . '/' . $tempFolder . '/';
        $result = preg_replace_callback($srcsetPattern, function($matches) use ($moveFile, $targetFolder, $destBaseUrl, $tempUrlPrefix) {
            $prefix = $matches[1];
            $srcsetValue = $matches[2];
            $suffix = $matches[3];

            // srcset: "url1 1x, url2 2x" 형태 파싱
            $entries = array_map('trim', explode(',', $srcsetValue));
            $processed = [];
            foreach ($entries as $entry) {
                $parts = preg_split('/\s+/', $entry, 2);
                $url = $parts[0];
                $descriptor = $parts[1] ?? '';

                if (strpos($url, $tempUrlPrefix) === 0) {
                    $fileName = substr($url, strlen($tempUrlPrefix));
                    if ($moveFile($fileName)) {
                        $url = $destBaseUrl . '/' . $targetFolder . '/' . $fileName;
                    }
                    // 이동 실패 시 원본 URL 유지
                }
                $processed[] = trim($url . ' ' . $descriptor);
            }
            return $prefix . implode(', ', $processed) . $suffix;
        }, $html) ?? $html;

        return $result;
    }

    /**
     * processHtml에서 이동된 파일을 원래 임시 위치로 되돌림 (DB 저장 실패 시 롤백)
     *
     * @param array $moved processHtml의 out-param으로 받은 이동 내역
     */
    public static function rollbackMoved(array $moved): void
    {
        foreach ($moved as $entry) {
            $source = $entry['source'] ?? null;
            $dest = $entry['dest'] ?? null;

            if (!$source || !$dest || !file_exists($dest)) {
                continue;
            }

            // 원래 위치로 복원을 시도하되, 실패하면 최소한 이동된 파일은 삭제하여 고아 방지
            $sourceDir = dirname($source);
            if (!is_dir($sourceDir)) {
                @mkdir($sourceDir, 0755, true);
            }

            if (!@rename($dest, $source)) {
                if (@copy($dest, $source)) {
                    @unlink($dest);
                } else {
                    // 복원 실패 → 고아 방지를 위해 이동된 파일 삭제
                    @unlink($dest);
                }
            }
        }
    }

    /**
     * 임시 폴더의 오래된 파일 삭제 (Garbage Collection)
     *
     * 사용법: MubloEditorUploader::cleanupTemp(24);
     *
     * @param int|null $hours 유지 시간 (시간 단위, null이면 config 사용)
     */
    public static function cleanupTemp(?int $hours = null): void
    {
        $config = self::getConfig();
        $hours = $hours ?? ($config['temp_expire_hours'] ?? 24);
        $storagePath = rtrim($config['storage_path'], '/\\');
        $tempFolder = $config['temp_folder'] ?? 'temp';
        $tempDir = $storagePath . '/' . $tempFolder . '/';

        if (!is_dir($tempDir)) return;

        $files = scandir($tempDir);
        $now = time();
        $limit = $hours * 3600;

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;

            $filePath = $tempDir . $file;

            if (is_file($filePath) && ($now - filemtime($filePath) > $limit)) {
                @unlink($filePath);
            }
        }
    }

    /**
     * Sanitize folder path — allow alphanumeric, underscore, hyphen, slash.
     * Prevents traversal (..), leading slash, consecutive slashes.
     */
    private static function sanitizeFolder(string $folder): string
    {
        $folder = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $folder);
        $folder = preg_replace('#\.\.+#', '', $folder);
        $folder = preg_replace('#/{2,}#', '/', $folder);
        $folder = trim($folder, '/');
        return $folder;
    }

    /**
     * HTTP 요청을 처리하는 메인 핸들러 (AJAX 호출용)
     */
    public static function handleRequest(): void
    {
        // Fileinfo extension check
        if (!class_exists('finfo')) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'MISSING_FINFO', 'message' => 'PHP fileinfo extension is required.']);
            exit;
        }

        try {
            $config = self::getConfig();
        } catch (\Throwable $e) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'CONFIG_ERROR', 'message' => 'Configuration error: ' . $e->getMessage()]);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');

        // CORS — config에서 제어 가능
        $allowedOrigin = $config['cors_origin'] ?? '*';
        header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.']);
            exit;
        }

        $storagePath = rtrim($config['storage_path'], '/\\');
        $storageUrl = rtrim($config['storage_url'], '/');
        $includeDomain = $config['include_domain'] ?? false;

        // URL 생성
        if ($includeDomain) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
            $baseUploadUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . $storageUrl . '/';
        } else {
            $baseUploadUrl = $storageUrl . '/';
        }

        // [기능 1] 파일 이동 — 기본 비활성화 (config에서 허용해야 사용 가능)
        if (($_REQUEST['action'] ?? '') === 'move') {
            if (!($config['allow_move_action'] ?? false)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'MOVE_DISABLED', 'message' => 'Move action is disabled.']);
                exit;
            }

            $fileName = basename($_REQUEST['file'] ?? '');
            $targetFolder = self::sanitizeFolder($_REQUEST['target'] ?? '');
            $fromFolder = self::sanitizeFolder($_REQUEST['from'] ?? ($config['temp_folder'] ?? 'temp'));

            if (!$fileName || !$targetFolder) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'MISSING_PARAMS', 'message' => 'File name and target folder are required.']);
                exit;
            }

            $sourcePath = $storagePath . '/' . $fromFolder . '/' . $fileName;
            $targetPath = $storagePath . '/' . $targetFolder . '/' . $fileName;

            if (file_exists($sourcePath)) {
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

                if (rename($sourcePath, $targetPath)) {
                    echo json_encode(['success' => true, 'url' => $baseUploadUrl . $targetFolder . '/' . $fileName]);
                    exit;
                }
            }

            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'FILE_NOT_FOUND', 'message' => 'File not found.']);
            exit;
        }

        // [기능 2] 파일 업로드
        $folder = self::sanitizeFolder($_REQUEST['folder'] ?? ($config['temp_folder'] ?? 'temp'));
        if (empty($folder)) $folder = $config['temp_folder'] ?? 'temp';

        $uploadDir = $storagePath . '/' . $folder . '/';
        $uploadUrl = $baseUploadUrl . $folder . '/';

        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'DIR_CREATE_FAILED', 'message' => 'Failed to create directory.']);
                exit;
            }
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = isset($_FILES['file']) ? $_FILES['file']['error'] : -1;
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'UPLOAD_FAILED', 'message' => 'File upload failed (error code: ' . $errorCode . ').']);
            exit;
        }

        $file = $_FILES['file'];
        $maxSize = $config['max_file_size'] ?? 5 * 1024 * 1024;
        $allowedTypes = $config['allowed_types'] ?? ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $allowedExtensions = $config['allowed_extensions'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if ($file['size'] > $maxSize) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'FILE_TOO_LARGE', 'message' => 'File exceeds maximum size (' . round($maxSize / 1024 / 1024, 1) . 'MB).']);
            exit;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, $allowedTypes)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'INVALID_TYPE', 'message' => 'File type not allowed: ' . $mimeType]);
            exit;
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'INVALID_EXTENSION', 'message' => 'File extension not allowed: ' . $extension]);
            exit;
        }

        $newFilename = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $uploadPath = $uploadDir . $newFilename;

        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
            echo json_encode([
                'success' => true,
                'url' => $uploadUrl . $newFilename,
                'filename' => $newFilename,
                'originalName' => $file['name'],
                'size' => $file['size'],
                'type' => $mimeType
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'SAVE_FAILED', 'message' => 'Failed to save file.']);
        }
    }
}

// 직접 실행은 기본 차단 (무인증 업로드 방지)
// 운영 환경에서는 프레임워크 라우트 POST /api/v1/editor/upload 사용을 권장한다.
// config.local.php 에서 'allow_standalone_handler' => true 설정 시에만 레거시 모드 허용.
// standalone 모드는 별도 인증/CSRF 보호가 없으므로 공개 운영 환경에서 사용하지 않는다.
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    try {
        $cfg = MubloEditorUploader::getConfig();
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'CONFIG_ERROR', 'message' => $e->getMessage()]);
        exit;
    }

    if (empty($cfg['allow_standalone_handler'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'STANDALONE_DISABLED',
            'message' => 'Standalone upload handler is disabled. Use /api/v1/editor/upload.',
        ]);
        exit;
    }

    MubloEditorUploader::handleRequest();
}
