<?php
namespace Mublo\Infrastructure\Security;

use Mublo\Infrastructure\Crypto\CryptoManager;
use Mublo\Core\Session\SessionInterface;

/**
 * Class CsrfManager
 *
 * CSRF 토큰 관리 서비스
 *
 * 책임:
 * - CSRF 토큰 생성
 * - CSRF 토큰 검증
 * - 세션 기반 토큰 저장/조회
 */
class CsrfManager
{
    private const SESSION_KEY = '_csrf_token';
    private const TOKEN_LENGTH = 32;

    protected CryptoManager $crypto;
    private ?SessionInterface $session;

    public function __construct(?SessionInterface $session = null)
    {
        $this->crypto = new CryptoManager();
        $this->session = $session;
    }

    /**
     * CSRF 토큰 생성 및 세션 저장
     *
     * @return string 생성된 토큰
     */
    public function generateToken(): string
    {
        $this->ensureSession();

        $token = $this->crypto->generateToken(self::TOKEN_LENGTH);
        $_SESSION[self::SESSION_KEY] = $token;

        return $token;
    }

    /**
     * 현재 CSRF 토큰 반환 (없으면 생성)
     *
     * @return string
     */
    public function getToken(): string
    {
        // session_write_close() 이후에도 $_SESSION 슈퍼전역은 유지된다. 토큰이 이미 있으면
        // 세션을 (재)시작하지 않고 그대로 읽어, 렌더 단계(세션이 닫힌 뒤)에 세션이 통째로
        // 재시작되는 것을 막는다. 재시작은 플래시 데이터 이중 aging + 잠금 재획득을 유발한다.
        if (isset($_SESSION[self::SESSION_KEY])) {
            return $_SESSION[self::SESSION_KEY];
        }

        $this->ensureSession();

        if (!isset($_SESSION[self::SESSION_KEY])) {
            return $this->generateToken();
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * CSRF 토큰 검증
     *
     * @param string $token 검증할 토큰
     * @return bool
     */
    public function validateToken(string $token): bool
    {
        $this->ensureSession();

        if (!isset($_SESSION[self::SESSION_KEY])) {
            return false;
        }

        return $this->crypto->secureCompare($_SESSION[self::SESSION_KEY], $token);
    }

    /**
     * CSRF 토큰 재생성 (로그인 후 등)
     *
     * @return string 새 토큰
     */
    public function regenerateToken(): string
    {
        $this->ensureSession();

        // 기존 토큰 삭제
        unset($_SESSION[self::SESSION_KEY]);

        return $this->generateToken();
    }

    /**
     * CSRF 토큰 삭제
     */
    public function clearToken(): void
    {
        $this->ensureSession();

        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * 세션 시작 확인
     *
     * SessionManager가 주입되면 그것을 통해 시작한다(도메인 스코프 이름·secure/samesite 등
     * 올바른 쿠키 설정 적용, 이미 활성이면 no-op). 정상 요청 흐름에서는 SessionMiddleware가
     * 먼저 세션을 시작하므로 여기서는 대부분 no-op이다.
     */
    private function ensureSession(): void
    {
        if ($this->session !== null) {
            $this->session->start();
            return;
        }

        // 폴백(주입되지 않은 예외적 경로): 이미 없을 때만 기본 시작.
        // 정상 경로에서는 도달하지 않는다.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
