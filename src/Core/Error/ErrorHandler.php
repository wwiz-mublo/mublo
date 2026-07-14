<?php
namespace Mublo\Core\Error;

use Mublo\Core\Rendering\ErrorRenderer;
use Mublo\Exception\ApplicationException;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Infrastructure\Log\LogLevel;

/**
 * ErrorHandler
 *
 * 전역 에러/예외 핸들러
 *
 * 책임:
 * - PHP 에러 → 예외 변환
 * - 예외 로깅 (Logger)
 * - 에러 페이지 렌더링 (ErrorRenderer 위임)
 * - 개발/운영 모드 분기
 */
class ErrorHandler
{
    private ?Logger $logger;
    private ErrorRenderer $renderer;
    private bool $debug;
    private int $domainId;

    public function __construct(
        ?Logger $logger = null,
        ?ErrorRenderer $renderer = null,
        bool $debug = false,
        int $domainId = 0
    ) {
        $this->logger = $logger;
        $this->renderer = $renderer ?? new ErrorRenderer();
        $this->debug = $debug;
        $this->domainId = $domainId;
    }

    /**
     * 도메인 ID 설정 (Context 확정 후 호출)
     */
    public function setDomainId(int $domainId): self
    {
        $this->domainId = $domainId;

        // Logger에도 도메인 ID 설정
        if ($this->logger) {
            $this->logger->setDomainId($domainId);
        }

        return $this;
    }

    /**
     * 디버그 모드 설정
     */
    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }

    /**
     * Logger 설정
     */
    public function setLogger(Logger $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * 전역 핸들러 등록
     *
     * Application::boot()에서 호출
     */
    public function register(): void
    {
        // PHP 에러를 예외로 변환
        set_error_handler([$this, 'handleError']);

        // 예외 핸들러 등록
        set_exception_handler([$this, 'handleException']);

        // Fatal 에러 처리
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * PHP 에러 핸들러
     *
     * E_WARNING, E_NOTICE 등을 예외로 변환
     */
    public function handleError(
        int $level,
        string $message,
        string $file = '',
        int $line = 0
    ): bool {
        // error_reporting 설정에 따라 무시
        if (!(error_reporting() & $level)) {
            return false;
        }

        throw new \ErrorException($message, 0, $level, $file, $line);
    }

    /**
     * 예외 핸들러
     */
    public function handleException(\Throwable $e): void
    {
        // 1. 로깅
        $this->logException($e);

        // 2. 응답 처리
        $this->renderException($e);
    }

    /**
     * Shutdown 핸들러 (Fatal 에러 처리)
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            $e = new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            );

            $this->handleException($e);
        }
    }

    /**
     * 예외를 직접 처리 (Application의 catch 블록에서 호출)
     */
    public function handle(\Throwable $e): void
    {
        $this->handleException($e);
    }

    /**
     * 예외 로깅
     */
    private function logException(\Throwable $e): void
    {
        // 정적 에셋 경로 및 브라우저 자동 probe 경로의 404는 로깅 생략
        // - /assets/, /serve/: 정적 에셋 (Apache/Nginx ErrorDocument 404로 PHP 유입)
        // - /.well-known/, /favicon.ico, /apple-touch-icon, /robots.txt:
        //   브라우저/DevTools/봇이 자동 요청하는 경로
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = ($pos = strpos($uri, '?')) !== false ? substr($uri, 0, $pos) : $uri;
        if ($this->determineStatusCode($e) === 404 && (
            str_starts_with($path, '/assets/')
            || str_starts_with($path, '/serve/')
            || str_starts_with($path, '/.well-known/')
            || str_starts_with($path, '/apple-touch-icon')
            || $path === '/favicon.ico'
            || $path === '/robots.txt'
        )) {
            return;
        }

        if (!$this->logger) {
            // Logger가 없으면 기본 error_log 사용
            error_log($this->formatException($e));
            return;
        }

        // 에러 채널로 로깅
        $errorLogger = $this->logger->channel('error');

        // 예외 종류에 따라 로그 레벨 결정
        $level = $this->determineLogLevel($e);

        $errorLogger->log($level, $e->getMessage(), [
            'exception' => get_class($e),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $this->debug ? $e->getTraceAsString() : $this->getSafeTrace($e),
            'url' => $this->maskSensitiveParams($_SERVER['REQUEST_URI'] ?? ''),
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);
    }

    /**
     * 예외 종류에 따른 로그 레벨 결정
     */
    private function determineLogLevel(\Throwable $e): string
    {
        // ErrorException의 severity에 따라 레벨 결정
        if ($e instanceof \ErrorException) {
            return match ($e->getSeverity()) {
                E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE => LogLevel::CRITICAL,
                E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => LogLevel::WARNING,
                E_NOTICE, E_USER_NOTICE, E_DEPRECATED => LogLevel::NOTICE,
                default => LogLevel::ERROR,
            };
        }

        // 타입 예외 계층은 httpStatusCode 로 판정 (getCode() 는 0 이라 아래 분기를 못 탄다)
        if ($e instanceof ApplicationException) {
            $status = $e->getHttpStatusCode();
            return $status >= 400 && $status < 500 ? LogLevel::WARNING : LogLevel::ERROR;
        }

        // HTTP 에러 코드 기반 (커스텀 예외에서 getCode() 사용 시)
        $code = $e->getCode();
        if ($code >= 400 && $code < 500) {
            return LogLevel::WARNING;  // 클라이언트 에러
        }

        return LogLevel::ERROR;  // 기본
    }

    /**
     * 예외 렌더링
     */
    private function renderException(\Throwable $e): void
    {
        // 이미 출력 시작되었는지 확인
        if (headers_sent()) {
            if ($this->debug) {
                echo '<pre>' . htmlspecialchars($this->formatException($e)) . '</pre>';
            }
            return;
        }

        // HTTP 상태 코드 결정
        $statusCode = $this->determineStatusCode($e);

        // JSON 요청인 경우 JSON 응답
        if ($this->isJsonRequest()) {
            $this->renderJsonError($e, $statusCode);
            return;
        }

        // HTML 응답
        $this->renderHtmlError($e, $statusCode);
    }

    /**
     * HTTP 상태 코드 결정
     *
     * Router가 던지는 예외 형식: "404 Not Found", "405 Method Not Allowed"
     * → 메시지 시작이 HTTP 상태코드(3자리 숫자)인 경우만 매칭
     *
     * 주의: str_contains('not found') 같은 광범위 매칭 금지
     * → "Service not found: Context" 같은 내부 에러가 404로 오판됨
     */
    private function determineStatusCode(\Throwable $e): int
    {
        // 타입 예외 계층이 HTTP 상태코드를 직접 보유하면 그것을 최우선으로 신뢰한다.
        // (ValidationException=400, HttpNotFoundException=404 등. 이전에는 이 값을 무시하고
        //  메시지 정규식만 봐서 미처리 ValidationException 이 500 으로 렌더되는 버그가 있었다.)
        if ($e instanceof ApplicationException) {
            return $e->getHttpStatusCode();
        }

        $message = $e->getMessage();

        // Router 명시적 HTTP 에러 ("404 Not Found", "405 Method Not Allowed" 등)
        if (preg_match('/^([45]\d{2})\s/', $message, $matches)) {
            return (int) $matches[1];
        }

        // 예외 코드가 HTTP 상태 코드인 경우
        $code = (int) $e->getCode();
        if ($code >= 400 && $code < 600) {
            return $code;
        }

        return 500;
    }

    /**
     * JSON 요청 여부 확인
     */
    private function isJsonRequest(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        return str_contains($accept, 'application/json') ||
               str_contains($contentType, 'application/json') ||
               !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    /**
     * JSON 에러 응답
     */
    private function renderJsonError(\Throwable $e, int $statusCode): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');

        $response = [
            'success' => false,
            'error' => [
                'message' => $this->debug ? $e->getMessage() : $this->getPublicMessage($statusCode),
                'code' => $statusCode,
            ],
        ];

        // 디버그 모드에서 상세 정보 포함
        if ($this->debug) {
            $response['error']['debug'] = [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ];
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    }

    /**
     * HTML 에러 응답
     */
    private function renderHtmlError(\Throwable $e, int $statusCode): void
    {
        http_response_code($statusCode);

        switch ($statusCode) {
            case 404:
                $this->renderer->renderNotFound();
                break;

            case 403:
                $this->renderer->renderForbidden();
                break;

            default:
                if ($this->debug) {
                    // 디버그 모드: 상세 에러 표시
                    $this->renderDebugError($e);
                } else {
                    // 운영 모드: 일반 에러 페이지
                    $this->renderer->renderServerError();
                }
                break;
        }
    }

    /**
     * 디버그 모드 에러 페이지
     */
    private function renderDebugError(\Throwable $e): void
    {
        ?>
        <!DOCTYPE html>
        <html lang="ko">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Error - <?= htmlspecialchars(get_class($e)) ?></title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #1a1a2e; color: #eee; padding: 20px; }
                .container { max-width: 1200px; margin: 0 auto; }
                .header { background: #e74c3c; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
                .header h1 { font-size: 18px; font-weight: 600; }
                .header .message { font-size: 24px; margin-top: 10px; word-break: break-word; }
                .content { background: #16213e; padding: 20px; border-radius: 0 0 8px 8px; }
                .section { margin-bottom: 20px; }
                .section-title { color: #3498db; font-size: 14px; font-weight: 600; margin-bottom: 10px; text-transform: uppercase; }
                .info-grid { display: grid; grid-template-columns: 120px 1fr; gap: 8px; font-size: 14px; }
                .info-label { color: #888; }
                .info-value { color: #eee; word-break: break-all; }
                .trace { background: #0f0f23; padding: 15px; border-radius: 4px; font-family: 'Fira Code', monospace; font-size: 13px; overflow-x: auto; white-space: pre-wrap; line-height: 1.6; }
                .trace-line { padding: 2px 0; }
                .trace-line:hover { background: #1a1a3e; }
                .file-link { color: #3498db; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1><?= htmlspecialchars(get_class($e)) ?></h1>
                    <div class="message"><?= htmlspecialchars($e->getMessage()) ?></div>
                </div>
                <div class="content">
                    <div class="section">
                        <div class="section-title">Error Info</div>
                        <div class="info-grid">
                            <span class="info-label">File</span>
                            <span class="info-value file-link"><?= htmlspecialchars($e->getFile()) ?></span>
                            <span class="info-label">Line</span>
                            <span class="info-value"><?= $e->getLine() ?></span>
                            <span class="info-label">Code</span>
                            <span class="info-value"><?= $e->getCode() ?></span>
                        </div>
                    </div>
                    <div class="section">
                        <div class="section-title">Stack Trace</div>
                        <div class="trace"><?php
                            foreach ($e->getTrace() as $i => $frame) {
                                $file = htmlspecialchars((string) ($frame['file'] ?? '[internal]'), ENT_QUOTES, 'UTF-8');
                                $line = htmlspecialchars((string) ($frame['line'] ?? '?'), ENT_QUOTES, 'UTF-8');
                                $class = htmlspecialchars((string) ($frame['class'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $type = htmlspecialchars((string) ($frame['type'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $function = htmlspecialchars((string) ($frame['function'] ?? ''), ENT_QUOTES, 'UTF-8');
                                echo '<div class="trace-line">#' . $i . ' <span class="file-link">' . $file . '</span>(' . $line . '): ' . $class . $type . $function . '()</div>';
                            }
                        ?></div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
    }

    /**
     * 공개용 에러 메시지 (운영 모드)
     */
    private function getPublicMessage(int $statusCode): string
    {
        return match ($statusCode) {
            400 => '잘못된 요청입니다.',
            401 => '인증이 필요합니다.',
            403 => '접근이 거부되었습니다.',
            404 => '페이지를 찾을 수 없습니다.',
            405 => '허용되지 않은 요청 방법입니다.',
            500 => '서버 오류가 발생했습니다.',
            502 => '서버 연결에 실패했습니다.',
            503 => '서비스를 일시적으로 사용할 수 없습니다.',
            default => '오류가 발생했습니다.',
        };
    }

    /**
     * 예외 포맷팅 (error_log용)
     */
    private function formatException(\Throwable $e): string
    {
        return sprintf(
            "[%s] %s: %s in %s:%d\nStack trace:\n%s",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
    }

    /**
     * URL 쿼리스트링의 민감 파라미터 값 마스킹 (로그 기록용)
     */
    private function maskSensitiveParams(string $url): string
    {
        $sensitive = ['password', 'passwd', 'token', 'secret', 'key', 'auth', 'api_key', 'access_token'];
        $pattern = '/([?&])(' . implode('|', $sensitive) . ')=([^&]*)/i';
        return preg_replace($pattern, '$1$2=***', $url) ?? $url;
    }

    /**
     * 안전한 스택 트레이스 (운영 모드용 - 파일 경로 숨김)
     */
    private function getSafeTrace(\Throwable $e): string
    {
        $trace = [];
        foreach ($e->getTrace() as $i => $frame) {
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $function = $frame['function'] ?? '';
            $trace[] = "#{$i} {$class}{$type}{$function}()";
        }
        return implode("\n", array_slice($trace, 0, 10));  // 최대 10개만
    }
}
