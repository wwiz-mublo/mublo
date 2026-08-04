<?php
declare(strict_types=1);
namespace Mublo\Core\Rendering;

use Throwable;

/**
 * Class ErrorRenderer
 *
 * 에러 화면 출력 전용
 *
 * 책임:
 * - 예외 → 사용자 화면 변환
 * - 도메인 관련 에러 처리
 */
class ErrorRenderer
{
    /**
     * Error view 경로
     */
    protected string $viewPath;

    public function __construct()
    {
        $this->viewPath = dirname(__DIR__, 3) . '/views/Error';
    }

    /**
     * 일반 예외 렌더링
     */
    public function render(Throwable $e): void
    {
        // TODO:
        // - 운영 / 개발 모드 분기
        // - 에러 템플릿 출력

        http_response_code(500);
        echo 'Error: ' . htmlspecialchars($e->getMessage());
    }

    /**
     * 도메인 미등록 에러 렌더링
     *
     * @param string $domainName 접속 시도한 도메인
     */
    public function renderDomainNotFound(string $domainName): void
    {
        http_response_code(404);
        $this->renderView('DomainNotFound', ['domain' => $domainName]);
    }

    /**
     * 도메인 차단 에러 렌더링
     */
    public function renderDomainBlocked(): void
    {
        http_response_code(403);
        $this->renderView('DomainBlocked');
    }

    /**
     * 404 Not Found 렌더링
     */
    public function renderNotFound(): void
    {
        http_response_code(404);
        $this->renderView('NotFound');
    }

    /**
     * 403 Forbidden 렌더링
     */
    public function renderForbidden(): void
    {
        http_response_code(403);
        $this->renderView('Forbidden');
    }

    /**
     * 500 Server Error 렌더링
     */
    public function renderServerError(?Throwable $e = null): void
    {
        http_response_code(500);
        $this->renderView('ServerError', ['exception' => $e]);
    }

    /**
     * 에러 뷰 렌더링
     *
     * @param string $view 뷰 파일명 (확장자 제외)
     * @param array $data 뷰에 전달할 데이터
     */
    protected function renderView(string $view, array $data = []): void
    {
        $filePath = $this->viewPath . '/' . $view . '.php';

        if (!file_exists($filePath)) {
            // 뷰 파일이 없으면 기본 메시지 출력
            echo "Error: {$view}";
            return;
        }

        // 데이터를 변수로 추출
        extract($data, EXTR_SKIP);

        include $filePath;
    }
}
