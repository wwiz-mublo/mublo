<?php
declare(strict_types=1);
namespace Mublo\Infrastructure\Log;

use Psr\Log\LoggerInterface;

/**
 * Logger
 *
 * 파일 기반 로깅 인프라 클래스
 * - 멀티 도메인 지원 (D{domain_id} 폴더 구조)
 * - PSR-3 (Psr\Log\LoggerInterface) 구현
 * - 일별 로그 파일 분리
 * - 로그 레벨 필터링
 *
 * 저장 구조:
 * storage/logs/D{domain_id}/{channel}/{date}.log
 * storage/logs/_system/{channel}/{date}.log   (domainId 미설정 시)
 *
 * 컨테이너는 LoggerInterface 로도 이 구현을 내준다. 운영자가 Monolog·Sentry
 * 같은 다른 구현으로 갈아끼울 수 있게 하려는 것이고, 확장은 구체 클래스가
 * 아니라 인터페이스에 의존하면 된다.
 */
class Logger implements LoggerInterface
{
    private string $basePath;
    private string $channel;
    private int $domainId;
    private string $minLevel;

    public function __construct(
        int $domainId = 0,
        string $channel = 'app',
        string $minLevel = LogLevel::DEBUG,
        ?string $basePath = null
    ) {
        $this->domainId = $domainId;
        $this->channel = $channel;
        $this->minLevel = $minLevel;
        $this->basePath = $basePath ?? $this->resolveBasePath();
    }

    /**
     * 로그 베이스 경로 결정
     */
    private function resolveBasePath(): string
    {
        // MUBLO_STORAGE_PATH 상수 사용 (프레임워크 표준)
        if (defined('MUBLO_STORAGE_PATH')) {
            return MUBLO_STORAGE_PATH . '/logs';
        }

        // 폴백: 프로젝트 루트 기준 절대 경로
        return dirname(__DIR__, 3) . '/storage/logs';
    }

    /**
     * 도메인 ID 설정
     */
    public function setDomainId(int $domainId): self
    {
        $this->domainId = $domainId;
        return $this;
    }

    /**
     * 채널 설정
     */
    public function setChannel(string $channel): self
    {
        $this->channel = $channel;
        return $this;
    }

    /**
     * 최소 로그 레벨 설정
     */
    public function setMinLevel(string $level): self
    {
        $this->minLevel = $level;
        return $this;
    }

    /**
     * 새 채널 Logger 인스턴스 생성
     */
    public function channel(string $channel): self
    {
        return new self($this->domainId, $channel, $this->minLevel, $this->basePath);
    }

    // === PSR-3 호환 메서드 ===

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * 로그 기록
     *
     * $level 에 타입을 두지 않는 것은 PSR-3 규격이다. 규격상 임의 값이 올 수
     * 있으므로 여기서 문자열로 좁힌 뒤 우선순위를 판단한다.
     *
     * @param mixed $level 로그 레벨 (LogLevel 상수)
     * @param string|\Stringable $message 메시지
     * @param array $context 컨텍스트 데이터
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $level = is_string($level) ? $level : (string) $level;

        // 레벨 필터링
        if (LogLevel::getPriority($level) > LogLevel::getPriority($this->minLevel)) {
            return;
        }

        // 민감 정보(비밀번호/토큰 등) 마스킹 — 로그에 시크릿/PII가 남지 않도록 중앙 처리
        $context = $this->maskContext($context);

        // 메시지에 컨텍스트 변수 치환 (PSR-3 스타일)
        $message = $this->interpolate((string) $message, $context);

        // 로그 라인 생성
        $logLine = $this->formatLogLine($level, $message, $context);

        // 파일에 기록
        $this->writeToFile($logLine);
    }

    /**
     * 예외 로그 (스택트레이스 포함)
     */
    public function exception(\Throwable $e, string $level = LogLevel::ERROR, array $context = []): void
    {
        $context['exception'] = [
            'class' => get_class($e),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];

        if ($e->getPrevious()) {
            $context['previous'] = [
                'class' => get_class($e->getPrevious()),
                'message' => $e->getPrevious()->getMessage(),
            ];
        }

        $this->log($level, $e->getMessage(), $context);
    }

    /**
     * 요청 로그 (API/웹 요청 추적)
     */
    public function request(
        string $method,
        string $uri,
        int $statusCode,
        float $duration,
        array $context = []
    ): void {
        $context['request'] = [
            'method' => $method,
            'uri' => $uri,
            'status' => $statusCode,
            'duration_ms' => round($duration * 1000, 2),
        ];

        $message = "{$method} {$uri} - {$statusCode} ({$context['request']['duration_ms']}ms)";
        $this->info($message, $context);
    }

    /**
     * 쿼리 로그 (SQL 디버깅)
     */
    public function query(string $sql, array $bindings = [], float $duration = 0): void
    {
        $context = [
            'sql' => $sql,
            'bindings' => $bindings,
            'duration_ms' => round($duration * 1000, 2),
        ];

        $this->debug("SQL Query ({$context['duration_ms']}ms)", $context);
    }

    // === 유틸리티 메서드 ===

    /**
     * 오래된 로그 파일 정리
     *
     * @param int $days 보관 일수
     * @return int 삭제된 파일 수
     */
    public function cleanup(int $days = 30): int
    {
        $deleted = 0;
        $domainDir = $this->domainId > 0 ? 'D' . $this->domainId : '_system';
        $domainPath = $this->basePath . '/' . $domainDir;

        if (!is_dir($domainPath)) {
            return 0;
        }

        $cutoffTime = time() - ($days * 86400);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($domainPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'log') {
                if ($file->getMTime() < $cutoffTime) {
                    if (unlink($file->getPathname())) {
                        $deleted++;
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * 로그 파일 경로 반환
     *
     * domainId=0 (Context 확정 전): _system 폴더에 기록
     * domainId>0: D{domainId} 폴더에 기록
     */
    public function getLogPath(?string $date = null): string
    {
        $date = $date ?? date('Y-m-d');
        $domainDir = $this->domainId > 0 ? 'D' . $this->domainId : '_system';
        return $this->basePath . '/' . $domainDir . '/' . $this->channel . '/' . $date . '.log';
    }

    /**
     * 로그 파일 읽기
     *
     * @param string|null $date 날짜 (null이면 오늘)
     * @param int $lines 읽을 라인 수 (0이면 전체)
     * @return array
     */
    public function read(?string $date = null, int $lines = 100): array
    {
        $path = $this->getLogPath($date);

        if (!file_exists($path)) {
            return [];
        }

        $content = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines > 0) {
            $content = array_slice($content, -$lines);
        }

        return $content;
    }

    // === Private Methods ===

    /**
     * 로그 context에서 마스킹할 민감 키 (부분일치, 대소문자 무시).
     * 광범위한 'key'/'auth' 단독 매칭은 제외(정상 디버그 값 과다 마스킹 방지).
     */
    private const SENSITIVE_KEY_PATTERNS = [
        'password', 'passwd', 'pwd', 'secret', 'token',
        'api_key', 'apikey', 'access_token', 'refresh_token',
        'private_key', 'encrypt_key', 'credential', 'authorization', 'pepper',
    ];

    /**
     * context 배열을 재귀적으로 순회하며 민감 키의 값을 마스킹한다.
     */
    private function maskContext(array $context): array
    {
        $masked = [];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $masked[$key] = $this->maskContext($value);
            } elseif ($this->isSensitiveKey((string) $key)) {
                $masked[$key] = '***MASKED***';
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    /**
     * 키 이름이 민감 패턴을 포함하는지 확인
     */
    private function isSensitiveKey(string $key): bool
    {
        $keyLower = strtolower($key);

        foreach (self::SENSITIVE_KEY_PATTERNS as $pattern) {
            if (str_contains($keyLower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 로그 라인 포맷팅
     */
    private function formatLogLine(string $level, string $message, array $context): string
    {
        $timestamp = date('Y-m-d H:i:s.u');
        $levelUpper = strtoupper($level);

        // 기본 포맷: [timestamp] channel.LEVEL: message {context}
        $line = "[{$timestamp}] {$this->channel}.{$levelUpper}: {$message}";

        // 컨텍스트가 있으면 JSON으로 추가
        if (!empty($context)) {
            $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $line .= ' ' . $contextJson;
        }

        return $line;
    }

    /**
     * 파일에 기록
     *
     * @ 에러 억제 사용 이유:
     * ErrorHandler.handleError()가 warning을 예외로 변환하므로,
     * handleException() 내부에서 로그 쓰기 경고가 발생하면
     * 재귀 예외 → fatal error. 로깅 실패는 무시하는 것이 안전.
     */
    private function writeToFile(string $line): void
    {
        $path = $this->getLogPath();
        $dir = dirname($path);

        // 디렉토리 생성 (실패해도 무시 — 로깅이 앱을 중단시키면 안 됨)
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // 파일에 추가 (실패 시 error_log 폴백)
        if (@file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            // 최후 수단: PHP 기본 error_log
            @error_log($line);
        }
    }

    /**
     * PSR-3 스타일 변수 치환
     * {key} 형태의 플레이스홀더를 context 값으로 치환
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];

        foreach ($context as $key => $value) {
            if (is_string($value) || is_numeric($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replace);
    }
}
