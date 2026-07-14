<?php
namespace Mublo\Core\Response;

/**
 * Class HtmlResponse
 *
 * ============================================================
 * HtmlResponse – 원시 HTML/Text 문자열을 직접 출력하는 Response
 * ============================================================
 *
 * Controller 에서 View 파일(템플릿) 없이
 * 문자열을 그대로 HTTP 응답으로 내보낼 때 사용한다.
 *
 * Renderer 를 거치지 않으며,
 * Header / Footer / Layout 이 일절 포함되지 않는다.
 *
 * ------------------------------------------------------------
 * [ViewResponse::partial() 과의 차이]
 * ------------------------------------------------------------
 *
 * - ViewResponse::partial()
 *   → .php 뷰 파일이 존재하며, Renderer 를 경유한다.
 *   → Layout(Header/Footer) 만 생략할 뿐, 템플릿 렌더링은 수행된다.
 *
 * - HtmlResponse
 *   → 뷰 파일이 필요 없다. 코드에서 생성한 문자열을 그대로 echo 한다.
 *   → Renderer 를 거치지 않는다.
 *
 * ------------------------------------------------------------
 * [사용 예시]
 * ------------------------------------------------------------
 *
 * 1) Sitemap XML 출력
 *    ```php
 *    $xml = '<?xml version="1.0"?>...<urlset>...</urlset>';
 *    return (new HtmlResponse($xml))
 *        ->withHeader('Content-Type', 'application/xml; charset=UTF-8');
 *    ```
 *
 * 2) 외부 PG(결제 게이트웨이) 콜백 응답
 *    ```php
 *    // PG사가 기대하는 단순 텍스트 응답
 *    return new HtmlResponse('SUCCESS');
 *    return new HtmlResponse('FAIL', 403);
 *    ```
 *
 * 3) iframe / 위젯 임베드용 HTML
 *    ```php
 *    $html = '<html><body><div id="map">...</div><script>...</script></body></html>';
 *    return new HtmlResponse($html);
 *    ```
 *
 * 4) 외부 서비스 Webhook 수신 확인
 *    ```php
 *    return new HtmlResponse('OK');
 *    ```
 *
 * 5) 건강 체크(Health Check) 엔드포인트
 *    ```php
 *    return (new HtmlResponse(json_encode(['status' => 'ok'])))
 *        ->withHeader('Content-Type', 'application/json');
 *    ```
 *
 * ------------------------------------------------------------
 * [정리]
 * ------------------------------------------------------------
 *
 * "뷰 파일 없이, 문자열을 있는 그대로 내보낸다"
 * 이것이 HtmlResponse 의 존재 이유이다.
 */
class HtmlResponse extends AbstractResponse
{
    private string $html;

    public function __construct(string $html, int $statusCode = 200)
    {
        $this->html = $html;
        $this->statusCode = $statusCode;
        $this->headers['Content-Type'] = 'text/html; charset=UTF-8';
    }

    public function getHtml(): string
    {
        return $this->html;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->headers as $key => $value) {
            header("{$key}: {$value}");
        }
        echo $this->html;
    }
}
