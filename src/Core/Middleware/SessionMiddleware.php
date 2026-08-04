<?php
declare(strict_types=1);

namespace Mublo\Core\Middleware;

use Mublo\Core\Http\Request;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\AbstractResponse;
use Mublo\Infrastructure\Session\SessionManager;

/**
 * Session Middleware
 *
 * 세션 시작 미들웨어
 * - 도메인별 세션 분리 (멀티테넌트)
 * - PG 콜백처럼 외부 origin에서 오는 요청은 session_start로 새 세션을 만들지 않도록
 *   addExcludePath()로 우회 가능. 외부 cross-site POST는 SameSite=Lax 정책상
 *   브라우저가 부모창 세션 쿠키를 보내지 않기 때문에, 응답에 Set-Cookie를 내보내면
 *   부모창의 기존 로그인 세션을 덮어쓰는 부작용이 발생한다.
 */
class SessionMiddleware implements MiddlewareInterface
{
    private SessionManager $session;

    /** @var string[] 경로 경계가 일치하면 세션 시작을 스킵 (prefix) */
    protected array $excludePaths = [];

    public function __construct(SessionManager $session)
    {
        $this->session = $session;
    }

    /**
     * 세션 시작 예외 경로 등록 (Plugin/Package에서 boot() 시 호출)
     *
     * CsrfMiddleware와 동일하게 경계 prefix로 매칭한다(부분 문자열 매칭의 오탐 방지).
     *
     * 주의: 세션을 스킵할 경로는 CsrfMiddleware 의 exclude 와 짝을 맞춰야 한다 —
     * 한쪽만 등록하면 CSRF 토큰 워밍(getToken)이 세션을 되살려 스킵 의도가 무효화된다.
     * cross-site POST 로 돌아오는 PG 콜백 경로가 그 예다 — 쿠키가 실려오지 않는데 세션을
     * 새로 시작하면, 응답의 Set-Cookie 가 부모창의 로그인 세션을 덮어쓴다.
     *
     * @param string $pathPrefix 경로 경계가 일치하면 세션 시작 스킵
     */
    public function addExcludePath(string $pathPrefix): void
    {
        $normalized = '/' . trim($pathPrefix, '/');
        if ($normalized !== '/') {
            $normalized = rtrim($normalized, '/');
        }

        $this->excludePaths[] = $normalized;
    }

    public function handle(Request $request, Context $context, callable $next): AbstractResponse
    {
        $path = $request->getPath();
        foreach ($this->excludePaths as $prefix) {
            if ($this->matchesExcludePath($path, $prefix)) {
                return $next($request, $context);
            }
        }

        // 도메인 ID로 세션 시작 (멀티테넌트 분리)
        $domainId = $context->getDomainId();
        $this->session->start($domainId);

        // 유휴 타임아웃 강제 + 슬라이딩 갱신 (세션 쿠키라 브라우저 종료 시 파기)
        $this->session->enforceIdleTimeout();

        $response = $next($request, $context);

        // 세션 잠금 조기 해제 (동시 요청 직렬화 방지)
        $this->session->close();

        return $response;
    }

    protected function matchesExcludePath(string $path, string $prefix): bool
    {
        if ($prefix === '/') {
            return $path === '/';
        }

        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }
}
