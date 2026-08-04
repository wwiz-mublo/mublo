<?php

namespace Tests\Unit\Infrastructure\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Mublo\Infrastructure\Security\CsrfManager;
use Mublo\Core\Session\SessionInterface;

/**
 * F-7 회귀: 렌더 단계(세션 close 이후)에서 getToken()이 세션을 재시작하지 않아야 한다.
 *
 * session_write_close() 후에도 $_SESSION 슈퍼전역은 유지되므로, 토큰이 이미 있으면
 * 세션을 (재)시작하지 않고 그대로 읽어야 한다. 재시작하면 플래시 이중 aging + 잠금 재획득이 발생한다.
 */
#[CoversClass(CsrfManager::class)]
class CsrfManagerTest extends TestCase
{
    private const KEY = '_csrf_token';

    protected function tearDown(): void
    {
        unset($_SESSION[self::KEY]);
        parent::tearDown();
    }

    #[Test]
    public function testGetTokenReadsExistingTokenWithoutRestartingSession(): void
    {
        $_SESSION[self::KEY] = 'existing-token';

        $session = $this->createMock(SessionInterface::class);
        // 핵심: 토큰이 이미 있으면 세션을 다시 시작하지 않는다.
        $session->expects($this->never())->method('start');

        $csrf = new CsrfManager($session);

        $this->assertSame('existing-token', $csrf->getToken());
    }

    #[Test]
    public function testGetTokenStartsSessionWhenTokenMissing(): void
    {
        unset($_SESSION[self::KEY]);

        $session = $this->createMock(SessionInterface::class);
        // 토큰이 없으면 세션을 시작해 생성 경로로 진입해야 한다.
        $session->expects($this->atLeastOnce())->method('start');

        $csrf = new CsrfManager($session);
        $token = $csrf->getToken();

        $this->assertNotSame('', $token);
    }
}
