<?php
namespace Mublo\Core\Response;

/**
 * Class ViewResponse
 *
 * ============================================================
 * ViewResponse – 화면 출력 "의도"를 표현하는 Response 객체
 * ============================================================
 *
 * ViewResponse 는 Controller 가
 * "어떤 화면을 보여주고 싶은지"를
 * 선언적으로 표현하기 위한 Response 타입이다.
 *
 * 이 객체는
 * 화면이 어디서 조립되는지(Front/Admin),
 * 어떤 레이아웃을 사용하는지,
 * Header / Footer 가 있는지에 대해
 * 어떠한 정보도 가지지 않는다.
 *
 * ------------------------------------------------------------
 * [설계 핵심]
 * ------------------------------------------------------------
 *
 * - Controller 는 "무엇을" 보여줄지만 선언한다.
 * - "어떻게" 보여줄지는 Renderer 의 책임이다.
 * - ViewResponse 는 Renderer 를 제어하지 않는다.
 *
 * ------------------------------------------------------------
 * 이 클래스는
 * Controller ↔ Renderer 사이의
 * '의도 전달용 계약 객체'이다.
 * ------------------------------------------------------------
 */
class ViewResponse extends AbstractResponse
{
    /**
     * 출력할 View 파일의 논리 경로
     *
     * 예:
     * - Board/View
     * - Auth/Login
     * - (절대경로) /path/to/Plugin/views/Admin/History
     */
    protected string $viewPath;

    /**
     * View 에 전달할 데이터
     */
    protected array $viewData = [];

    /**
     * 절대 경로 여부
     *
     * true  : viewPath가 절대 경로 (Plugin/Package용)
     * false : viewPath가 상대 경로 (Core용, 기본값)
     */
    protected bool $isAbsolutePath = false;

    /**
     * 전체 페이지 출력 여부에 대한 "힌트"
     *
     * true  : 페이지 단위 출력 의도
     * false : partial / fragment 출력 의도
     *
     * 실제 Header / Layout / Footer 포함 여부는
     * Renderer 가 Context 와 규칙에 따라 최종 결정한다.
     */
    protected bool $fullPageHint = false;

    /**
     * 생성자는 외부에서 직접 호출하지 않는다.
     *
     * ViewResponse 는
     * Named Constructor 를 통해서만 생성한다.
     */
    protected function __construct()
    {
    }

    /* =========================================================
     * Named Constructor
     * ========================================================= */

    /**
     * ViewResponse 생성 (상대 경로)
     *
     * @param string $viewPath
     *        출력할 View 경로 (예: 'Auth/Login', 'Board/List')
     *
     * @return static
     */
    public static function view(string $viewPath): self
    {
        $instance = new self();
        $instance->viewPath = $viewPath;
        return $instance;
    }

    /**
     * ViewResponse 생성 (절대 경로)
     *
     * Plugin/Package에서 자체 View 파일을 사용할 때
     *
     * 예:
     * ```php
     * return ViewResponse::absoluteView(
     *     MUBLO_PLUGIN_PATH . '/MemberPoint/views/Admin/History'
     * )->withData([...]);
     * ```
     *
     * @param string $absolutePath
     *        절대 경로 (.php 확장자 제외)
     *
     * @return static
     */
    public static function absoluteView(string $absolutePath): self
    {
        $instance = new self();
        $instance->viewPath = $absolutePath;
        $instance->isAbsolutePath = true;
        return $instance;
    }

    /* =========================================================
     * Fluent Interface (의도 표현)
     * ========================================================= */

    /**
     * View 에 전달할 데이터 설정
     *
     * 기존 데이터는 덮어쓰지 않고 병합된다.
     * (Controller / Service 계층에서 점진적 구성 가능)
     *
     * @param array $data
     * @return $this
     */
    public function withData(array $data): self
    {
        $this->viewData = array_merge($this->viewData, $data);
        return $this;
    }

    /**
     * 전체 페이지 출력 의도 선언
     *
     * @return $this
     */
    public function fullPage(): self
    {
        $this->fullPageHint = true;
        return $this;
    }

    /**
     * 부분 출력 의도 선언
     *
     * @return $this
     */
    public function partial(): self
    {
        $this->fullPageHint = false;
        return $this;
    }

    /* =========================================================
     * Getter (Renderer 전용 계약)
     * ========================================================= */

    /**
     * Renderer 전용
     */
    public function getViewPath(): string
    {
        return $this->viewPath;
    }

    /**
     * Renderer 전용
     */
    public function getViewData(): array
    {
        return $this->viewData;
    }

    /**
     * Renderer 전용
     *
     * 이 값은 "명령"이 아니라 "힌트"이다.
     */
    public function isFullPageHint(): bool
    {
        return $this->fullPageHint;
    }

    /**
     * Renderer 전용
     *
     * 절대 경로 여부 반환
     */
    public function isAbsolutePath(): bool
    {
        return $this->isAbsolutePath;
    }
}
