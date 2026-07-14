<?php
/**
 * MubloEditor 어댑터
 *
 * 표준 통일 인터페이스
 * 다른 에디터 추가 시 이 파일을 참고하여 동일한 함수 구현
 *
 * 필수 함수:
 * - Mublo_editor_html($id, $content, $options)  에디터 HTML 출력
 * - Mublo_editor_css()                          에디터 CSS 출력
 * - Mublo_editor_js()                           에디터 JS 출력
 * - Mublo_editor_sync_js($id)                   폼 제출 전 동기화 JS
 * - Mublo_editor_configure($config)             설정 주입
 *
 * 프레임워크 없이 독립 사용 시:
 * - editor_html(), editor_css() 등 전역 함수도 제공
 */

// 설정 저장용
$GLOBALS['_Mublo_editor_config'] = $GLOBALS['_Mublo_editor_config'] ?? [];
$GLOBALS['_Mublo_editor_css_loaded'] = $GLOBALS['_Mublo_editor_css_loaded'] ?? false;
$GLOBALS['_Mublo_editor_js_loaded'] = $GLOBALS['_Mublo_editor_js_loaded'] ?? false;

/**
 * 에디터 설정 로드
 */
function _Mublo_editor_get_config(): array
{
    static $config = null;

    if ($config === null) {
        $configPath = __DIR__ . '/config.php';
        $localPath = __DIR__ . '/config.local.php';

        // 기본 설정 로드
        $config = file_exists($configPath) ? require $configPath : [];

        // 로컬 설정 오버라이드
        if (file_exists($localPath)) {
            $localConfig = require $localPath;
            $config = array_merge($config, $localConfig);
        }

        // 런타임 설정 병합
        if (!empty($GLOBALS['_Mublo_editor_config'])) {
            $config = array_merge($config, $GLOBALS['_Mublo_editor_config']);
        }
    }

    return $config;
}

// =========================================================================
// 내부 함수 (프레임워크에서 호출됨)
// =========================================================================

/**
 * 에디터 설정 주입 (프레임워크에서 호출)
 */
function Mublo_editor_configure(array $config): void
{
    $GLOBALS['_Mublo_editor_config'] = array_merge(
        $GLOBALS['_Mublo_editor_config'] ?? [],
        $config
    );
}

/**
 * 에디터 CSS 출력 (head 영역)
 */
function Mublo_editor_css(): string
{
    if ($GLOBALS['_Mublo_editor_css_loaded']) {
        return '';
    }
    $GLOBALS['_Mublo_editor_css_loaded'] = true;

    $basePath = '/assets/lib/editor/mublo-editor';
    $cssPath = $basePath . '/MubloEditor.css';
    // 프레임워크 런타임에서는 asset()로 mtime 캐시버스팅 적용 (standalone 사용 시 원본 경로)
    if (function_exists('asset')) {
        $cssPath = asset($cssPath);
    }

    return '<link rel="stylesheet" href="' . $cssPath . '">' . "\n";
}

/**
 * 에디터 HTML 출력
 */
function Mublo_editor_html(string $id, string $content = '', array $options = []): string
{
    $config = _Mublo_editor_get_config();

    // 옵션 병합
    $height = $options['height'] ?? 300;
    $toolbar = $options['toolbar'] ?? 'full';
    $placeholder = $options['placeholder'] ?? '';
    $name = $options['name'] ?? $id;

    // 업로드 URL 결정 (우선순위: 옵션 > 설정 upload_url > 레거시 플러그인 경로)
    $uploadUrl = $options['uploadUrl'] ?? ($config['upload_url'] ?? null);
    if (!$uploadUrl) {
        $tempFolder = $config['temp_folder'] ?? 'temp';
        $uploadUrl = '/assets/lib/editor/mublo-editor/plugins/upload/upload.php?folder=' . $tempFolder;
    }

    // CSRF 토큰 (프레임워크가 주입)
    $csrfToken = $options['csrfToken'] ?? ($config['csrf_token'] ?? null);

    // data 속성 생성
    $dataAttrs = [
        'data-height="' . (int)$height . '"',
        'data-toolbar="' . htmlspecialchars($toolbar) . '"',
        'data-upload-url="' . htmlspecialchars($uploadUrl) . '"',
    ];

    if ($csrfToken) {
        $dataAttrs[] = 'data-upload-csrf="' . htmlspecialchars($csrfToken) . '"';
    }

    if ($placeholder) {
        $dataAttrs[] = 'data-placeholder="' . htmlspecialchars($placeholder) . '"';
    }

    // 추가 옵션 처리
    $skipOptions = ['height', 'toolbar', 'placeholder', 'name', 'uploadUrl', 'csrfToken'];
    foreach ($options as $key => $value) {
        if (in_array($key, $skipOptions)) continue;

        $dataKey = 'data-' . strtolower(preg_replace('/([A-Z])/', '-$1', $key));
        if (is_bool($value)) {
            $dataAttrs[] = $dataKey . '="' . ($value ? 'true' : 'false') . '"';
        } else {
            $dataAttrs[] = $dataKey . '="' . htmlspecialchars((string)$value) . '"';
        }
    }

    $html = '<textarea';
    $html .= ' class="mublo-editor"';
    $html .= ' id="' . htmlspecialchars($id) . '"';
    $html .= ' name="' . htmlspecialchars($name) . '"';
    $html .= ' ' . implode(' ', $dataAttrs);
    $html .= '>';
    $html .= htmlspecialchars($content);
    $html .= '</textarea>';

    return $html;
}

/**
 * 에디터 JS 출력 (body 끝)
 */
function Mublo_editor_js(): string
{
    if ($GLOBALS['_Mublo_editor_js_loaded']) {
        return '';
    }
    $GLOBALS['_Mublo_editor_js_loaded'] = true;

    $basePath = '/assets/lib/editor/mublo-editor';
    $jsPath = $basePath . '/MubloEditor.js';
    // 프레임워크 런타임에서는 asset()로 mtime 캐시버스팅 적용 (standalone 사용 시 원본 경로)
    if (function_exists('asset')) {
        $jsPath = asset($jsPath);
    }

    $html = '<script src="' . $jsPath . '"></script>' . "\n";

    // 자동 초기화 스크립트
    $html .= '<script>' . "\n";
    $html .= 'document.addEventListener("DOMContentLoaded", function() {' . "\n";
    $html .= '    document.querySelectorAll(".mublo-editor").forEach(function(el) {' . "\n";
    $html .= '        if (!el.dataset.MubloEditorInitialized) {' . "\n";
    $html .= '            MubloEditor.create(el);' . "\n";
    $html .= '            el.dataset.MubloEditorInitialized = "true";' . "\n";
    $html .= '        }' . "\n";
    $html .= '    });' . "\n";
    $html .= '});' . "\n";
    $html .= '</script>' . "\n";

    return $html;
}

/**
 * 폼 제출 전 에디터 동기화 JS
 */
function Mublo_editor_sync_js(string $id): string
{
    $encodedId = json_encode($id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return 'if (typeof MubloEditor !== "undefined") { MubloEditor.get(' . $encodedId . ')?.sync(); }';
}

/**
 * 업로드 URL 생성
 */
function Mublo_editor_upload_url(?string $folder = null): string
{
    $config = _Mublo_editor_get_config();
    $folder = $folder ?? ($config['temp_folder'] ?? 'temp');

    return '/assets/lib/editor/mublo-editor/plugins/upload/upload.php?folder=' . urlencode($folder);
}

// =========================================================================
// 전역 함수 (독립 사용 시) - 프레임워크 없이 사용할 때만 정의
// =========================================================================

if (!function_exists('editor_configure')) {
    function editor_configure(array $config): void {
        Mublo_editor_configure($config);
    }
}

if (!function_exists('editor_css')) {
    function editor_css(): string {
        return Mublo_editor_css();
    }
}

if (!function_exists('editor_html')) {
    function editor_html(string $id, string $content = '', array $options = []): string {
        return Mublo_editor_html($id, $content, $options);
    }
}

if (!function_exists('editor_js')) {
    function editor_js(): string {
        return Mublo_editor_js();
    }
}

if (!function_exists('editor_sync_js')) {
    function editor_sync_js(string $id): string {
        return Mublo_editor_sync_js($id);
    }
}

if (!function_exists('editor_upload_url')) {
    function editor_upload_url(?string $folder = null): string {
        return Mublo_editor_upload_url($folder);
    }
}
