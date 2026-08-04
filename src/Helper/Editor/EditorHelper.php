<?php
declare(strict_types=1);
namespace Mublo\Helper\Editor;

use Mublo\Helper\Security\HtmlSanitizer;

/**
 * EditorHelper
 *
 * 프레임워크에서 에디터를 사용하기 위한 헬퍼 클래스
 * 표준 에디터 인터페이스 제공
 *
 * 사용법:
 * - EditorHelper::setEditor('Mublo-editor')  에디터 선택
 * - EditorHelper::configure([...])          설정 주입
 * - EditorHelper::html($id, $content)       에디터 HTML 출력
 * - EditorHelper::css()                     에디터 CSS 출력
 * - EditorHelper::js()                      에디터 JS 출력
 */
class EditorHelper
{
    /**
     * 현재 사용 중인 에디터
     */
    private static string $editor = 'mublo-editor';

    /**
     * 에디터 설정
     */
    private static array $config = [];

    /**
     * 에디터 라이브러리 로드 여부
     */
    private static bool $libLoaded = false;

    /**
     * 에디터 기본 경로
     */
    private static string $editorBasePath = '';

    /**
     * 사용할 에디터 설정
     *
     * @param string $editor 에디터명 (Mublo-editor, ckeditor, etc.)
     */
    public static function setEditor(string $editor): void
    {
        $editor = self::normalizeEditorName($editor);

        // 에디터가 변경되면 라이브러리 다시 로드
        if (self::$editor !== $editor) {
            self::$libLoaded = false;
        }
        self::$editor = $editor;
    }

    /**
     * 현재 에디터 가져오기
     */
    public static function getEditor(): string
    {
        return self::$editor;
    }

    /**
     * 에디터 설정 주입
     *
     * @param array $config 설정 배열 [storage_path, storage_url, ...]
     */
    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);

        // 이미 로드된 경우 에디터별 configure 함수 호출
        if (self::$libLoaded) {
            $func = self::functionName('configure');
            if (function_exists($func)) {
                $func($config);
            }
        }
    }

    /**
     * 도메인별 에디터 저장 경로 설정
     *
     * 에디터 임시 업로드 경로를 도메인별로 분리합니다.
     * storage_path: MUBLO_PUBLIC_STORAGE_PATH/D{domainId}
     * storage_url:  /storage/D{domainId}
     * temp_folder:  editor/temp
     *
     * @param int $domainId 도메인 ID
     * 공개 저장소 기준 저장 경로 규칙:
     * 도메인별 업로드는 public/storage/D{domainId} 하위에 분리한다.
     */
    public static function configureForDomain(int $domainId): void
    {
        $storagePath = defined('MUBLO_PUBLIC_STORAGE_PATH')
            ? MUBLO_PUBLIC_STORAGE_PATH
            : 'public/storage';

        self::configure([
            'storage_path' => $storagePath . '/D' . $domainId,
            'storage_url' => '/storage/D' . $domainId,
            'temp_folder' => 'editor/temp',
        ]);
    }

    /**
     * 에디터 라이브러리 로드
     */
    private static function loadLib(): void
    {
        if (self::$libLoaded) {
            return;
        }

        // 에디터 경로 결정
        $basePath = defined('MUBLO_PUBLIC_PATH')
            ? MUBLO_PUBLIC_PATH . '/assets/lib/editor'
            : 'public/assets/lib/editor';

        self::$editorBasePath = $basePath . '/' . strtolower(self::$editor);

        $libPath = self::$editorBasePath . '/editor.lib.php';

        if (!file_exists($libPath)) {
            // editor.lib.php가 없으면 textarea 폴백
            self::$editor = 'textarea';
            self::$libLoaded = true;
            return;
        }

        require_once $libPath;

        // 설정이 있으면 주입
        if (!empty(self::$config)) {
            $func = self::functionName('configure');
            if (function_exists($func)) {
                $func(self::$config);
            }
        }

        self::$libLoaded = true;
    }

    /**
     * 에디터 HTML 출력
     *
     * @param string $id 에디터 ID
     * @param string $content 초기 콘텐츠
     * @param array $options 옵션 [height, toolbar, placeholder, ...]
     * @return string 에디터 HTML
     */
    public static function html(string $id, string $content = '', array $options = []): string
    {
        // textarea 폴백
        if (self::$editor === 'textarea') {
            return self::textareaFallback($id, $content, $options);
        }

        self::loadLib();

        // 에디터별 내부 함수 호출 (Mublo_editor_html, ckeditor_html 등)
        $func = self::functionName('html');
        if (function_exists($func)) {
            return $func($id, $content, $options);
        }

        return self::textareaFallback($id, $content, $options);
    }

    /**
     * 에디터 CSS 출력
     *
     * @return string CSS 링크 태그
     */
    public static function css(): string
    {
        if (self::$editor === 'textarea') {
            return '';
        }

        self::loadLib();

        // 에디터별 내부 함수 호출
        $func = self::functionName('css');
        if (function_exists($func)) {
            return $func();
        }

        return '';
    }

    /**
     * 에디터 JS 출력
     *
     * @return string JS 스크립트 태그
     */
    public static function js(): string
    {
        if (self::$editor === 'textarea') {
            return '';
        }

        self::loadLib();

        // 에디터별 내부 함수 호출
        $func = self::functionName('js');
        if (function_exists($func)) {
            return $func();
        }

        return '';
    }

    /**
     * 폼 제출 전 에디터 동기화 JS
     *
     * @param string $id 에디터 ID
     * @return string JS 코드
     */
    public static function syncJs(string $id): string
    {
        if (self::$editor === 'textarea') {
            return '';
        }

        self::loadLib();

        // 에디터별 내부 함수 호출
        $func = self::functionName('sync_js');
        if (function_exists($func)) {
            return $func($id);
        }

        return '';
    }

    /**
     * 업로드 URL 가져오기
     *
     * @param string|null $folder 폴더명
     * @return string 업로드 URL
     */
    public static function uploadUrl(?string $folder = null): string
    {
        self::loadLib();

        // 에디터별 내부 함수 호출
        $func = self::functionName('upload_url');
        if (function_exists($func)) {
            return $func($folder);
        }

        // 프레임워크가 주입한 업로드 라우트 우선 (레거시 standalone 핸들러는 기본 차단)
        if (!empty(self::$config['upload_url'])) {
            return (string) self::$config['upload_url'];
        }

        // 기본 업로드 URL
        $folder = $folder ?? 'temp';
        return '/assets/lib/editor/' . self::$editor . '/plugins/upload/upload.php?folder=' . urlencode($folder);
    }

    /**
     * 업로드 CSRF 토큰 가져오기
     *
     * 업로드 라우트는 CsrfMiddleware 를 경유하므로 토큰 없이는 403 이다.
     * editor_html() 로 렌더하면 data-upload-csrf 가 자동으로 붙지만,
     * textarea 를 직접 조립하는 뷰는 uploadUrl() 과 짝으로 이 값을 넣어야 한다.
     *
     * @return string 토큰 (주입 전이면 빈 문자열)
     */
    public static function uploadCsrfToken(): string
    {
        self::loadLib();

        $func = self::functionName('upload_csrf_token');
        if (function_exists($func)) {
            return $func();
        }

        return (string) (self::$config['csrf_token'] ?? '');
    }

    /**
     * textarea 폴백
     *
     * 프론트 프레임에는 Bootstrap CSS가 없으므로 form-control 클래스만으로는
     * 폭이 잡히지 않는다(브라우저 기본 cols 기준의 좁은 상자로 보인다).
     * 어느 화면에 놓이든 컨테이너를 채우도록 최소 스타일을 인라인으로 갖춘다.
     */
    private static function textareaFallback(string $id, string $content, array $options): string
    {
        $height = $options['height'] ?? 300;
        $placeholder = $options['placeholder'] ?? '';
        $name = $options['name'] ?? $id;

        $style = 'width:100%;box-sizing:border-box;height:' . (int)$height . 'px;';

        $html = '<textarea';
        $html .= ' id="' . htmlspecialchars($id) . '"';
        $html .= ' name="' . htmlspecialchars($name) . '"';
        $html .= ' class="form-control mublo-editor-fallback"';
        $html .= ' style="' . $style . '"';
        if ($placeholder) {
            $html .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
        }
        $html .= '>';
        $html .= htmlspecialchars($content);
        $html .= '</textarea>';

        return $html;
    }

    /**
     * HTML 콘텐츠에서 첫 번째 이미지 경로 추출
     *
     * @param string $html 에디터 HTML 콘텐츠
     * @return string|null 이미지 경로 (없으면 null)
     */
    public static function extractFirstImage(string $html): ?string
    {
        if (empty($html)) {
            return null;
        }

        // <img ... src="..." ...> 에서 src 값 추출
        if (preg_match('/<img\s[^>]*src=["\']([^"\']+)["\']/', $html, $matches)) {
            $src = $matches[1];

            // 이미지 확장자 확인
            $ext = strtolower(pathinfo(parse_url($src, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'], true)) {
                return $src;
            }
        }

        return null;
    }

    /**
     * 에디터 이미지 처리 (임시 → 최종 경로)
     *
     * 이동된 파일 목록을 $moved out-param으로 받을 수 있어, 후속 DB 저장이
     * 실패할 경우 rollbackImages()로 되돌릴 수 있다.
     *
     * @param string $html 에디터 HTML
     * @param string $targetFolder 대상 폴더 (예: 'board/notice/2026-01-31')
     * @param string|null $targetBasePath 커스텀 대상 기본 경로 (null이면 editor 기본 경로)
     * @param string|null $targetBaseUrl 커스텀 대상 기본 URL (null이면 editor 기본 URL)
     * @param array|null &$moved (out) 이동된 파일 목록 [['source' => ..., 'dest' => ...], ...]
     * @return string 처리된 HTML
     */
    public static function processImages(string $html, string $targetFolder, ?string $targetBasePath = null, ?string $targetBaseUrl = null, ?array &$moved = null): string
    {
        if ($moved === null) {
            $moved = [];
        }

        if (empty($html)) {
            return $html;
        }

        // 에디터 라이브러리 로드 (설정 포함)
        self::loadLib();

        // MubloEditorUploader 클래스 로드
        $uploaderPath = self::getUploaderPath();

        if ($uploaderPath && file_exists($uploaderPath)) {
            require_once $uploaderPath;

            if (class_exists('MubloEditorUploader')) {
                $html = \MubloEditorUploader::processHtml($html, $targetFolder, $targetBasePath, $targetBaseUrl, $moved);
                return self::sanitizeHtml($html);
            }
        }

        return self::sanitizeHtml($html);
    }

    /**
     * 에디터 HTML 저장용 정화
     */
    public static function sanitizeHtml(string $html): string
    {
        return HtmlSanitizer::sanitizeEditorContent($html);
    }

    /**
     * processImages()로 이동된 파일을 원래 임시 위치로 되돌림 (롤백)
     *
     * DB 저장 실패 등 후속 처리에서 롤백이 필요할 때 사용한다.
     *
     * @param array $moved processImages의 out-param으로 받은 이동 내역
     */
    public static function rollbackImages(array $moved): void
    {
        if (empty($moved)) {
            return;
        }

        $uploaderPath = self::getUploaderPath();
        if ($uploaderPath && file_exists($uploaderPath)) {
            require_once $uploaderPath;

            if (class_exists('MubloEditorUploader')
                && method_exists('MubloEditorUploader', 'rollbackMoved')
            ) {
                \MubloEditorUploader::rollbackMoved($moved);
            }
        }
    }

    /**
     * 업로더 경로 가져오기
     */
    private static function getUploaderPath(): ?string
    {
        // 1. MUBLO_PUBLIC_PATH 상수 사용
        if (defined('MUBLO_PUBLIC_PATH')) {
            return MUBLO_PUBLIC_PATH . '/assets/lib/editor/' . self::$editor . '/plugins/upload/upload.php';
        }

        // 2. base_path 함수 사용
        if (function_exists('base_path')) {
            return base_path('public/assets/lib/editor/' . self::$editor . '/plugins/upload/upload.php');
        }

        // 3. DOCUMENT_ROOT 사용
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            return $_SERVER['DOCUMENT_ROOT'] . '/assets/lib/editor/' . self::$editor . '/plugins/upload/upload.php';
        }

        // 4. 현재 파일 기준 상대 경로
        return dirname(__DIR__, 3) . '/public/assets/lib/editor/' . self::$editor . '/plugins/upload/upload.php';
    }

    private static function normalizeEditorName(string $editor): string
    {
        $editor = trim($editor);
        if ($editor === '') {
            return 'textarea';
        }

        $lower = strtolower($editor);
        return match ($lower) {
            'mublo-editor', 'mublo_editor', 'mubloeditor' => 'mublo-editor',
            default => $lower,
        };
    }

    private static function functionName(string $suffix): string
    {
        if (self::$editor === 'mublo-editor') {
            return 'Mublo_editor_' . $suffix;
        }

        return str_replace('-', '_', self::$editor) . '_editor_' . $suffix;
    }
}
