<?php

namespace Tests\Unit\Infrastructure\Security;

use Mublo\Core\Session\SessionInterface;
use Mublo\Infrastructure\Security\CsrfManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CSRF 토큰 유효시간 — 슬라이딩 만료.
 *
 * 배경: security.csrf.token_ttl 은 설치 화면에서 값을 받아 설정 파일에 기록만 하고
 * 아무 데서도 읽지 않는 죽은 설정이었다. 사용자는 "1시간"을 골랐는데 실제 수명은
 * 세션 설정을 따랐다.
 *
 * 고정 만료로 구현하면 긴 글을 쓰는 도중 토큰이 죽어 제출 시 본문을 잃는다.
 * 게시판이 주력인 제품에서 그 사고를 만들지 않으려고 슬라이딩을 택했다 —
 * 검증에 성공할 때마다 시계를 다시 돌리고, 방치된 토큰만 만료시킨다.
 */
#[CoversClass(CsrfManager::class)]
class CsrfManagerSlidingExpiryTest extends TestCase
{
    private const KEY = '_csrf_token';
    private const TIME_KEY = '_csrf_token_at';

    protected function tearDown(): void
    {
        unset($_SESSION[self::KEY], $_SESSION[self::TIME_KEY]);
        parent::tearDown();
    }

    private function manager(int $ttl): CsrfManager
    {
        $session = $this->createMock(SessionInterface::class);

        return new CsrfManager($session, $ttl);
    }

    #[Test]
    public function testFreshTokenValidates(): void
    {
        $_SESSION[self::KEY] = 'token-abc';
        $_SESSION[self::TIME_KEY] = time();

        $this->assertTrue($this->manager(1800)->validateToken('token-abc'));
    }

    #[Test]
    public function testIdleTokenIsRejectedOnceTtlHasPassed(): void
    {
        $_SESSION[self::KEY] = 'token-abc';
        $_SESSION[self::TIME_KEY] = time() - 1801;

        $this->assertFalse($this->manager(1800)->validateToken('token-abc'));
    }

    #[Test]
    public function testExpiredTokenIsDiscardedSoTheNextRequestGetsAFreshOne(): void
    {
        $_SESSION[self::KEY] = 'token-abc';
        $_SESSION[self::TIME_KEY] = time() - 9999;

        $this->manager(1800)->validateToken('token-abc');

        // 남겨두면 다음 요청이 같은 죽은 토큰으로 또 실패한다
        $this->assertArrayNotHasKey(self::KEY, $_SESSION);
    }

    #[Test]
    public function testSuccessfulValidationRestartsTheIdleClock(): void
    {
        $_SESSION[self::KEY] = 'token-abc';
        $_SESSION[self::TIME_KEY] = time() - 1700; // 아직 만료 전

        $this->manager(1800)->validateToken('token-abc');

        // 슬라이딩 — 활동 중인 사용자는 계속 유효해야 한다
        $this->assertGreaterThan(time() - 5, $_SESSION[self::TIME_KEY]);
    }

    #[Test]
    public function testActiveUserIsNeverCutOffMidSession(): void
    {
        $_SESSION[self::KEY] = 'token-abc';
        $_SESSION[self::TIME_KEY] = time() - 1700;

        $manager = $this->manager(1800);

        // TTL 보다 짧은 간격으로 계속 활동하면 총 경과가 TTL 을 넘어도 유효하다
        $this->assertTrue($manager->validateToken('token-abc'));
        $_SESSION[self::TIME_KEY] = time() - 1700;
        $this->assertTrue($manager->validateToken('token-abc'));
    }

    #[Test]
    public function testExpiredTokenIsReplacedWhenTheNextPageAsksForOne(): void
    {
        $_SESSION[self::KEY] = 'dead-token';
        $_SESSION[self::TIME_KEY] = time() - 9999;

        $token = $this->manager(1800)->getToken();

        // 죽은 토큰을 화면에 심으면 제출 때 실패한다 — 새로 발급해야 한다
        $this->assertNotSame('dead-token', $token);
        $this->assertSame($token, $_SESSION[self::KEY]);
    }

    #[Test]
    public function testZeroTtlKeepsThePreviousBehaviourOfNoExpiry(): void
    {
        $_SESSION[self::KEY] = 'token-abc';
        $_SESSION[self::TIME_KEY] = time() - 86400 * 30;

        $this->assertTrue($this->manager(0)->validateToken('token-abc'));
    }

    #[Test]
    public function testTokenIssuedBeforeThisFeatureIsNotTreatedAsExpired(): void
    {
        // 업데이트 직후: 시각 없이 발급된 토큰이 세션에 남아 있다.
        // 만료로 처리하면 열려 있던 폼이 한꺼번에 깨진다.
        $_SESSION[self::KEY] = 'legacy-token';

        $this->assertTrue($this->manager(1800)->validateToken('legacy-token'));
        $this->assertIsInt($_SESSION[self::TIME_KEY], '검증 성공 시 시각이 붙어야 한다');
    }

    #[Test]
    public function testWrongTokenStillFailsWithinTtl(): void
    {
        $_SESSION[self::KEY] = 'token-abc';
        $_SESSION[self::TIME_KEY] = time();

        $this->assertFalse($this->manager(1800)->validateToken('token-xyz'));
    }
}
