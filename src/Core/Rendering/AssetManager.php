<?php
declare(strict_types=1);
namespace Mublo\Core\Rendering;

/**
 * AssetManager — CSS/JS 에셋 수집 + 렌더링 (Slot CSS/JS 시스템)
 *
 * 블록 스킨/Plugin/View가 등록한 CSS/JS를 수집하고, FrontViewRenderer가
 * 플레이스홀더 치환으로 출력한다.
 *
 * ── Slot CSS/JS ──
 * addCss/addJs 의 2번째 인자로 슬롯 이름을 주면 템플릿의 <!-- MUBLO_CSS_{slot} -->
 * (또는 MUBLO_JS_{slot}) 자리에 주입된다 → 로드 위치를 템플릿이 결정.
 * 슬롯 마커가 없으면 기본 대역(<!-- MUBLO_CSS --> / <!-- MUBLO_JS -->)으로 폴백(유실 방지).
 * 인자 없이 쓰면 기본 대역(CSS=스킨 뒤, JS=body 끝)에 등록순.
 *
 * 사용법:
 * - 기본:   $this->assets->addCss('/path/to/style.css')
 * - 슬롯:   $this->assets->addCss('/path/to/mublo-pagination.css', 'component')
 */
class AssetManager
{
    /** 슬롯 없는 CSS = 기본 대역(<!-- MUBLO_CSS -->, 스킨 뒤)에 등록순 출력. */
    private array $css = [];
    /** 슬롯별 CSS [slot => [path,...]] — <!-- MUBLO_CSS_{slot} --> 자리에 등록순 주입.
     *  템플릿이 각 슬롯을 원하는 위치에 배치한다(예: 컴포넌트 슬롯을 스킨 CSS 앞에). */
    private array $slotCss = [];
    /** 슬롯 없는 JS = 기본 대역(<!-- MUBLO_JS -->, body 끝)에 등록순 출력. */
    private array $js = [];
    /** 슬롯별 JS [slot => [path,...]] — <!-- MUBLO_JS_{slot} --> 자리에 등록순 주입 (CSS 슬롯과 대칭). */
    private array $slotJs = [];

    /**
     * 캡처 창 스택 — beginCapture()~endCapture() 사이 addCss/addJs 호출 경로를
     * dedup 여부와 무관하게 기록한다. 블록 행 캐시가 "이 행이 필요로 하는 CSS 전체"를
     * 저장하기 위해 사용(공유 CSS를 먼저 등록한 다른 행에 뺏겨 유실되는 문제 방지).
     * 스택 구조라 중첩 렌더 시 바깥 프레임도 안쪽에서 등록한 경로를 함께 기록한다.
     * @var array<int,array<string>>
     */
    private array $captureCssFrames = [];
    /** @var array<int,array<string>> 캡처 창 JS(위 CSS와 대칭). */
    private array $captureJsFrames = [];

    /** 캡처 창 시작 — 이후 addCss/addJs 경로가 dedup 무관하게 기록된다. endCapture()와 짝. */
    public function beginCapture(): void
    {
        $this->captureCssFrames[] = [];
        $this->captureJsFrames[] = [];
    }

    /**
     * 캡처 창 종료 — 이 창에서 등록 시도된 CSS/JS 경로(중복 제거)를 반환한다.
     * @return array{css: string[], js: string[]}
     */
    public function endCapture(): array
    {
        $css = array_pop($this->captureCssFrames) ?? [];
        $js = array_pop($this->captureJsFrames) ?? [];
        return [
            'css' => array_values(array_unique($css)),
            'js' => array_values(array_unique($js)),
        ];
    }

    /**
     * CSS 등록.
     *
     * @param string      $path 에셋 경로
     * @param string|null $slot 슬롯 이름. 지정하면 렌더 시 <!-- MUBLO_CSS_{slot} --> 자리에 주입된다
     *                          (템플릿이 그 슬롯을 원하는 위치에 배치 → 로드 시점을 정함).
     *                          해당 슬롯 마커가 없으면 기본 <!-- MUBLO_CSS --> 대역으로 폴백(유실 방지).
     *                          null 이면 기본 대역(스킨 뒤)에 등록순.
     *
     * 예) addCss('/assets/css/components/mublo-pagination.css', 'component')  // → <!-- MUBLO_CSS_component -->
     */
    public function addCss(string $path, ?string $slot = null): void
    {
        // 캡처 창(있으면): dedup 여부와 무관하게 "이 렌더가 필요로 한" 경로 기록
        foreach ($this->captureCssFrames as &$frame) {
            $frame[] = $path;
        }
        unset($frame);

        // 중복 등록 방지 (모든 버킷)
        if (in_array($path, $this->css, true)) {
            return;
        }
        foreach ($this->slotCss as $paths) {
            if (in_array($path, $paths, true)) {
                return;
            }
        }

        if ($slot === null) {
            $this->css[] = $path;
        } else {
            $this->slotCss[$slot][] = $path;
        }
    }

    /**
     * JS 등록. $slot 지정 시 렌더 시 <!-- MUBLO_JS_{slot} --> 자리에 주입(마커 없으면 기본 대역 폴백).
     * null 이면 기본 대역(<!-- MUBLO_JS -->, body 끝)에 등록순. (addCss 슬롯과 대칭)
     */
    public function addJs(string $path, ?string $slot = null): void
    {
        // 캡처 창(있으면): dedup 무관하게 경로 기록 (addCss와 대칭)
        foreach ($this->captureJsFrames as &$frame) {
            $frame[] = $path;
        }
        unset($frame);

        if (in_array($path, $this->js, true)) {
            return;
        }
        foreach ($this->slotJs as $paths) {
            if (in_array($path, $paths, true)) {
                return;
            }
        }

        if ($slot === null) {
            $this->js[] = $path;
        } else {
            $this->slotJs[$slot][] = $path;
        }
    }

    /** 슬롯별 CSS 그룹 [slot => [paths]] — 렌더러가 <!-- MUBLO_CSS_{slot} --> 치환에 사용 */
    public function getSlotCssGroups(): array
    {
        return $this->slotCss;
    }

    /**
     * 전체 CSS 경로 (슬롯 → 슬롯없음).
     * 블록 에디터 diff·admin 단일밴드 등 "전부 필요한" 곳에서 사용.
     */
    public function getCssPaths(): array
    {
        $slotted = [];
        foreach ($this->slotCss as $paths) {
            foreach ($paths as $path) {
                $slotted[] = $path;
            }
        }
        return array_merge($slotted, $this->css);
    }

    /** 슬롯별 JS 그룹 [slot => paths] — 렌더러가 <!-- MUBLO_JS_{slot} --> 치환에 사용 */
    public function getSlotJsGroups(): array
    {
        return $this->slotJs;
    }

    /** 전체 JS 경로 (슬롯 → 슬롯없음). admin 단일밴드 등 "전부 필요한" 곳. */
    public function getJsPaths(): array
    {
        $slotted = [];
        foreach ($this->slotJs as $paths) {
            foreach ($paths as $path) {
                $slotted[] = $path;
            }
        }
        return array_merge($slotted, $this->js);
    }

    /** CSS 경로 배열 → <link> 문자열 */
    private function renderLinks(array $paths): string
    {
        $html = '';
        foreach ($paths as $path) {
            $url = htmlspecialchars(self::versionedPath($path), ENT_QUOTES, 'UTF-8');
            $html .= '<link rel="stylesheet" href="' . $url . '">' . "\n";
        }
        return $html;
    }

    /** CSS 경로 배열 → <link> (렌더러가 슬롯 주입에 사용) */
    public function renderCssLinks(array $paths): string
    {
        return $this->renderLinks($paths);
    }

    /** 전체 CSS — admin 등 단일 출력 placeholder용 */
    public function renderCss(): string
    {
        return $this->renderLinks($this->getCssPaths());
    }

    /** 기본 대역(<!-- MUBLO_CSS -->): 슬롯 없는 CSS + 폴백(마커 없는 슬롯 CSS). 스킨 뒤. */
    public function renderMainCss(array $leftover = []): string
    {
        return $this->renderLinks(array_merge($leftover, $this->css));
    }

    /** JS 경로 배열 → <script> (렌더러가 슬롯 주입에 사용) */
    public function renderJsLinks(array $paths): string
    {
        $html = '';
        foreach ($paths as $path) {
            $url = htmlspecialchars(self::versionedPath($path), ENT_QUOTES, 'UTF-8');
            $html .= '<script src="' . $url . '"></script>' . "\n";
        }
        return $html;
    }

    /** 전체 JS — admin 등 단일 출력용 */
    public function renderJs(): string
    {
        return $this->renderJsLinks($this->getJsPaths());
    }

    /** 기본 대역(<!-- MUBLO_JS -->): 슬롯 없는 JS + 폴백(마커 없는 슬롯). body 끝. */
    public function renderMainJs(array $leftover = []): string
    {
        return $this->renderJsLinks(array_merge($leftover, $this->js));
    }

    /**
     * 자산 경로에 캐시버스팅 버전(?<filemtime>)을 붙여 반환.
     * 인스턴스 없이도 쓰도록 static — 전역 asset() 헬퍼가 이 메서드를 재사용한다.
     */
    public static function versionedPath(string $path): string
    {
        $file = self::resolveAssetFile($path);
        if ($file !== null && is_file($file)) {
            return $path . '?' . filemtime($file);
        }
        return $path;
    }

    /**
     * URL 경로를 디스크 실제 경로로 매핑.
     * /serve/... 는 ServeController가 처리하는 가상 라우트라 DOCUMENT_ROOT에 파일이 없음 →
     * cache-buster가 안 붙어 max-age(24h) 동안 클라이언트가 옛 버전을 들고 있는 문제 해결을 위해 매핑 추가.
     * 매핑 규칙은 src/Core/App/Router.php의 /serve/ 라우트와 1:1.
     */
    private static function resolveAssetFile(string $path): ?string
    {
        $name = '[a-zA-Z0-9_-]+';

        if (defined('MUBLO_PACKAGE_PATH')
            && preg_match("#^/serve/package/($name)/(.+)$#", $path, $m)) {
            return MUBLO_PACKAGE_PATH . '/' . $m[1] . '/' . $m[2];
        }
        if (defined('MUBLO_PLUGIN_PATH')
            && preg_match("#^/serve/plugin/($name)/(.+)$#", $path, $m)) {
            return MUBLO_PLUGIN_PATH . '/' . $m[1] . '/' . $m[2];
        }
        if (defined('MUBLO_VIEW_PATH')) {
            if (preg_match("#^/serve/front/view/($name)/($name)/(.+)$#", $path, $m)) {
                return MUBLO_VIEW_PATH . '/Front/' . ucfirst($m[1]) . '/' . $m[2] . '/_assets/' . $m[3];
            }
            if (preg_match("#^/serve/front/($name)/(.+)$#", $path, $m)) {
                return MUBLO_VIEW_PATH . '/Front/frame/' . $m[1] . '/_assets/' . $m[2];
            }
            if (preg_match("#^/serve/admin/($name)/(.+)$#", $path, $m)) {
                return MUBLO_VIEW_PATH . '/Admin/frame/' . $m[1] . '/_assets/' . $m[2];
            }
            if (preg_match("#^/serve/block/($name)/($name)/(.+)$#", $path, $m)) {
                return MUBLO_VIEW_PATH . '/Block/' . $m[1] . '/' . $m[2] . '/' . $m[3];
            }
            if (preg_match('#^/serve/views/admin/(.+)$#', $path, $m)) {
                return MUBLO_VIEW_PATH . '/Admin/' . $m[1];
            }
            if (preg_match('#^/serve/views/front/(.+)$#', $path, $m)) {
                return MUBLO_VIEW_PATH . '/Front/' . $m[1];
            }
        }

        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        return $docRoot !== '' ? $docRoot . '/' . ltrim($path, '/') : null;
    }
}
