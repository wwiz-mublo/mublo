<?php
namespace Mublo\Service\Block;

use Mublo\Infrastructure\Image\ImageProcessor;

/**
 * BlockKitScreenshot
 *
 * 블록 킷 스크린샷의 변환(내보내기)과 검증(가져오기)을 담당한다.
 *
 * 스크린샷은 화면에 렌더되는 블록 이미지가 아니라 블록 킷 자체의 초상이므로,
 * zip 번들을 기다리지 않고 data URI 로 블록 킷 JSON 에 임베드한다(설계 4.7).
 */
class BlockKitScreenshot
{
    public const WIDTH = 1200;
    public const HEIGHT = 900;
    public const MAX_BYTES = 512000; // 500 KiB
    public const QUALITY = 82;

    /**
     * 가져오기에서 허용하는 임베드 포맷.
     *
     * 내보내기는 webp(GD 미지원 시 png 폴백)로 변환해 생성하므로 블록 킷 파일에
     * jpeg 는 등장하지 않는다. png 는 폴백 산출물이자 수작업 블록 킷 호환용이다.
     * SVG 는 스크립트를 품을 수 있어 받지 않는다.
     */
    private const ALLOWED_MIME = ['image/png', 'image/webp'];

    /**
     * 목록 썸네일이 놓이는 곳. `<img>` 로 읽어야 하므로 웹에서 접근 가능한 storage 다.
     *
     * 블록 킷 JSON 은 DB 에 있고 여기 있는 것은 그 사본의 초상뿐이므로, 파일이 사라져도
     * 블록 킷은 멀쩡하다. 목록에 "스크린샷 없음" 이 뜰 뿐이다.
     */
    private const SCREENSHOT_DIR = 'kit-screenshots';

    private string $storagePath;

    public function __construct(
        private ImageProcessor $imageProcessor,
        ?string $storagePath = null
    ) {
        // 테스트가 실제 public/storage 를 건드리지 않도록 주입 가능하게 둔다.
        $this->storagePath = $storagePath
            ?? (defined('MUBLO_PUBLIC_STORAGE_PATH') ? MUBLO_PUBLIC_STORAGE_PATH : 'public/storage');
    }

    /**
     * 내보내기: 업로드 이미지를 webp(폴백 png) data URI 로 변환한다.
     *
     * 크기를 검증해 거부하지 않고 변환한다 — 저작자 사이트에서는 멀쩡히 만들어진 블록 킷이
     * 대상 사이트의 upload_max_filesize 에 걸려 가져오기가 실패하는 것을 막기 위해서다.
     *
     * 포맷 정책(#39): WebP 는 선택(용량 최적화)이지 요구사항이 아니다. GD 에 WebP 가
     * 없으면 PNG 로 폴백한다 — ALLOWED_MIME 이 png 를 받으므로 가져오기 호환도 유지된다.
     *
     * @param string $sourcePath 업로드된 임시 파일 경로
     * @return string|null data URI, 변환 불가 시 null (스크린샷만 버리고 블록 킷은 진행)
     */
    public function toDataUri(string $sourcePath): ?string
    {
        if (!is_file($sourcePath)) {
            return null;
        }

        $destPath = tempnam(sys_get_temp_dir(), 'mublo-kit-shot');
        if ($destPath === false) {
            return null;
        }

        $format = $this->outputFormat();

        try {
            $ok = $format === 'webp'
                ? $this->imageProcessor->resizeToWebp($sourcePath, $destPath, self::WIDTH, self::HEIGHT, self::QUALITY)
                : $this->imageProcessor->resizeToPng($sourcePath, $destPath, self::WIDTH, self::HEIGHT, self::QUALITY);

            if (!$ok) {
                return null;
            }

            $binary = @file_get_contents($destPath);
            if ($binary === false || $binary === '' || strlen($binary) > self::MAX_BYTES) {
                return null;
            }

            return 'data:image/' . $format . ';base64,' . base64_encode($binary);
        } finally {
            @unlink($destPath);
        }
    }

    /**
     * 썸네일 출력 포맷 결정 — webp 지원 시 webp, 아니면 png 폴백.
     *
     * 폴백은 조용하면 안 된다(#39 원칙 3) — 관리자가 고칠 수 있는 서버 설정
     * 문제이므로 로그로 알린다. (요청당 여러 번 구워도 로그는 한 번만)
     */
    private function outputFormat(): string
    {
        if ($this->imageProcessor->supportsWebp()) {
            return 'webp';
        }

        static $warned = false;
        if (!$warned) {
            $warned = true;
            error_log('[Mublo] GD 에 WebP 지원이 없어 블록 킷 스크린샷을 PNG 로 처리합니다. (용량만 늘 뿐 동작은 동일 — gd_info() 의 WebP Support 참고)');
        }

        return 'png';
    }

    /**
     * 가져오기: data URI 가 실제 이미지인지 검증한다.
     *
     * 프리픽스 확인 → 디코드 → getimagesizefromstring() 으로 실물 확인.
     * 폴리글랏 파일(이미지 헤더를 가진 스크립트) 방어를 위해 재인코딩은 저장 시점에 한다.
     */
    public function isValidDataUri(?string $dataUri): bool
    {
        return $this->decode($dataUri) !== null;
    }

    /**
     * data URI 를 원본 바이트로 디코드한다.
     *
     * @return string|null 유효하지 않으면 null
     */
    public function decode(?string $dataUri): ?string
    {
        if (!is_string($dataUri) || $dataUri === '') {
            return null;
        }

        if (!preg_match('#^data:(image/(?:png|webp));base64,#', $dataUri, $matches)) {
            return null;
        }

        if (!in_array($matches[1], self::ALLOWED_MIME, true)) {
            return null;
        }

        $binary = base64_decode(substr($dataUri, strlen($matches[0])), true);
        if ($binary === false || $binary === '' || strlen($binary) > self::MAX_BYTES) {
            return null;
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            return null;
        }

        if (!in_array($info['mime'] ?? '', self::ALLOWED_MIME, true)) {
            return null;
        }

        return $binary;
    }

    /**
     * 블록 킷의 스크린샷 data URI 를 썸네일 파일로 굽는다(설계 4.7-③).
     *
     * 목록이 블록 킷 JSON 을 파싱해 data URI 를 꺼내면, 스무 개짜리 목록이 2 MiB × 20 을
     * 읽는다. 업로드 시 한 번만 추출해 파일로 두고 `<img src>` 로 건다.
     *
     * ## 디코드한 바이트를 그대로 쓰지 않는다
     *
     * decode() 는 getimagesizefromstring() 으로 "이미지처럼 보이는지" 확인할 뿐이다.
     * 이미지 헤더를 가진 폴리글랏 파일은 그 검사를 통과한다. 그래서 GD 로 **다시
     * 인코딩**해 굽는다. 재인코딩을 거치면 원본의 바이트는 한 조각도 남지 않는다.
     *
     * @return string|null 웹 경로(`/storage/...`), 실패 시 null (스크린샷만 버린다)
     */
    public function store(?string $dataUri, int $domainId, int $kitId): ?string
    {
        $binary = $this->decode($dataUri);
        if ($binary === null) {
            // 역량 부재와 데이터 문제를 구분해 남긴다(#39 원칙 3).
            // 스크린샷이 아예 없는 킷은 정상 케이스라 조용히 지나간다.
            if (is_string($dataUri) && $dataUri !== '') {
                error_log("[Mublo] 블록 킷 스크린샷 data URI 가 유효하지 않아 썸네일을 만들지 않습니다 (kit {$kitId}) — 킷 파일 문제입니다.");
            }
            return null;
        }

        $relativeDir = self::SCREENSHOT_DIR . '/D' . $domainId;
        $fullDir = $this->storagePath . '/' . $relativeDir;

        if (!is_dir($fullDir) && !@mkdir($fullDir, 0755, true) && !is_dir($fullDir)) {
            error_log("[Mublo] 블록 킷 썸네일 디렉터리를 만들 수 없습니다 ({$fullDir}) — 쓰기 권한 문제입니다.");
            return null;
        }

        // 확장자는 실제 포맷을 말해야 한다(#39) — PNG 로 구우면서 .webp 로 이름 짓지 않는다.
        $format = $this->outputFormat();
        $fileName = $kitId . '.' . $format;
        $destPath = $fullDir . '/' . $fileName;

        $sourcePath = tempnam(sys_get_temp_dir(), 'mublo-kit-shot-in');
        if ($sourcePath === false) {
            return null;
        }

        try {
            if (@file_put_contents($sourcePath, $binary) === false) {
                return null;
            }

            // 여기서 폴리글랏이 죽는다. GD 가 픽셀을 읽어 새 파일을 만든다.
            $ok = $format === 'webp'
                ? $this->imageProcessor->resizeToWebp($sourcePath, $destPath, self::WIDTH, self::HEIGHT, self::QUALITY)
                : $this->imageProcessor->resizeToPng($sourcePath, $destPath, self::WIDTH, self::HEIGHT, self::QUALITY);

            if (!$ok) {
                // 재인코딩 실패도 원인을 구분한다: webp 소스인데 GD 가 webp 를 읽지
                // 못하는 서버(역량)와, 그 외의 손상 소스(데이터)는 고치는 사람이 다르다.
                $mime = (string) (@getimagesizefromstring($binary)['mime'] ?? '알 수 없음');
                if ($mime === 'image/webp' && !function_exists('imagecreatefromwebp')) {
                    error_log("[Mublo] GD 에 WebP 디코드 지원이 없어 블록 킷 스크린샷(webp)을 읽지 못했습니다 (kit {$kitId}) — 서버 GD 설정 문제입니다. 킷을 PNG 스크린샷으로 다시 내보내면 표시됩니다.");
                } else {
                    error_log("[Mublo] 블록 킷 스크린샷 재인코딩에 실패했습니다 (kit {$kitId}, {$mime}) — 킷 파일 문제일 가능성이 높습니다.");
                }
                return null;
            }

            return '/storage/' . $relativeDir . '/' . $fileName;
        } finally {
            @unlink($sourcePath);
        }
    }

    /**
     * 블록 킷이 지워질 때 썸네일도 지운다.
     *
     * 경로는 우리가 만든 것이지만 DB 를 손으로 고칠 수 있으므로, 스크린샷 디렉터리
     * 밖을 가리키면 지우지 않는다. `../../config/app.php` 를 지우게 둘 수는 없다.
     */
    public function remove(?string $webPath): bool
    {
        if (!is_string($webPath) || !str_starts_with($webPath, '/storage/' . self::SCREENSHOT_DIR . '/')) {
            return false;
        }

        $relative = substr($webPath, strlen('/storage/'));
        if (str_contains($relative, '..')) {
            return false;
        }

        $fullPath = $this->storagePath . '/' . $relative;

        return is_file($fullPath) && @unlink($fullPath);
    }
}
