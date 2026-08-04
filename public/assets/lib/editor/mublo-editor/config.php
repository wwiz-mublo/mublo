<?php
/**
 * MubloEditor 설정 파일
 *
 * 이 파일은 에디터의 기본 설정을 정의합니다.
 * 도메인별 동적 설정은 EditorHelper::configure()로 주입됩니다.
 *
 * 설정 우선순위:
 * 1. EditorHelper::configure() 동적 설정
 * 2. config.local.php (있으면 우선 적용)
 * 3. config.php (기본값 = 이 파일)
 *
 * 저장 경로 규칙: .claude/skills/storage-path-rules.md 참조
 */
// DOCUMENT_ROOT 가져오기 (CLI 환경 대비)
$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4);

return [
    // =========================================================================
    // 저장소 설정
    // =========================================================================

    // 파일 저장 경로 (절대 경로)
    // 도메인별 설정은 EditorHelper::configure()로 오버라이드
    // 기본값: public/storage (도메인 디렉토리 미포함)
    'storage_path' => $documentRoot . '/storage',

    // 웹 접근 URL (도메인 제외, 슬래시로 시작)
    'storage_url' => '/storage',

    // 임시 폴더명 (storage_path 하위)
    // 도메인별 설정 시: D{domainId}/editor/temp/
    'temp_folder' => 'editor/temp',

    // =========================================================================
    // 파일 업로드 설정
    // =========================================================================

    // 최대 파일 크기 (바이트)
    'max_file_size' => 5 * 1024 * 1024,  // 5MB

    // 허용 MIME 타입
    'allowed_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ],

    // 허용 확장자
    'allowed_extensions' => [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
    ],

    // =========================================================================
    // URL 설정
    // =========================================================================

    // URL에 도메인 포함 여부
    // true: https://example.com/storage/...
    // false: /storage/...
    'include_domain' => false,

    // =========================================================================
    // 정리 설정
    // =========================================================================

    // 임시 파일 보관 시간 (시간 단위)
    'temp_expire_hours' => 24,
];
