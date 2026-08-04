<?php
declare(strict_types=1);

namespace Mublo\Infrastructure\Session;

use Mublo\Core\ConfigFile;
use Mublo\Core\Session\SessionInterface;
use Mublo\Infrastructure\Maintenance\DailyStorageCleanup;
use Mublo\Infrastructure\Redis\RedisManager;

/**
 * SessionManager
 *
 * PHP 세션 관리 인프라 클래스 (SessionInterface 구현)
 * - 파일/Redis 드라이버 지원
 * - 멀티테넌트 지원 (도메인별 세션 분리)
 * - 세션 시작/종료
 * - 데이터 저장/조회/삭제
 * - 플래시 메시지
 */
class SessionManager implements SessionInterface
{
    /** 마지막 활동 시각 세션 키 (유휴 타임아웃 슬라이딩용) */
    private const LAST_ACTIVITY_KEY = '__last_activity';

    private bool $started = false;
    private array $config;
    private string $driver;
    private ?int $domainId = null;
    private ?DailyStorageCleanup $dailyStorageCleanup;

    /** 플래시 데이터 aging 을 요청(인스턴스)당 1회로 제한 — close 후 재시작 시 이중 aging 방지 */
    private bool $flashAged = false;

    public function __construct(?DailyStorageCleanup $dailyStorageCleanup = null)
    {
        $this->config = $this->loadConfig();
        $this->driver = $this->loadDriver();
        $this->dailyStorageCleanup = $dailyStorageCleanup;
    }

    /**
     * 보안 설정 로드
     */
    private function loadConfig(): array
    {
        $session = ConfigFile::load('security')['session'] ?? null;

        return is_array($session) ? $session : $this->getDefaultConfig();
    }

    /**
     * 드라이버 설정 로드
     */
    private function loadDriver(): string
    {
        return (string) (ConfigFile::load('security')['session_driver'] ?? 'file');
    }

    /**
     * 기본 설정
     */
    private function getDefaultConfig(): array
    {
        return [
            'lifetime' => 120,
            'cookie_secure' => false,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ];
    }

    /**
     * 도메인 ID 설정 (멀티테넌트)
     */
    public function setDomainId(?int $domainId): void
    {
        $this->domainId = $domainId;
    }

    /**
     * 세션 시작
     *
     * @param int|null $domainId 도메인 ID (멀티테넌트 분리용)
     */
    public function start(?int $domainId = null): void
    {
        if ($this->started) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        // 도메인 ID 설정
        if ($domainId !== null) {
            $this->domainId = $domainId;
        }

        // 드라이버별 핸들러 설정
        $this->configureDriver();

        // 쿠키 설정
        $this->configureSession();

        // 파일 세션과 로그의 일일 정리. 실패해도 사용자 세션 시작은 계속되어야 한다.
        if ($this->driver === 'file' && $this->dailyStorageCleanup !== null) {
            try {
                $this->dailyStorageCleanup->runIfDue();
            } catch (\Throwable $e) {
                error_log('[DailyStorageCleanup] ' . $e->getMessage());
            }
        }

        session_start();
        $this->started = true;

        // 플래시 aging 은 요청당 1회만. close() 후 렌더 단계에서 세션이 재시작되더라도
        // 두 번째 aging 으로 플래시가 조기 소멸하지 않도록 방어한다.
        if (!$this->flashAged) {
            $this->ageFlashData();
            $this->flashAged = true;
        }
    }

    /**
     * 드라이버별 세션 핸들러 설정
     */
    private function configureDriver(): void
    {
        if ($this->driver === 'redis') {
            // Redis 확장 체크
            if (!RedisManager::isAvailable()) {
                throw new \RuntimeException(
                    'Redis 확장이 설치되지 않았습니다. SESSION_DRIVER=file로 변경하거나 Redis 확장을 설치하세요.'
                );
            }

            // Redis 연결 체크
            if (!RedisManager::isConnected()) {
                throw new \RuntimeException(
                    'Redis 연결에 실패했습니다. Redis 서버 상태를 확인하세요.'
                );
            }

            // Redis 세션 핸들러 등록 (도메인별 prefix 적용)
            $ttl = ($this->config['lifetime'] ?? 120) * 60;
            $handler = new RedisSessionHandler($ttl, $this->domainId);
            session_set_save_handler($handler, true);
        }
        // file 드라이버는 PHP 기본 핸들러 사용
    }

    /**
     * 세션 설정 구성
     */
    private function configureSession(): void
    {
        $lifetimeSeconds = ($this->config['lifetime'] ?? 120) * 60;

        // gc_maxlifetime을 세션 lifetime과 동기화 (서버 측 세션 조기 정리 방지)
        ini_set('session.gc_maxlifetime', $lifetimeSeconds);

        // 앱의 일일 정리기가 연결된 파일 드라이버는 PHP의 요청 중 확률 GC를 끈다.
        // 디렉터리 전체 스캔이 낮 시간 사용자 요청에 임의로 걸리지 않게 하고,
        // 한국 시간 새벽 정리 창에서만 오래된 파일을 제거한다.
        if ($this->driver === 'file' && $this->dailyStorageCleanup !== null) {
            ini_set('session.gc_probability', '0');
        }

        // 세션 픽세이션 방어(필수):
        // - use_strict_mode: 서버가 발급한 적 없는(공격자가 심은) 세션 ID 를 거부한다.
        //   PHP 기본값 0 은 미발급 ID 를 그대로 채택하므로, 로그인 전 흐름(CSRF 토큰
        //   발급·플래시·장바구니)까지 픽세이션에 노출된다. 반드시 1 로 강제.
        // - use_only_cookies: 세션 ID 를 URL(쿼리스트링)로 받지 않는다. URL 노출·유출 차단.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        // 도메인별 세션 이름 (멀티테넌트 분리)
        $sessionName = $this->domainId
            ? 'MUBLO_sess_' . $this->domainId
            : 'MUBLO_session';

        if ($this->driver === 'file') {
            $this->configureFileSavePath($sessionName);
        }

        // 세션 쿠키(lifetime 0): 브라우저 종료 시 파기.
        // 유휴 만료는 enforceIdleTimeout()이 서버측에서 슬라이딩으로 강제한다.
        //
        // Secure 플래그: config가 명시적으로 켰거나, 요청이 실제 HTTPS면 강제로 true.
        // HTTPS인데도 config 기본값(false)으로 세션 쿠키가 평문 경로로 노출되는 것을 막는다.
        // (HTTP 요청에서는 secure=false로 두어야 쿠키가 정상 동작하므로 자동 감지만 추가)
        $secure = ($this->config['cookie_secure'] ?? false) || $this->isHttpsRequest();

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => $this->config['cookie_httponly'] ?? true,
            'samesite' => $this->config['cookie_samesite'] ?? 'Lax',
        ]);

        session_name($sessionName);
    }

    /**
     * 현재 요청이 HTTPS인지 판별한다.
     *
     * 프록시 뒤(HTTP_X_FORWARDED_PROTO)도 감지한다. XFP를 신뢰해도 보안 약화는 없다 —
     * 평문 요청에 secure를 켜봤자 브라우저가 그 쿠키를 HTTP로 안 보낼 뿐이고(자기 손해),
     * 다른 사용자의 요청에 영향을 줄 수 없다(쿠키 파라미터는 현재 응답에만 적용).
     */
    private function isHttpsRequest(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (strtolower((string) $proto) === 'https') {
            return true;
        }

        return false;
    }

    /**
     * file 세션을 앱 전용 디렉터리에 저장한다.
     *
     * PHP 기본 저장소(/tmp 등)를 공유하면 다른 앱이나 시스템 정리 정책의
     * 짧은 GC 기준에 의해 세션이 조기 삭제될 수 있다.
     */
    private function configureFileSavePath(string $sessionName): void
    {
        if (!defined('MUBLO_STORAGE_PATH')) {
            return;
        }

        $previousSavePath = session_save_path();
        $savePath = MUBLO_STORAGE_PATH . '/sessions';
        if (!is_dir($savePath)) {
            @mkdir($savePath, 0775, true);
        }

        if (is_dir($savePath) && is_writable($savePath)) {
            $this->migrateExistingFileSession($sessionName, $previousSavePath, $savePath);
            session_save_path($savePath);
        }
    }

    /**
     * /tmp 같은 기존 기본 경로에 남아 있는 현재 요청의 세션 파일을
     * 앱 전용 경로로 한 번 복사해 저장 경로 변경 직후의 강제 로그아웃을 줄인다.
     */
    private function migrateExistingFileSession(string $sessionName, string $previousSavePath, string $newSavePath): void
    {
        $sessionId = $_COOKIE[$sessionName] ?? '';
        if (!is_string($sessionId) || !preg_match('/^[a-zA-Z0-9,-]{16,128}$/', $sessionId)) {
            return;
        }

        $oldDir = $previousSavePath !== '' ? $previousSavePath : sys_get_temp_dir();
        $oldFile = rtrim($oldDir, '/\\') . '/sess_' . $sessionId;
        $newFile = rtrim($newSavePath, '/\\') . '/sess_' . $sessionId;

        if ($oldFile === $newFile || file_exists($newFile) || !is_file($oldFile) || !is_readable($oldFile)) {
            return;
        }

        @copy($oldFile, $newFile);
        @chmod($newFile, 0600);
    }

    /**
     * 유휴(idle) 타임아웃 강제 + 슬라이딩 갱신
     *
     * 세션 쿠키(브라우저 종료 시 파기)를 사용하므로 클라이언트는 만료 시각을 갖지
     * 않는다. 따라서 마지막 활동 시각을 서버 세션에 기록하고, lifetime(분)을 초과해
     * 비활성인 세션은 데이터를 비우고 ID를 재발급해 로그아웃 처리한다. 활동이 있으면
     * 매 요청마다 시각을 갱신해 슬라이딩 연장한다.
     *
     * 클라이언트가 쿠키 만료를 무시하고 옛 세션 ID를 재전송하더라도, 만료 초과 시
     * regenerate(true)로 서버측 세션 데이터가 삭제되므로 인증 상태는 복구되지 않는다.
     *
     * @return bool 유휴 초과로 세션이 만료(파기)되었으면 true
     */
    public function enforceIdleTimeout(): bool
    {
        if (!$this->started || session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $idleSeconds = ($this->config['lifetime'] ?? 120) * 60;

        // lifetime <= 0 이면 유휴 만료 비활성화 (브라우저 종료 시 파기만 동작)
        if ($idleSeconds <= 0) {
            return false;
        }

        $now = time();
        $last = $_SESSION[self::LAST_ACTIVITY_KEY] ?? null;

        if ($last !== null && ($now - (int) $last) > $idleSeconds) {
            // 유휴 초과 → 세션 비우고 ID 재발급 (로그아웃 효과)
            $_SESSION = [];
            $this->regenerate(true);
            $_SESSION[self::LAST_ACTIVITY_KEY] = $now;
            return true;
        }

        // 활동 기록 (슬라이딩 연장)
        $_SESSION[self::LAST_ACTIVITY_KEY] = $now;

        return false;
    }

    /**
     * 세션 잠금 해제 (동시 요청 직렬화 방지)
     *
     * 세션 데이터를 기록하고 잠금을 해제합니다.
     * destroy()와 달리 세션 데이터는 유지됩니다.
     */
    public function close(): void
    {
        if (!$this->started || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        session_write_close();
        $this->started = false;
    }

    /**
     * 세션 종료 (로그아웃 시)
     */
    public function destroy(): void
    {
        if (!$this->started) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'] ?? '/',
                    'domain' => $params['domain'] ?? '',
                    'secure' => (bool) ($params['secure'] ?? false),
                    'httponly' => (bool) ($params['httponly'] ?? true),
                    'samesite' => $params['samesite'] ?? ($this->config['cookie_samesite'] ?? 'Lax'),
                ]
            );
        }

        session_destroy();
        $this->started = false;
    }

    /**
     * 세션 ID 재생성 (로그인 후 보안)
     */
    public function regenerate(bool $deleteOldSession = true): bool
    {
        return session_regenerate_id($deleteOldSession);
    }

    /**
     * 세션 값 저장
     */
    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    /**
     * 세션 값 조회
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * 세션 값 존재 여부
     */
    public function has(string $key): bool
    {
        $this->ensureStarted();
        return isset($_SESSION[$key]);
    }

    /**
     * 세션 값 삭제
     */
    public function remove(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$key]);
    }

    /**
     * 모든 세션 데이터 조회
     */
    public function all(): array
    {
        $this->ensureStarted();
        return $_SESSION ?? [];
    }

    /**
     * 플래시 메시지 저장 (다음 요청에서만 유효)
     */
    public function flash(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION['_flash']['new'][$key] = $value;
    }

    /**
     * 플래시 메시지 조회
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();

        return $_SESSION['_flash']['old'][$key]
            ?? $_SESSION['_flash']['new'][$key]
            ?? $default;
    }

    /**
     * 플래시 데이터 aging 처리
     */
    private function ageFlashData(): void
    {
        unset($_SESSION['_flash']['old']);

        if (isset($_SESSION['_flash']['new'])) {
            $_SESSION['_flash']['old'] = $_SESSION['_flash']['new'];
            unset($_SESSION['_flash']['new']);
        }
    }

    /**
     * 세션 시작 보장
     */
    private function ensureStarted(): void
    {
        if (!$this->started) {
            $this->start();
        }
    }

    /**
     * 세션 ID 조회
     */
    public function getId(): string
    {
        $this->ensureStarted();
        return session_id();
    }

    /**
     * 현재 드라이버 반환
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * 현재 도메인 ID 반환
     */
    public function getDomainId(): ?int
    {
        return $this->domainId;
    }
}
