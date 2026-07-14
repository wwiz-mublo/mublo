<?php

namespace Mublo\Infrastructure\Storage;

/**
 * UploadPolicy
 *
 * 업로드 허용 타입의 단일 진실(코어 allowlist). `확장자 → 허용 finfo MIME` 맵.
 *
 * 설계:
 * - allowlist 방식: 목록에 없는 모든 타입(실행/액티브 콘텐츠 포함)은 자동 차단.
 *   denylist처럼 위험 확장자를 일일이 열거하지 않는다(영원히 미완성이므로).
 * - svg/html 등 인라인 서빙 시 스크립트가 실행될 수 있는 포맷은 의도적으로 부재
 *   → 어떤 업로드 경로에서도(관리자 포함) 코어에서 원천 차단된다.
 * - 검증은 확장자뿐 아니라 finfo로 감지한 실제 MIME과의 일치까지 본다(확장자 위조 방지).
 *   · 이미지: 위조-XSS에 민감하므로 해당 이미지 MIME만 엄격 허용.
 *   · 문서/압축: 인라인 실행 위험이 낮고 finfo 판별이 흔들리므로(예: 구형 오피스가
 *     octet-stream, OOXML이 application/zip) 현실적 MIME을 관대하게 허용.
 *
 * 기능단(게시판 file_extension_allowed 등)은 이 목록 안에서 "좁히기"만 할 수 있고,
 * 목록 밖으로 넓힐 수 없다. 실제 강제는 FileUploader::upload()가 수행한다(불가피 게이트).
 */
final class UploadPolicy
{
    /** @var array<string, string[]> 확장자(소문자) => 허용 finfo MIME 목록 */
    private const ALLOWED = [
        // 이미지 (위조 민감 → 해당 이미지 MIME만 엄격 허용, octet-stream/text 불허)
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
        'ico'  => ['image/vnd.microsoft.icon', 'image/x-icon'],

        // 문서
        'pdf'  => ['application/pdf'],
        'txt'  => ['text/plain'],
        'md'   => ['text/plain', 'text/markdown'],
        'json' => ['application/json', 'text/plain'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv'],
        'doc'  => ['application/msword', 'application/x-ole-storage', 'application/octet-stream'],
        'xls'  => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/octet-stream'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'hwp'  => ['application/x-hwp', 'application/haansofthwp', 'application/vnd.hancom.hwp', 'application/x-ole-storage', 'application/octet-stream'],
        'hwpx' => ['application/hwp+zip', 'application/vnd.hancom.hwpx', 'application/zip'],

        // 압축
        'zip'  => ['application/zip', 'application/octet-stream'],
        'rar'  => ['application/x-rar', 'application/x-rar-compressed', 'application/vnd.rar', 'application/octet-stream'],
        '7z'   => ['application/x-7z-compressed', 'application/octet-stream'],
        'tar'  => ['application/x-tar', 'application/octet-stream'],
        'gz'   => ['application/gzip', 'application/x-gzip', 'application/octet-stream'],
        'tgz'  => ['application/gzip', 'application/x-gzip', 'application/octet-stream'],
    ];

    /**
     * 코어가 허용하는 전체 확장자 목록 (기능단이 교집합으로 좁힐 때 기준)
     *
     * @return string[]
     */
    public static function allowedExtensions(): array
    {
        return array_keys(self::ALLOWED);
    }

    /**
     * 확장자가 코어 allowlist에 포함되는가
     */
    public static function isAllowedExtension(string $extension): bool
    {
        return isset(self::ALLOWED[strtolower($extension)]);
    }

    /**
     * 확장자 + 실제 감지 MIME이 코어 정책에 부합하는가 (최종 강제 판정).
     *
     * 판별 불가(null/빈 MIME)면 fail-closed로 거부한다.
     *
     * @param string      $extension    파일 확장자
     * @param string|null $detectedMime finfo로 감지한 실제 MIME
     */
    public static function matches(string $extension, ?string $detectedMime): bool
    {
        $ext = strtolower($extension);
        if (!isset(self::ALLOWED[$ext])) {
            return false;
        }
        if ($detectedMime === null || $detectedMime === '') {
            return false; // 내용 판별 불가 → 안전하게 거부
        }

        return in_array(strtolower($detectedMime), self::ALLOWED[$ext], true);
    }
}
