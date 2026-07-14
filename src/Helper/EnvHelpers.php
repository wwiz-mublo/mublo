<?php

/**
 * Framework Helper Functions
 *
 * 전역 헬퍼 함수 정의
 */

use Mublo\Core\Env\Env;

if (!function_exists('env')) {
    /**
     * 환경 변수 조회 헬퍼
     *
     * @param string $key 환경 변수 키
     * @param mixed $default 기본값
     * @return mixed 환경 변수 값 또는 기본값
     *
     * @example
     *   env('DB_HOST', 'localhost')
     *   env('APP_DEBUG', false)
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('base_path')) {
    /**
     * 프로젝트 루트 경로 반환
     *
     * @param string $path 상대 경로 (선택)
     * @return string 전체 경로
     */
    function base_path(string $path = ''): string
    {
        static $basePath = null;

        if ($basePath === null) {
            // src/Helper/EnvHelpers.php -> 프로젝트 루트 (2단계 상위)
            $basePath = dirname(__DIR__, 2);
        }

        return $path ? $basePath . DIRECTORY_SEPARATOR . ltrim($path, '/\\') : $basePath;
    }
}

if (!function_exists('config_path')) {
    /**
     * config 디렉토리 경로 반환
     *
     * @param string $path 상대 경로 (선택)
     * @return string 전체 경로
     */
    function config_path(string $path = ''): string
    {
        return base_path('config' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('storage_path')) {
    /**
     * storage 디렉토리 경로 반환
     *
     * @param string $path 상대 경로 (선택)
     * @return string 전체 경로
     */
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('asset')) {
    /**
     * 정적 자산 URL + 캐시버스팅 버전(?<filemtime>) 반환.
     *
     * AssetManager(addCss/addJs)와 동일한 버전 로직을 재사용하므로
     * /assets/... (public 루트) 와 /serve/... (가상 라우트) 모두 처리된다.
     * 프레임 스킨 Head/Footer 처럼 AssetManager를 못 거치고 직접 태그로
     * 로딩하는 자산에 인라인으로 쓴다.
     *
     * @param string $path 자산 경로
     * @return string 버전 쿼리가 붙은 URL (파일을 못 찾으면 원본 경로)
     *
     * @example
     *   <script src="<?= asset('/assets/js/MubloCore.js') ?>"></script>
     */
    function asset(string $path): string
    {
        return \Mublo\Core\Rendering\AssetManager::versionedPath($path);
    }
}

if (!function_exists('is_editor_preview')) {
    /**
     * 블록 에디터 미리보기 요청인지 (블록 에디터 설계 5.3).
     *
     * 에디터의 iframe 미리보기는 ?_editor=1 을 달고 프론트를 연다. 이 플래그는
     * 렌더링을 바꾸지 않고 부작용(팝업·AOS·통계)을 끄는 신호다. 로그인 세션이
     * 있어야 존중한다 — 방문자가 파라미터를 붙여도 아무 일도 일어나지 않는다.
     */
    function is_editor_preview(): bool
    {
        return ($_GET['_editor'] ?? null) === '1' && !empty($_SESSION['auth_user']);
    }
}

if (!function_exists('e')) {
    /**
     * HTML 출력 이스케이프 헬퍼 (XSS 방지)
     *
     * 스킨/뷰에서 변수를 HTML 로 출력할 때는 항상 이 함수를 거친다.
     * null 은 빈 문자열로 처리하므로 `e($row['title'] ?? null)` 처럼 안심하고 쓸 수 있다.
     *
     * @param mixed $value 출력할 값 (null·숫자·문자열·Stringable)
     * @return string 이스케이프된 문자열
     *
     * @example
     *   <h1><?= e($title) ?></h1>
     *   <input value="<?= e($keyword) ?>">
     */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        // 배열 등 문자열이 될 수 없는 값이 화면에 'Array' 로 조용히 찍히지 않도록
        // 경고를 남기고 빈 문자열로 처리한다 (스킨 실수를 로그로 드러내되 페이지는 살린다)
        if (!\is_scalar($value) && !($value instanceof \Stringable)) {
            trigger_error('e(): 문자열로 변환할 수 없는 값입니다: ' . get_debug_type($value), E_USER_WARNING);
            return '';
        }

        return \Mublo\Helper\Security\HtmlSanitizer::escape((string) $value);
    }
}

// =========================================================================
// 에디터 헬퍼 함수
// =========================================================================

if (!function_exists('editor_html')) {
    /**
     * 에디터 HTML 출력
     *
     * @param string $id 에디터 ID
     * @param string $content 초기 콘텐츠
     * @param array $options 옵션 [height, toolbar, placeholder, ...]
     * @return string 에디터 HTML
     */
    function editor_html(string $id, string $content = '', array $options = []): string
    {
        return \Mublo\Helper\Editor\EditorHelper::html($id, $content, $options);
    }
}

if (!function_exists('editor_css')) {
    /**
     * 에디터 CSS 출력 (head 영역)
     *
     * @return string CSS 링크 태그
     */
    function editor_css(): string
    {
        return \Mublo\Helper\Editor\EditorHelper::css();
    }
}

if (!function_exists('editor_js')) {
    /**
     * 에디터 JS 출력 (body 끝)
     *
     * @return string JS 스크립트 태그
     */
    function editor_js(): string
    {
        return \Mublo\Helper\Editor\EditorHelper::js();
    }
}

if (!function_exists('editor_sync_js')) {
    /**
     * 폼 제출 전 에디터 동기화 JS
     *
     * @param string $id 에디터 ID
     * @return string JS 코드
     */
    function editor_sync_js(string $id): string
    {
        return \Mublo\Helper\Editor\EditorHelper::syncJs($id);
    }
}
