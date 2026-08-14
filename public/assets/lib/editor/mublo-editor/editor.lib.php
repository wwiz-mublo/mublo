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
 * 공식 플러그인 목록 (툴바 항목 이름 → 코어 다음에 로드할 스크립트).
 *
 * 키는 툴바 항목 이름이다 — 뷰가 toolbarItems 에 적는 이름과 같아야
 * 설정에서 켠 것과 화면에 뜨는 버튼이 어긋나지 않는다.
 *
 * @return array<string, list<string>>
 */
function _Mublo_editor_plugin_registry(): array
{
    return [
        'layout'     => ['/plugins/MubloEditorLayouts.js'],
        // 뷰어 + 기본 팩 등록 순서로 로드해야 팩이 붙는다
        'sticker'    => ['/plugins/MubloEditorStickers.js', '/plugins/stickers/packs.js'],
        'fileimport' => ['/plugins/MubloEditorFileImport.js'],
        'export'     => ['/plugins/MubloEditorExport.js'],
    ];
}

/**
 * 설정에서 켠 플러그인만, 레지스트리 순서로 반환 (모르는 이름은 버린다)
 *
 * @return list<string>
 */
function _Mublo_editor_enabled_plugins(array $config): array
{
    $requested = $config['plugins'] ?? [];
    if (!is_array($requested)) {
        return [];
    }

    return array_values(array_intersect(
        array_keys(_Mublo_editor_plugin_registry()),
        array_map('strval', $requested)
    ));
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

    $config = _Mublo_editor_get_config();
    $basePath = '/assets/lib/editor/mublo-editor';
    // 프레임워크 런타임에서는 asset()로 mtime 캐시버스팅 적용 (standalone 사용 시 원본 경로)
    $src = fn (string $path): string => function_exists('asset') ? asset($basePath . $path) : $basePath . $path;

    $html = '<script src="' . $src('/MubloEditor.js') . '"></script>' . "\n";

    // 플러그인은 코어가 만든 전역에 자기 툴바 항목을 등록하므로 코어 다음에 온다
    $enabled = _Mublo_editor_enabled_plugins($config);
    $registry = _Mublo_editor_plugin_registry();
    foreach ($enabled as $plugin) {
        foreach ($registry[$plugin] as $path) {
            $html .= '<script src="' . $src($path) . '"></script>' . "\n";
        }
    }

    // 자동 초기화 스크립트
    $html .= '<script>' . "\n";
    $html .= '(function() {' . "\n";
    // 체크리스트·목차는 코어 툴바 항목이지만 full 프리셋에는 들어 있지 않다.
    // 여기서 붙이지 않으면 슬래시 커맨드로만 닿을 수 있어 버튼이 없는 기능이 된다.
    $extraItems = array_merge(['checklist', 'toc'], $enabled);

    $html .= '    var extraItems = ' . json_encode($extraItems, JSON_UNESCAPED_SLASHES) . ';' . "\n";
    // full 은 "쓸 수 있는 모든 도구" 프리셋이므로 켜 둔 플러그인 버튼도 함께 붙인다.
    // minimal/compact 는 좁은 화면용이라 늘리지 않고, 항목을 직접 지정한 에디터도 건드리지 않는다.
    //
    // 코어가 DOMContentLoaded 에서 스스로 초기화하고 그 리스너가 먼저 등록돼 있으므로,
    // 항목은 파싱 시점(이 스크립트는 본문 끝)에 미리 얹어야 첫 렌더에 반영된다.
    $html .= '    document.querySelectorAll(".mublo-editor").forEach(function(el) {' . "\n";
    $html .= '        if (el.dataset.toolbarItems || (el.dataset.toolbar || "full") !== "full") return;' . "\n";
    $html .= '        var preset = MubloEditor.TOOLBAR_PRESETS.full || [];' . "\n";
    // 코어 프리셋이 나중에 같은 항목을 품어도 중복되지 않게 걸러 낸다
    $html .= '        var add = extraItems.filter(function(n) { return preset.indexOf(n) === -1; });' . "\n";
    $html .= '        if (preset.length && add.length) el.dataset.toolbarItems = preset.concat("separator", add).join(",");' . "\n";
    $html .= '    });' . "\n";
    // 코어 autoInit 이 이미 만든 에디터는 create() 가 기존 인스턴스를 돌려준다.
    // 이 루프는 코어가 놓친 요소(늦게 붙은 폼 등)를 위한 보강이다.
    $html .= '    document.addEventListener("DOMContentLoaded", function() {' . "\n";
    $html .= '        document.querySelectorAll(".mublo-editor").forEach(function(el) {' . "\n";
    $html .= '            if (!el.dataset.MubloEditorInitialized) {' . "\n";
    $html .= '                MubloEditor.create(el);' . "\n";
    $html .= '                el.dataset.MubloEditorInitialized = "true";' . "\n";
    $html .= '            }' . "\n";
    $html .= '        });' . "\n";
    $html .= '    });' . "\n";
    $html .= '})();' . "\n";
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

    // 프레임워크가 주입한 업로드 라우트를 우선한다(Mublo_editor_html 과 동일한 우선순위).
    // 레거시 standalone 핸들러는 무인증 업로드 방지를 위해 기본 차단(403 STANDALONE_DISABLED)이므로,
    // 이 함수가 그 경로를 반환하면 textarea 를 직접 조립하는 뷰의 업로드가 통째로 실패한다.
    // upload_url 라우트는 도메인별 temp 경로를 서버에서 결정하므로 $folder 는 의미가 없다.
    if (!empty($config['upload_url'])) {
        return (string) $config['upload_url'];
    }

    $folder = $folder ?? ($config['temp_folder'] ?? 'temp');

    return '/assets/lib/editor/mublo-editor/plugins/upload/upload.php?folder=' . urlencode($folder);
}

/**
 * 업로드 CSRF 토큰
 *
 * 프레임워크 업로드 라우트는 CsrfMiddleware 를 경유하므로 토큰 없이는 403 이다.
 * Mublo_editor_html 은 data-upload-csrf 를 자동으로 붙이지만,
 * textarea 를 직접 조립하는 뷰는 이 값을 가져다 써야 한다.
 */
function Mublo_editor_upload_csrf_token(): string
{
    $config = _Mublo_editor_get_config();

    return (string) ($config['csrf_token'] ?? '');
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
