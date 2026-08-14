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
    // 플러그인
    // =========================================================================

    // 코어 다음에 로드할 공식 플러그인 (툴바 항목 이름과 같다).
    // 여기서 켠 항목은 toolbar='full' 인 에디터의 툴바 끝에 자동으로 붙고,
    // 다른 프리셋에서는 toolbarItems 에 이름을 적어야 나타난다.
    //
    // - layout     이미지+텍스트 레이아웃 10종
    // - sticker    이모티콘/스티커 (기본 팩: 머블로봇 · Twemoji)
    // - fileimport 문서 가져오기 (TXT·MD·HTML·CSV. DOCX·XLSX·PDF 는 서버
    //              변환 핸들러를 붙였을 때만 파일 선택 목록에 나타난다)
    // - export     문서 내보내기 (Word. PDF 는 페이지에 html2pdf.js 가 있을 때만)
    'plugins' => ['layout', 'sticker', 'fileimport', 'export'],

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
