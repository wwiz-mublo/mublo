<?php
declare(strict_types=1);

namespace Mublo\Core\Rendering;

use Mublo\Core\Registry\CategoryProviderRegistry;
use Mublo\Contract\Member\MemberActionTargetTransport;
use Mublo\Contract\Member\MemberActionView;
use Mublo\Helper\List\ListColumnBuilder;

/**
 * ViewContext
 *
 * ============================================================
 * View 파일에서 $this 컨텍스트를 제공하는 통합 클래스
 * ============================================================
 *
 * Admin/Front 공용으로 사용되며, 필요한 Helper를 동적으로 주입받아
 * View에서 $this->helperName 형태로 접근할 수 있게 한다.
 *
 * ------------------------------------------------------------
 * [사용 예시 - Admin]
 * ------------------------------------------------------------
 *
 * ```php
 * // Renderer에서 설정
 * $viewContext = new ViewContext($adminSkin);
 * $viewContext->setHelper('listRenderHelper', new ListRenderHelper());
 *
 * // View에서 사용
 * $columns = $this->columns()->add('id', 'ID')->build();
 * echo $this->listRenderHelper->setColumns($columns)->render();
 * echo $this->pagination($pagination);
 * ```
 *
 * ------------------------------------------------------------
 * [사용 예시 - Front]
 * ------------------------------------------------------------
 *
 * ```php
 * // Renderer에서 Helper 주입
 * $viewContext = new ViewContext('front');
 * $viewContext->setHelper('format', new ViewFormatHelper());
 * $viewContext->setHelper('content', new ViewContentHelper());
 *
 * // View에서 사용
 * echo $this->format->highlightKeyword($item['title_safe'], $keyword);
 * echo $this->content->thumbnail($article['content']);
 * echo $this->pagination($pagination);
 * ```
 *
 * ------------------------------------------------------------
 * [Helper 주입 패턴]
 * ------------------------------------------------------------
 *
 * ViewContext는 핵심 기능(컴포넌트, 페이지네이션, 렌더링)만 직접 제공.
 * 도메인별 기능은 setHelper()로 주입하여 확장.
 *
 * | Helper | 키 | 스킨에서 | 설명 |
 * |--------|-----|---------|------|
 * | ViewFormatHelper | format | $this->format->method() | 포맷팅 |
 * | ViewContentHelper | content | $this->content->method() | 콘텐츠 파싱 |
 * | ListRenderHelper | listRenderHelper | $this->listRenderHelper | Admin 목록 |
 * | (Plugin) | shop | $this->shop->method() | 플러그인 확장 |
 *
 * ------------------------------------------------------------
 */
class ViewContext
{
    private const MEMBER_ACTION_MENU_OPTIONS = ['placement', 'compact', 'ariaLabel', 'triggerLabel'];

    /**
     * View 스킨 (Admin 또는 Front 그룹 스킨)
     */
    protected string $skin;

    /**
     * View에 바인딩된 데이터
     */
    protected array $viewData = [];

    /**
     * 동적으로 주입된 Helper 저장소
     */
    protected array $helpers = [];

    /**
     * 현재 요청의 쿼리스트링 (페이지네이션 등에서 사용)
     */
    protected string $queryString = '';

    /**
     * 레이아웃 옵션 (스킨에서 $this->layout()으로 설정)
     *
     * 지원 키:
     * - header: bool (Header 포함 여부, 기본 true)
     * - footer: bool (Footer 포함 여부, 기본 true)
     */
    protected array $layoutOptions = [];

    /**
     * 카테고리 레지스트리 (Package가 등록한 카테고리 트리 조회)
     */
    protected ?CategoryProviderRegistry $categoryRegistry = null;

    /**
     * 도메인 ID (카테고리 조회에 사용)
     */
    protected ?int $domainId = null;

    /**
     * @param string $skin View 스킨
     */
    public function __construct(string $skin)
    {
        $this->skin = $skin;
    }

    /**
     * 쿼리스트링 설정 (Renderer에서 Request 기반으로 주입)
     */
    public function setQueryString(string $queryString): void
    {
        $this->queryString = $queryString;
    }

    /**
     * 현재 쿼리스트링 반환 (뷰에서 목록 컨텍스트 보존용)
     */
    public function getQueryString(): string
    {
        return $this->queryString;
    }

    /**
     * CategoryProviderRegistry + domainId 설정 (Renderer에서 주입)
     */
    public function setCategoryRegistry(CategoryProviderRegistry $registry, int $domainId): void
    {
        $this->categoryRegistry = $registry;
        $this->domainId = $domainId;
    }

    /**
     * 카테고리 트리 조회
     *
     * Package가 등록한 카테고리를 스킨에서 조회한다.
     *
     * ```php
     * // 스킨 (Header.php 등)
     * <?php $categories = $this->category('shop'); ?>
     * <?php foreach ($categories as $cat): ?>
     *     <a href="<?= $cat['link'] ?>"><?= $cat['label'] ?></a>
     * <?php endforeach; ?>
     * ```
     *
     * @param string $key Provider 키 (예: 'shop', 'rental')
     * @param int|null $depth 최대 depth (null = 전체, 1 = 루트만, 2 = 2단계까지)
     * @return array 규격화된 카테고리 트리 (미등록 시 빈 배열)
     */
    public function category(string $key, ?int $depth = null): array
    {
        if (!$this->categoryRegistry || !$this->domainId) {
            return [];
        }

        return $this->categoryRegistry->getTree($key, $this->domainId, $depth);
    }

    /* =========================================================
     * Helper 관리
     * ========================================================= */

    /**
     * Helper 주입 (Renderer 전용)
     *
     * @param string $name Helper 이름 (View에서 $this->name으로 접근)
     * @param object $helper Helper 인스턴스
     */
    public function setHelper(string $name, object $helper): void
    {
        $this->helpers[$name] = $helper;
    }

    /**
     * View에서 $this->helperName 접근 지원
     *
     * @param string $name Helper 이름
     * @return object|null
     */
    public function __get(string $name): ?object
    {
        return $this->helpers[$name] ?? null;
    }

    /**
     * Helper 존재 여부 확인
     */
    public function __isset(string $name): bool
    {
        return isset($this->helpers[$name]);
    }

    /* =========================================================
     * View 데이터 관리
     * ========================================================= */

    /**
     * View 데이터 바인딩
     */
    public function bind(array $data): void
    {
        $this->viewData = $data;
    }

    /**
     * View 데이터 조회
     *
     * @param string|null $key 키 (null이면 전체 반환)
     * @param mixed $default 기본값
     * @return mixed
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->viewData;
        }

        return $this->viewData[$key] ?? $default;
    }

    /* =========================================================
     * Layout 옵션 (스킨에서 Header/Footer 제어)
     * ========================================================= */

    /**
     * 레이아웃 옵션 설정 (스킨 View 상단에서 호출)
     *
     * ```php
     * // 스킨 View 파일 상단
     * <?php $this->layout(['header' => false, 'footer' => false]); ?>
     * ```
     *
     * @param array $options 레이아웃 옵션
     */
    public function layout(array $options): void
    {
        $this->layoutOptions = array_merge($this->layoutOptions, $options);
    }

    /**
     * 레이아웃 옵션 조회 (Renderer에서 사용)
     *
     * @param string $key 옵션 키 ('header', 'footer')
     * @param bool $default 기본값
     * @return bool
     */
    public function getLayoutOption(string $key, bool $default = true): bool
    {
        return (bool) ($this->layoutOptions[$key] ?? $default);
    }

    /**
     * 레이아웃 옵션 원시값 조회 (Renderer에서 사용)
     *
     * 불리언이 아닌 힌트용 — 예: 'layout' => 'full'|'left'|'right'|'both'
     * (스킨이 사이드바 레이아웃을 직접 선언할 때)
     */
    public function getLayoutOptionValue(string $key, mixed $default = null): mixed
    {
        return $this->layoutOptions[$key] ?? $default;
    }

    /* =========================================================
     * Component 렌더링
     * ========================================================= */

    /**
     * 컴포넌트 렌더링
     *
     * 경로: views/Components/{name}.php
     *
     * @param string $name 컴포넌트 이름 (예: 'pagination', 'breadcrumb')
     * @param array $data 컴포넌트에 전달할 데이터
     * @return string 렌더링된 HTML
     */
    public function component(string $name, array $data = []): string
    {
        $path = MUBLO_VIEW_PATH . "/Components/{$name}.php";

        if (!file_exists($path)) {
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                return "<!-- Component not found: Components/{$name}.php -->";
            }
            return '';
        }

        ob_start();
        extract($data, EXTR_SKIP);
        include $path;
        return ob_get_clean();
    }

    /**
     * 페이지네이션 렌더링
     *
     * @param array $pagination 페이지네이션 데이터
     *   - currentPage: int 현재 페이지
     *   - totalPages: int 전체 페이지 수
     *   - totalItems: int 전체 아이템 수
     *   - perPage: int 페이지당 아이템 수
     * @return string
     */
    public function pagination(array $pagination): string
    {
        $totalPages = $pagination['totalPages'] ?? 1;

        if ($totalPages <= 1) {
            return '';
        }

        // 컴포넌트 사용 시점에 CSS 등록 — 'component' 슬롯(스킨 앞 → 스킨이 덮을 수 있게)
        if (isset($this->helpers['assets'])) {
            $this->helpers['assets']->addCss('/assets/css/components/mublo-pagination.css', 'component');
        }

        // 쿼리스트링 유지를 위한 데이터 추가
        $pagination['queryString'] = $this->queryString;

        return $this->component('pagination', $pagination);
    }

    /**
     * 검증된 회원 액션을 코어 소유 메뉴로 렌더링한다.
     *
     * @param list<MemberActionView> $actions
     * @param array{placement?:string,compact?:bool,ariaLabel?:string,triggerLabel?:string} $options
     */
    public function memberActionMenu(array $actions, string $targetPublicId, array $options = []): string
    {
        if ($actions === [] || preg_match('/\A[0-9a-f]{22}\z/', $targetPublicId) !== 1) {
            return '';
        }

        $actions = array_values(array_filter(
            $actions,
            static fn (mixed $action): bool => $action instanceof MemberActionView
        ));
        if ($actions === []) {
            return '';
        }

        $mublo = $this->get('mublo', []);
        $csrfToken = is_array($mublo) ? (string) ($mublo['security']['csrfToken'] ?? '') : '';
        if ($csrfToken === '') {
            $actions = array_values(array_filter(
                $actions,
                static fn (MemberActionView $action): bool =>
                    $action->getTargetTransport() !== MemberActionTargetTransport::PrivateBody
            ));
            if ($actions === []) {
                return '';
            }
        }

        if (isset($this->helpers['assets'])) {
            $this->helpers['assets']->addCss('/assets/css/components/mublo-member-action-menu.css', 'component');
            $this->helpers['assets']->addJs('/assets/js/components/mublo-member-action-menu.js', 'footer');
        }

        $safeOptions = array_intersect_key($options, array_flip(self::MEMBER_ACTION_MENU_OPTIONS));
        $placement = (string) ($safeOptions['placement'] ?? '');
        if ($placement !== ''
            && preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $placement) !== 1
        ) {
            $placement = '';
        }

        return $this->component('member_action_menu', [
            'placement' => $placement,
            'compact' => (bool) ($safeOptions['compact'] ?? false),
            'ariaLabel' => (string) ($safeOptions['ariaLabel'] ?? '회원 액션'),
            'triggerLabel' => (string) ($safeOptions['triggerLabel'] ?? ''),
            'actions' => $actions,
            'targetPublicId' => $targetPublicId,
            'csrfToken' => $csrfToken,
        ]);
    }

    /**
     * 메뉴 렌더링
     *
     * @param array $menus 계층형 메뉴 트리 (MenuService::getTreeHierarchy 결과)
     * @param string $activeCode 현재 활성 메뉴 코드
     * @return string 렌더링된 HTML
     */
    public function menu(array $menus, string $activeCode = ''): string
    {
        if (empty($menus)) {
            return '';
        }

        return $this->component('menu', [
            'menus' => $menus,
            'activeCode' => $activeCode,
        ]);
    }

    /* =========================================================
     * Admin 전용 Helper (팩토리 메서드)
     * ========================================================= */

    /**
     * ListColumnBuilder 팩토리
     *
     * 매번 새 인스턴스를 반환 (빌더 패턴)
     *
     * @return ListColumnBuilder
     */
    public function columns(): ListColumnBuilder
    {
        return new ListColumnBuilder();
    }

    /* =========================================================
     * View 렌더링
     * ========================================================= */

    /**
     * View 파일 렌더링
     *
     * View 파일 내에서 $this로 ViewContext에 접근 가능
     *
     * @param string $path View 파일 절대 경로
     * @param array $data View에 전달할 데이터
     */
    public function render(string $path, array $data = []): void
    {
        $this->viewData = $data;
        extract($data, EXTR_SKIP);
        include $path;
    }

    /* =========================================================
     * Getter
     * ========================================================= */

    /**
     * 현재 View 스킨 반환
     */
    public function getSkin(): string
    {
        return $this->skin;
    }
}
