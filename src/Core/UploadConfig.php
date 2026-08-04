<?php
declare(strict_types=1);

namespace Mublo\Core;

/**
 * config/upload.php 를 읽는 지점.
 *
 * 설치본마다 운영자가 직접 열어 고치는 파일이라 두 가지를 전제한다.
 *
 *  - 파일이나 키가 없을 수 있다 (1.0.0 이전 설치본, 또는 운영자가 지운 경우).
 *    이때는 여기 상수를 쓴다. 부재가 곧 "제한 없음" 이 되면 안 된다.
 *  - 값이 숫자가 아니거나 0 이하일 수 있다 (오타, 빈 문자열).
 *    이때도 기본값으로 되돌린다 — 오타 하나로 업로드가 통째로 막히거나
 *    반대로 상한이 사라지는 쪽이 더 나쁘다.
 *
 * 여기서 정한 값보다 php.ini 의 upload_max_filesize 가 작으면 그쪽이 먼저 막는다.
 */
final class UploadConfig
{
    /** 로그인 회원의 에디터 본문 이미지 상한(MB) */
    public const DEFAULT_EDITOR_MAX_SIZE_MB = 20;

    /** 비로그인 방문자의 에디터 본문 이미지 상한(MB) */
    public const DEFAULT_EDITOR_GUEST_MAX_SIZE_MB = 5;

    /**
     * 에디터 본문 이미지 한 장의 최대 크기(bytes)
     *
     * @param bool $isGuest 비로그인 방문자인가. 판단할 수 없으면 true(fail-closed)로 넘긴다.
     */
    public static function editorImageMaxBytes(bool $isGuest): int
    {
        $section = ConfigFile::load('upload')['editor_image'] ?? [];

        $key = $isGuest ? 'guest_max_size_mb' : 'max_size_mb';
        $default = $isGuest
            ? self::DEFAULT_EDITOR_GUEST_MAX_SIZE_MB
            : self::DEFAULT_EDITOR_MAX_SIZE_MB;

        $megabytes = is_array($section) ? ($section[$key] ?? $default) : $default;

        if (!is_numeric($megabytes) || (float) $megabytes <= 0) {
            $megabytes = $default;
        }

        return (int) round((float) $megabytes * 1024 * 1024);
    }
}
