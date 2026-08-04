<?php
declare(strict_types=1);
namespace Mublo\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\UploadConfig;
use Mublo\Infrastructure\Storage\UploadPolicy;
use Mublo\Infrastructure\Security\RateLimiter;
use Mublo\Service\Auth\AuthService;

/**
 * 에디터 이미지 업로드 API
 *
 * POST /api/editor/upload
 *
 * 책임:
 * - MubloEditor(및 다른 에디터)에서 본문에 삽입하는 이미지의 임시 저장
 * - 도메인별 분리 저장 경로 보장 (public/storage/D{domainId}/editor/temp/)
 * - 프레임워크 미들웨어(SessionMiddleware, CsrfMiddleware)를 경유하여 보안 강화
 *
 * 응답:
 * - success: { success: true, url, filename, originalName, size, type }
 * - error:   { success: false, error, message }
 */
class EditorUploadController
{
    // 크기 상한은 config/upload.php 의 editor_image 에서 운영자가 정한다(UploadConfig).
    // 회원과 비로그인 방문자를 따로 잡는데, 이 엔드포인트는 인증 없이 호출할 수 있고
    // 저장된 파일이 공개 URL 로 열리므로 같은 한도를 주면 무단 업로드로 디스크를
    // 채우거나 파일 호스팅으로 악용할 여지가 그만큼 커지기 때문이다.

    // 비인증 접근 가능 엔드포인트(비회원 글쓰기 등) — IP 단위 남용 제한.
    // 정상 편집(글당 이미지 수십 개)은 통과하되 대량 업로드 DoS는 차단.
    private const RATE_LIMIT_MAX = 60;        // 윈도우당 최대 업로드 수
    private const RATE_LIMIT_WINDOW = 600;    // 윈도우 길이(초) = 10분

    public function __construct(
        private ?RateLimiter $rateLimiter = null,
        private ?AuthService $auth = null,
    ) {
    }

    // 에디터 본문 이미지는 이미지 타입만 허용(코어 allowlist 안에서 좁히기).
    // 실제 확장자-MIME 일치 검증은 UploadPolicy가 담당.
    private const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
    ];

    public function upload(Request $request, Context $context): JsonResponse
    {
        // IP 단위 남용 제한 (비인증 접근 가능 — 대량 업로드 DoS/파일 호스팅 남용 차단)
        if ($this->rateLimiter !== null) {
            $ip = $request->getClientIp();
            $rlKey = 'editor-upload:' . ($context->getDomainId() ?? 0) . ':' . $ip;
            if (!$this->rateLimiter->attempt($rlKey, self::RATE_LIMIT_MAX, self::RATE_LIMIT_WINDOW)) {
                return JsonResponse::error('업로드 요청이 너무 많습니다. 잠시 후 다시 시도해주세요.', null, 429);
            }
        }

        if (!class_exists('finfo')) {
            return JsonResponse::error('서버에 fileinfo 확장이 필요합니다.', null, 500);
        }

        if (!$request->hasFile('file')) {
            return JsonResponse::error('파일이 업로드되지 않았습니다.', null, 400);
        }

        $file = $request->getRawFile('file');
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            return JsonResponse::error('단일 파일만 업로드할 수 있습니다.', null, 400);
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            // php.ini 가 config/upload.php 보다 작게 잡혀 있으면 여기서 먼저 걸린다.
            // "error 1" 만 보여주면 원인을 찾을 단서가 없으므로 어디를 고쳐야 하는지 밝힌다.
            if ($error === UPLOAD_ERR_INI_SIZE) {
                return JsonResponse::error(
                    '파일이 서버가 허용하는 업로드 크기를 초과했습니다. (php.ini 의 upload_max_filesize)',
                    null,
                    400
                );
            }

            return JsonResponse::error('파일 업로드에 실패했습니다. (error ' . $error . ')', null, 400);
        }

        $maxSize = $this->maxFileSize();
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxSize) {
            $maxMb = round($maxSize / 1024 / 1024, 1);
            return JsonResponse::error('파일 크기가 허용 범위를 초과했습니다. (최대 ' . $maxMb . 'MB)', null, 400);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return JsonResponse::error('업로드 파일을 찾을 수 없습니다.', null, 400);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmpName);

        $originalName = (string) ($file['name'] ?? 'upload');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // 이미지-only 좁히기 + 코어 UploadPolicy로 확장자-실제MIME 일치 강제(위조 차단, fail-closed)
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true) || !UploadPolicy::matches($extension, $mime)) {
            return JsonResponse::error('허용되지 않은 파일 형식입니다.', null, 400);
        }

        $domainId = (int) ($context->getDomainId() ?? 0);
        [$storagePath, $storageUrl] = $this->resolveStoragePaths($domainId);

        $tempFolder = 'editor/temp';
        $uploadDir = $storagePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $tempFolder) . DIRECTORY_SEPARATOR;

        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return JsonResponse::error('업로드 디렉토리를 생성할 수 없습니다.', null, 500);
        }

        $newFilename = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $destPath = $uploadDir . $newFilename;

        if (!@move_uploaded_file($tmpName, $destPath)) {
            return JsonResponse::error('파일 저장에 실패했습니다.', null, 500);
        }

        $url = $storageUrl . '/' . $tempFolder . '/' . $newFilename;

        return JsonResponse::success([
            'url'          => $url,
            'filename'     => $newFilename,
            'originalName' => $originalName,
            'size'         => $size,
            'type'         => $mime,
        ], '업로드되었습니다.');
    }

    /**
     * 이번 요청에 적용할 크기 상한(bytes)
     *
     * 로그인 회원과 비로그인 방문자에 다른 값을 쓴다. AuthService 를 주입받지 못했으면
     * 로그인 여부를 알 수 없으므로 비회원 한도를 적용한다(fail-closed).
     */
    private function maxFileSize(): int
    {
        $isGuest = $this->auth === null || !$this->auth->check();

        return UploadConfig::editorImageMaxBytes($isGuest);
    }

    /**
     * 도메인별 저장 경로 / URL 결정
     *
     * @return array{0: string, 1: string} [storagePath, storageUrl]
     */
    private function resolveStoragePaths(int $domainId): array
    {
        $basePath = defined('MUBLO_PUBLIC_STORAGE_PATH')
            ? MUBLO_PUBLIC_STORAGE_PATH
            : ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/storage';

        if ($domainId > 0) {
            return [
                rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'D' . $domainId,
                '/storage/D' . $domainId,
            ];
        }

        return [rtrim($basePath, '/\\'), '/storage'];
    }
}
