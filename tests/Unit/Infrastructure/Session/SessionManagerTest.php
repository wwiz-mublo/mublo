<?php

namespace Tests\Unit\Infrastructure\Session;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Mublo\Infrastructure\Maintenance\DailyStorageCleanup;
use Mublo\Infrastructure\Session\SessionManager;

/**
 * SessionManagerTest
 *
 * SessionManager 단위 테스트
 * - getDriver() - 기본값 및 설정
 * - getDomainId() / setDomainId()
 * - set / get / has / remove / all
 * - flash / getFlash
 *
 * 주의: PHP 세션은 CLI 환경에서도 동작하지만,
 *       테스트 간 상태 격리를 위해 tearDown에서 세션을 초기화합니다.
 */
class SessionManagerTest extends TestCase
{
    private SessionManager $sessionManager;

    protected function setUp(): void
    {
        parent::setUp();

        // 세션이 이미 시작된 경우 초기화
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }

        $this->sessionManager = new SessionManager();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // 테스트 후 세션 상태 정리
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    // =========================================================================
    // 초기 상태
    // =========================================================================

    public function testGetDriverReturnsDefaultFile(): void
    {
        // 설정 파일 없을 때 기본값은 'file'
        $this->assertEquals('file', $this->sessionManager->getDriver());
    }

    public function testGetDomainIdReturnsNullByDefault(): void
    {
        $this->assertNull($this->sessionManager->getDomainId());
    }

    public function testSetDomainId(): void
    {
        $this->sessionManager->setDomainId(5);
        $this->assertEquals(5, $this->sessionManager->getDomainId());
    }

    public function testSetDomainIdToNull(): void
    {
        $this->sessionManager->setDomainId(5);
        $this->sessionManager->setDomainId(null);
        $this->assertNull($this->sessionManager->getDomainId());
    }

    // =========================================================================
    // set / get / has / remove
    // =========================================================================

    public function testSetAndGet(): void
    {
        $this->sessionManager->set('username', 'testuser');
        $this->assertEquals('testuser', $this->sessionManager->get('username'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $this->assertNull($this->sessionManager->get('nonexistent'));
        $this->assertEquals('default', $this->sessionManager->get('nonexistent', 'default'));
    }

    public function testHasReturnsTrueForExistingKey(): void
    {
        $this->sessionManager->set('test_key', 'test_value');
        $this->assertTrue($this->sessionManager->has('test_key'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $this->assertFalse($this->sessionManager->has('nonexistent_key'));
    }

    public function testRemoveDeletesKey(): void
    {
        $this->sessionManager->set('to_remove', 'value');
        $this->assertTrue($this->sessionManager->has('to_remove'));

        $this->sessionManager->remove('to_remove');

        $this->assertFalse($this->sessionManager->has('to_remove'));
        $this->assertNull($this->sessionManager->get('to_remove'));
    }

    public function testSetOverwritesExistingKey(): void
    {
        $this->sessionManager->set('key', 'original');
        $this->sessionManager->set('key', 'updated');
        $this->assertEquals('updated', $this->sessionManager->get('key'));
    }

    public function testSetAndGetArray(): void
    {
        $data = ['user_id' => 1, 'permissions' => ['read', 'write']];
        $this->sessionManager->set('user_data', $data);

        $this->assertEquals($data, $this->sessionManager->get('user_data'));
    }

    // =========================================================================
    // all()
    // =========================================================================

    public function testAllReturnsAllSessionData(): void
    {
        $this->sessionManager->set('key1', 'value1');
        $this->sessionManager->set('key2', 'value2');

        $all = $this->sessionManager->all();

        $this->assertArrayHasKey('key1', $all);
        $this->assertArrayHasKey('key2', $all);
        $this->assertEquals('value1', $all['key1']);
        $this->assertEquals('value2', $all['key2']);
    }

    // =========================================================================
    // flash 메시지
    // =========================================================================

    public function testFlashAndGetFlash(): void
    {
        // Given: 플래시 메시지 저장
        $this->sessionManager->flash('success', '저장되었습니다.');

        // When: 같은 요청에서 조회 (new 상태)
        $message = $this->sessionManager->getFlash('success');

        // Then
        $this->assertEquals('저장되었습니다.', $message);
    }

    public function testGetFlashReturnsDefaultForMissingKey(): void
    {
        $this->assertNull($this->sessionManager->getFlash('nonexistent'));
        $this->assertEquals('fallback', $this->sessionManager->getFlash('nonexistent', 'fallback'));
    }

    public function testFlashCanStoreDifferentTypes(): void
    {
        $this->sessionManager->flash('string_msg', 'Hello');
        $this->sessionManager->flash('array_msg', ['key' => 'value']);
        $this->sessionManager->flash('int_msg', 42);

        $this->assertEquals('Hello', $this->sessionManager->getFlash('string_msg'));
        $this->assertEquals(['key' => 'value'], $this->sessionManager->getFlash('array_msg'));
        $this->assertEquals(42, $this->sessionManager->getFlash('int_msg'));
    }

    // =========================================================================
    // getId
    // =========================================================================

    public function testGetIdReturnsNonEmptyString(): void
    {
        $id = $this->sessionManager->getId();
        $this->assertIsString($id);
        $this->assertNotEmpty($id);
    }

    // =========================================================================
    // 다중 키 관리
    // =========================================================================

    public function testMultipleKeysAreManagedIndependently(): void
    {
        $this->sessionManager->set('a', 1);
        $this->sessionManager->set('b', 2);
        $this->sessionManager->set('c', 3);

        $this->sessionManager->remove('b');

        $this->assertEquals(1, $this->sessionManager->get('a'));
        $this->assertFalse($this->sessionManager->has('b'));
        $this->assertEquals(3, $this->sessionManager->get('c'));
    }

    // =========================================================================
    // Secure 쿠키 자동화 (HTTPS 감지)
    // =========================================================================

    public function testSecureCookieEnabledOnHttps(): void
    {
        $prev = $_SERVER['HTTPS'] ?? null;
        $_SERVER['HTTPS'] = 'on';
        try {
            (new SessionManager())->start(1);
            $this->assertTrue(session_get_cookie_params()['secure']);
        } finally {
            if ($prev === null) {
                unset($_SERVER['HTTPS']);
            } else {
                $_SERVER['HTTPS'] = $prev;
            }
        }
    }

    public function testSecureCookieEnabledBehindHttpsProxy(): void
    {
        $prevHttps = $_SERVER['HTTPS'] ?? null;
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        try {
            (new SessionManager())->start(1);
            $this->assertTrue(session_get_cookie_params()['secure']);
        } finally {
            unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
            if ($prevHttps !== null) {
                $_SERVER['HTTPS'] = $prevHttps;
            }
        }
    }

    public function testSecureCookieDisabledOnPlainHttp(): void
    {
        $prevHttps = $_SERVER['HTTPS'] ?? null;
        $prevPort = $_SERVER['SERVER_PORT'] ?? null;
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['SERVER_PORT'] = 80;
        try {
            // 기본 config(cookie_secure=false) + 평문 HTTP → secure=false (로컬 개발 정상)
            (new SessionManager())->start(1);
            $this->assertFalse(session_get_cookie_params()['secure']);
        } finally {
            if ($prevHttps !== null) {
                $_SERVER['HTTPS'] = $prevHttps;
            }
            if ($prevPort !== null) {
                $_SERVER['SERVER_PORT'] = $prevPort;
            } else {
                unset($_SERVER['SERVER_PORT']);
            }
        }
    }

    public function testManagedFileCleanupDisablesRandomPhpGarbageCollection(): void
    {
        $previousProbability = ini_get('session.gc_probability');
        $noon = new DateTimeImmutable('2026-07-27 12:00:00', new DateTimeZone('Asia/Seoul'));
        $cleanup = new DailyStorageCleanup(
            sys_get_temp_dir() . '/mublo-session-manager-cleanup-test',
            static fn (): DateTimeImmutable => $noon
        );
        $manager = new SessionManager($cleanup);

        try {
            $manager->start(1);
            $this->assertSame('0', ini_get('session.gc_probability'));
        } finally {
            $manager->destroy();
            if ($previousProbability !== false) {
                ini_set('session.gc_probability', $previousProbability);
            }
        }
    }
}
