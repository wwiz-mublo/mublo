<?php
declare(strict_types=1);
namespace Mublo\Infrastructure\Security;

use Mublo\Contract\Security\CsrfTokenProviderInterface;
use Mublo\Core\ConfigFile;
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
 *
 * ── 유효시간(security.csrf.token_ttl) ──
 * **슬라이딩 만료**다. 검증에 성공할 때마다 시각이 갱신되므로, 활동 중인 사용자는
 * 끊기지 않고 오래 방치된 토큰만 만료된다.
 *
 * 고정 만료로 하면 긴 글을 쓰는 도중 만료되어 제출 시 본문을 잃는다. 게시판이
 * 주력인 제품에서 그 사고를 만들지 않으려고 슬라이딩을 택했다. 유출된 옛 토큰의
 * 수명을 제한한다는 목적은 그대로 달성된다.
 *
 * 0 이하면 만료를 적용하지 않는다(세션 수명에만 의존 — 이전 동작).
 */
class CsrfManager implements CsrfTokenProviderInterface
{
    private const SESSION_KEY = '_csrf_token';
    /** 마지막으로 발급·검증된 시각. 슬라이딩 만료의 기준점. */
    private const SESSION_TIME_KEY = '_csrf_token_at';
    private const TOKEN_LENGTH = 32;

    protected CryptoManager $crypto;
    private ?SessionInterface $session;
    private ?int $tokenTtl;

    /**
     * @param int|null $tokenTtl 유휴 허용 시간(초). null 이면 security 설정에서 읽는다.
     */
    public function __construct(?SessionInterface $session = null, ?int $tokenTtl = null)
    {
        $this->crypto = new CryptoManager();
        $this->session = $session;
        $this->tokenTtl = $tokenTtl;
    }

    /**
     * 적용할 유휴 허용 시간(초). 0 이하면 만료 없음.
     */
    private function tokenTtl(): int
    {
        if ($this->tokenTtl !== null) {
            return $this->tokenTtl;
        }

        // 설치 전에는 config 가 없을 수 있다 — 부재는 '만료 없음'으로 다룬다.
        $this->tokenTtl = (int) (ConfigFile::load('security')['csrf']['token_ttl'] ?? 0);

        return $this->tokenTtl;
    }

    /**
     * 저장된 토큰이 유휴 허용 시간을 넘겼는지.
     */
    private function isExpired(): bool
    {
        $ttl = $this->tokenTtl();
        if ($ttl <= 0) {
            return false;
        }

        // 시각이 없는 토큰(이전 버전에서 발급)은 만료로 보지 않는다. 업데이트 직후
        // 열려 있던 모든 폼이 한꺼번에 깨지는 것을 막고, 다음 검증에서 시각이 붙는다.
        $issuedAt = $_SESSION[self::SESSION_TIME_KEY] ?? null;
        if (!is_int($issuedAt)) {
            return false;
        }

        return (time() - $issuedAt) > $ttl;
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
        $_SESSION[self::SESSION_TIME_KEY] = time();

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
        if (isset($_SESSION[self::SESSION_KEY]) && !$this->isExpired()) {
            return $_SESSION[self::SESSION_KEY];
        }

        $this->ensureSession();

        // 만료된 토큰은 새로 발급한다 — 화면에 죽은 토큰을 심어 제출 때 실패시키지 않는다
        if (!isset($_SESSION[self::SESSION_KEY]) || $this->isExpired()) {
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

        // 만료된 토큰은 값이 맞아도 거부하고 즉시 버린다. 남겨두면 다음 요청이
        // 같은 죽은 토큰으로 다시 실패한다.
        if ($this->isExpired()) {
            $this->clearToken();
            return false;
        }

        if (!$this->crypto->secureCompare($_SESSION[self::SESSION_KEY], $token)) {
            return false;
        }

        // 슬라이딩 — 검증에 성공했으므로 유휴 시계를 다시 돌린다
        $_SESSION[self::SESSION_TIME_KEY] = time();

        return true;
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
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::SESSION_TIME_KEY]);

        return $this->generateToken();
    }

    /**
     * CSRF 토큰 삭제
     */
    public function clearToken(): void
    {
        $this->ensureSession();

        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::SESSION_TIME_KEY]);
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
