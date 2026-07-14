<?php

namespace Mublo\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Extension\NestedPlugin;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\AbstractResponse;
use Mublo\Core\Response\FileResponse;
use Mublo\Entity\Domain\Domain;

/**
 * ServeController
 *
 * 정적 파일 서빙 컨트롤러
 * - Plugin, Package, Views의 정적 파일(CSS, JS, 이미지 등) 서빙
 * - ETag, Last-Modified 기반 캐싱 (304 응답)
 * - 보안: 경로 탐색 공격 방지
 *
 * URL 패턴:
 * - /serve/package/{name}/{path:.*}    → Packages/{name}/...
 * - /serve/plugin/{name}/{path:.*}     → Plugin/{name}/...
 * - /serve/admin/{skin}/{path:.*}      → views/Admin/{skin}/_assets/...
 * - /serve/front/{skin}/{path:.*}      → views/Front/frame/{skin}/_assets/...
 * - /serve/front/view/{group}/{skin}/{path:.*} → views/Front/{Group}/{skin}/_assets/...
 * - /serve/block/{type}/{skin}/{path:.*} → views/Block/{type}/{skin}/...
 * - /serve/views/admin/{path:.*}       → views/Admin/... (레거시)
 * - /serve/views/front/{path:.*}       → views/Front/... (레거시)
 */
class ServeController
{
    /**
     * MIME 타입 매핑
     */
    private const MIME_TYPES = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'json'  => 'application/json',
        'xml'   => 'application/xml',
        'html'  => 'text/html',
        'htm'   => 'text/html',
        'txt'   => 'text/plain',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'webp'  => 'image/webp',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'eot'   => 'application/vnd.ms-fontobject',
        'otf'   => 'font/otf',
        'mp4'   => 'video/mp4',
        'webm'  => 'video/webm',
        'mp3'   => 'audio/mpeg',
        'wav'   => 'audio/wav',
        'pdf'   => 'application/pdf',
        'zip'   => 'application/zip',
    ];

    /**
     * 허용된 확장자 목록
     * 보안: html/htm 제거 (XSS 위험)
     */
    private const ALLOWED_EXTENSIONS = [
        'css', 'js', 'json', 'xml', 'txt',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp',
        'woff', 'woff2', 'ttf', 'eot', 'otf',
        'mp4', 'webm', 'mp3', 'wav', 'ogg',
        'pdf', 'zip',
    ];

    /**
     * 차단할 PHP 관련 확장자
     */
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps',
        'inc', 'htaccess', 'htpasswd',
    ];

    /**
     * 스트리밍 대상 확장자 (Range 요청 지원)
     */
    private const STREAMING_EXTENSIONS = ['mp4', 'webm', 'mp3', 'wav', 'ogg', 'pdf'];

    /**
     * 캐시 유효 시간 (초)
     */
    private const CACHE_MAX_AGE = 86400; // 24시간

    /**
     * 최대 파일 크기 (100MB)
     */
    private const MAX_FILE_SIZE = 104857600;

    /**
     * 프레임워크 루트 경로
     */
    private string $basePath;

    public function __construct()
    {
        $this->basePath = dirname(__DIR__, 3); // framework/ 디렉토리
    }

    /**
     * Package 정적 파일 서빙
     *
     * @param string $name Package 이름
     * @param string $path 파일 경로
     */
    public function package(string $name, string $path, Request $request, Context $context): AbstractResponse
    {
        // 보안: Package 이름 검증
        if (!$this->isValidName($name)) {
            return $this->errorResponse(400, 'Invalid package name');
        }

        if (!$this->isExtensionAssetAllowed($name, 'package', $context)) {
            return $this->errorResponse(403, 'Extension asset not allowed');
        }

        $baseDir = MUBLO_PACKAGE_PATH . '/' . $name;
        return $this->serve($baseDir, $path, $request);
    }

    /**
     * Plugin 정적 파일 서빙
     *
     * 패키지 종속 플러그인은 URL 세그먼트에 "{Pkg}.{Name}" 표기를 쓴다
     * (내부 이름 "{Pkg}/{Name}" 의 '/' 는 경로 구분자라 세그먼트에 못 쓴다).
     * 예: /serve/plugin/Board.BoardReport/assets/report.js
     *
     * @param string $name Plugin 이름 (또는 "{Pkg}.{Name}")
     * @param string $path 파일 경로
     */
    public function plugin(string $name, string $path, Request $request, Context $context): AbstractResponse
    {
        // 보안: Plugin 이름 검증
        if (!$this->isValidName($name, allowNestedDot: true)) {
            return $this->errorResponse(400, 'Invalid plugin name');
        }

        $internalName = NestedPlugin::fromUrlKey($name);

        if (!$this->isExtensionAssetAllowed($internalName, 'plugin', $context)) {
            return $this->errorResponse(403, 'Extension asset not allowed');
        }

        $baseDir = NestedPlugin::isNested($internalName)
            ? NestedPlugin::dir($internalName)
            : MUBLO_PLUGIN_PATH . '/' . $name;

        if ($baseDir === null) {
            return $this->errorResponse(400, 'Invalid plugin name');
        }

        return $this->serve($baseDir, $path, $request);
    }

    /**
     * Package/Plugin 이름 검증
     * 영문, 숫자, 하이픈, 언더스코어만 허용.
     * $allowNestedDot 이면 패키지 종속 표기("Pkg.Name" — 점 1개)도 허용한다.
     */
    private function isValidName(string $name, bool $allowNestedDot = false): bool
    {
        $pattern = $allowNestedDot
            ? '/^[a-zA-Z0-9_-]+(\.[a-zA-Z0-9_-]+)?$/'
            : '/^[a-zA-Z0-9_-]+$/';
        return preg_match($pattern, $name) === 1;
    }

    private function isExtensionAssetAllowed(string $name, string $type, Context $context): bool
    {
        $domain = $context->getDomainInfo();
        if (!$domain instanceof Domain) {
            return false;
        }

        $enabled = $type === 'plugin'
            ? $domain->getEnabledPlugins()
            : $domain->getEnabledPackages();

        // Nested Plugin Asset도 Runtime과 같은 도메인의 부모 활성 상태를 따른다.
        if ($type === 'plugin' && NestedPlugin::isNested($name)) {
            $parent = NestedPlugin::parentPackage($name);
            if ($parent !== null && !$this->containsExtensionName($parent, $domain->getEnabledPackages())) {
                return false;
            }
        }

        if ($this->containsExtensionName($name, $enabled)) {
            return true;
        }

        return $this->isDefaultExtension($name, $type);
    }

    private function containsExtensionName(string $name, array $enabled): bool
    {
        $normalized = $this->normalizeExtensionName($name);
        foreach ($enabled as $enabledName) {
            if (is_string($enabledName) && $this->normalizeExtensionName($enabledName) === $normalized) {
                return true;
            }
        }

        return false;
    }

    private function isDefaultExtension(string $name, string $type): bool
    {
        if ($type === 'plugin' && NestedPlugin::isNested($name)) {
            $extDir = NestedPlugin::dir($name);
        } else {
            $basePath = $type === 'plugin' ? MUBLO_PLUGIN_PATH : MUBLO_PACKAGE_PATH;
            $extDir = $basePath . '/' . $name;
        }

        $manifestFile = $extDir . '/manifest.json';
        if (!is_file($manifestFile)) {
            return false;
        }

        $manifest = json_decode(file_get_contents($manifestFile), true);
        return is_array($manifest) && !empty($manifest['default']);
    }

    private function normalizeExtensionName(string $name): string
    {
        return strtolower(str_replace(['-', '_'], '', $name));
    }

    /**
     * Admin Views 정적 파일 서빙 (레거시)
     *
     * @param string $path 파일 경로
     */
    public function viewsAdmin(string $path, Request $request): AbstractResponse
    {
        $baseDir = $this->basePath . '/views/Admin';
        return $this->serveLegacyViewAsset($baseDir, $path, $request);
    }

    /**
     * Admin 스킨 에셋 서빙 (새 구조)
     *
     * URL: /serve/admin/{skin}/{path}
     * 실제 경로: views/Admin/frame/{skin}/_assets/{path}
     *
     * @param string $skin 프레임(셸) 스킨명 (예: basic, modern)
     * @param string $path 파일 경로 (예: css/admin.css)
     */
    public function adminSkinAsset(string $skin, string $path, Request $request): AbstractResponse
    {
        // 스킨명 검증 (영문, 숫자, 하이픈, 언더스코어만 허용)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $skin)) {
            return $this->errorResponse(400, 'Invalid skin name');
        }

        $baseDir = $this->basePath . '/views/Admin/frame/' . $skin . '/_assets';
        return $this->serve($baseDir, $path, $request);
    }

    /**
     * Front 프레임 스킨 에셋 서빙
     *
     * URL: /serve/front/{skin}/{path}
     * 실제 경로: views/Front/frame/{skin}/_assets/{path}
     *
     * @param string $skin 스킨명 (예: basic, modern)
     * @param string $path 파일 경로 (예: css/front.css)
     */
    public function frontSkinAsset(string $skin, string $path, Request $request): AbstractResponse
    {
        // 스킨명 검증 (영문, 숫자, 하이픈, 언더스코어만 허용)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $skin)) {
            return $this->errorResponse(400, 'Invalid skin name');
        }

        $baseDir = $this->basePath . '/views/Front/frame/' . $skin . '/_assets';
        return $this->serve($baseDir, $path, $request);
    }

    /**
     * Front Content View 스킨 에셋 서빙
     *
     * URL: /serve/front/view/{group}/{skin}/{path}
     * 실제 경로: views/Front/{Group}/{skin}/_assets/{path}
     *
     * @param string $group View 그룹 (예: auth, member, board)
     * @param string $skin 스킨명 (예: basic, modern)
     * @param string $path 파일 경로 (예: css/auth.css)
     */
    public function frontViewSkinAsset(string $group, string $skin, string $path, Request $request): AbstractResponse
    {
        // 그룹명·스킨명 검증 (영문, 숫자, 하이픈, 언더스코어만 허용)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $group) || !preg_match('/^[a-zA-Z0-9_-]+$/', $skin)) {
            return $this->errorResponse(400, 'Invalid group or skin name');
        }

        $baseDir = $this->basePath . '/views/Front/' . ucfirst($group) . '/' . $skin . '/_assets';
        return $this->serve($baseDir, $path, $request);
    }

    /**
     * Block 스킨 에셋 서빙
     *
     * URL: /serve/block/{type}/{skin}/{path}
     * 실제 경로: views/Block/{type}/{skin}/{path}
     *
     * @param string $type 블록 콘텐츠 타입 (예: outlogin, board)
     * @param string $skin 스킨명 (예: basic)
     * @param string $path 파일 경로 (예: style.css)
     */
    public function blockSkinAsset(string $type, string $skin, string $path, Request $request): AbstractResponse
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $type) || !preg_match('/^[a-zA-Z0-9_-]+$/', $skin)) {
            return $this->errorResponse(400, 'Invalid type or skin name');
        }

        $baseDir = $this->basePath . '/views/Block/' . $type . '/' . $skin;
        return $this->serve($baseDir, $path, $request);
    }

    /**
     * Front Views 정적 파일 서빙 (레거시)
     *
     * @param string $path 파일 경로
     */
    public function viewsFront(string $path, Request $request): AbstractResponse
    {
        $baseDir = $this->basePath . '/views/Front';
        return $this->serveLegacyViewAsset($baseDir, $path, $request);
    }

    private function serveLegacyViewAsset(string $baseDir, string $path, Request $request): AbstractResponse
    {
        if (!$this->isAssetSubtreePath($path)) {
            return $this->errorResponse(403, 'Legacy view asset must be under _assets');
        }

        return $this->serve($baseDir, $path, $request);
    }

    private function isAssetSubtreePath(string $path): bool
    {
        $path = str_replace('\\', '/', urldecode($path));
        $path = trim((string) preg_replace('#/+#', '/', $path), '/');

        return $path === '_assets' || str_starts_with($path, '_assets/')
            || str_contains($path, '/_assets/');
    }

    /**
     * 파일 서빙 핵심 로직
     *
     * @param string $baseDir 기본 디렉토리
     * @param string $path 요청 경로
     */
    private function serve(string $baseDir, string $path, Request $request): AbstractResponse
    {
        // 1. 보안: 경로 정규화 및 탐색 공격 방지
        $path = $this->sanitizePath($path);
        if ($path === null) {
            return $this->errorResponse(400, 'Invalid path');
        }

        // 2. 전체 파일 경로 생성
        $filePath = $baseDir . '/' . $path;
        $realPath = realpath($filePath);

        // 3. 보안: 기본 디렉토리 내부인지 확인
        $realBaseDir = realpath($baseDir);
        if ($realPath === false || $realBaseDir === false) {
            return $this->errorResponse(404, 'File not found');
        }

        if (!str_starts_with($realPath, $realBaseDir . DIRECTORY_SEPARATOR)) {
            return $this->errorResponse(403, 'Access denied');
        }

        // 4. 파일 존재 및 읽기 가능 확인
        if (!is_file($realPath) || !is_readable($realPath)) {
            return $this->errorResponse(404, 'File not found');
        }

        // 5. 확장자 검증 (허용 목록 + 차단 목록)
        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));

        // 5-1. 차단 확장자 검증 (PHP 관련)
        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            return $this->errorResponse(403, 'File type blocked');
        }

        // 5-2. 허용 확장자 검증
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return $this->errorResponse(403, 'File type not allowed');
        }

        // 6. 파일 크기 검증
        $fileSize = filesize($realPath);
        if ($fileSize > self::MAX_FILE_SIZE) {
            return $this->errorResponse(413, 'File too large');
        }

        // 7. 캐싱 헤더 처리
        $lastModified = filemtime($realPath);
        $etag = $this->generateETag($realPath, $lastModified, $fileSize);

        // 8. 304 Not Modified 응답 체크
        if ($this->isNotModified($etag, $lastModified, $request)) {
            return new FileResponse(null, 304, [
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=' . self::CACHE_MAX_AGE,
            ]);
        }

        // 9. MIME 타입 결정
        $mimeType = self::MIME_TYPES[$extension] ?? 'application/octet-stream';

        // 10. 스트리밍 파일(비디오/오디오)은 Range 요청 지원
        if (in_array($extension, self::STREAMING_EXTENSIONS, true)) {
            return $this->serveWithRange($realPath, $mimeType, $fileSize, $etag, $lastModified, $request);
        }

        // 11. 일반 파일 응답 반환
        return new FileResponse($realPath, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => $fileSize,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=' . self::CACHE_MAX_AGE,
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * Range 요청을 지원하는 스트리밍 파일 서빙
     * 대용량 비디오/오디오 파일의 부분 다운로드 지원
     */
    private function serveWithRange(
        string $filePath,
        string $mimeType,
        int $fileSize,
        string $etag,
        int $lastModified,
        Request $request
    ): AbstractResponse {
        $start = 0;
        $end = $fileSize - 1;
        $statusCode = 200;

        // Range 헤더 파싱
        $rangeHeader = $request->header('Range', '');
        if (preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches)) {
            $start = $matches[1] !== '' ? (int)$matches[1] : 0;
            $end = $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;

            // 범위 유효성 검증
            if ($start > $end || $start >= $fileSize || $end >= $fileSize) {
                return $this->errorResponse(416, 'Range Not Satisfiable');
            }

            $statusCode = 206; // Partial Content
        }

        $length = $end - $start + 1;

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => $length,
            'Accept-Ranges' => 'bytes',
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=' . self::CACHE_MAX_AGE,
        ];

        if ($statusCode === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$fileSize}";
        }

        // FileResponse에 Range 정보 전달
        return new FileResponse($filePath, $statusCode, $headers, null, $start, $length);
    }

    /**
     * 경로 정규화 및 보안 검증
     *
     * @param string $path 요청 경로
     * @return string|null 정규화된 경로 또는 null (유효하지 않은 경우)
     */
    private function sanitizePath(string $path): ?string
    {
        // URL 디코딩
        $path = urldecode($path);

        // 널 바이트 제거
        $path = str_replace("\0", '', $path);

        // 역슬래시를 슬래시로 변환
        $path = str_replace('\\', '/', $path);

        // 연속 슬래시 제거
        $path = preg_replace('#/+#', '/', $path);

        // 앞뒤 슬래시 제거
        $path = trim($path, '/');

        // 경로 탐색 시도 감지
        if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
            return null;
        }

        // 숨김 파일 접근 차단
        if (preg_match('#(^|/)\.[^/]+#', $path)) {
            return null;
        }

        // PHP 및 위험한 확장자 차단
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            return null;
        }

        return $path;
    }

    /**
     * ETag 생성
     * 파일 경로 + 수정 시간 + 크기를 조합하여 고유 식별자 생성
     *
     * @param string $filePath 파일 경로
     * @param int $lastModified 마지막 수정 시간
     * @param int $fileSize 파일 크기
     */
    private function generateETag(string $filePath, int $lastModified, int $fileSize = 0): string
    {
        return '"' . md5($filePath . $lastModified . $fileSize) . '"';
    }

    /**
     * 304 응답 여부 확인
     *
     * @param string $etag 현재 ETag
     * @param int $lastModified 마지막 수정 시간
     */
    private function isNotModified(string $etag, int $lastModified, Request $request): bool
    {
        // If-None-Match 헤더 확인
        $ifNoneMatch = $request->header('If-None-Match', '');
        if ($ifNoneMatch === $etag) {
            return true;
        }

        // If-Modified-Since 헤더 확인
        $ifModifiedSince = $request->header('If-Modified-Since', '');
        if ($ifModifiedSince !== '') {
            $ifModifiedSinceTime = strtotime($ifModifiedSince);
            if ($ifModifiedSinceTime !== false && $ifModifiedSinceTime >= $lastModified) {
                return true;
            }
        }

        return false;
    }

    /**
     * 에러 응답 생성
     *
     * @param int $statusCode HTTP 상태 코드
     * @param string $message 에러 메시지
     */
    private function errorResponse(int $statusCode, string $message): AbstractResponse
    {
        return new FileResponse(null, $statusCode, [
            'Content-Type' => 'text/plain',
        ], $message);
    }
}
