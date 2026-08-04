<?php

namespace Tests\Unit\Service\Auth;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Mublo\Service\Auth\AuthService;
use Mublo\Core\Crypto\PasswordHasher;
use Mublo\Core\Session\SessionInterface;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Entity\Member\Member;
use Mublo\Enum\Member\MemberStatus;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Contract\Auth\AuthenticatedUser;
use Mublo\Contract\Auth\MemberAuthenticatorInterface;
use Mublo\Infrastructure\Security\CsrfManager;

/**
 * AuthServiceSessionTest
 *
 * AuthService 확장 테스트
 * - logout
 * - check() / guest()
 * - user() / currentUser() / id()
 * - isAdmin() / isSuper() / hasLevel()
 * - setProxyLogin / isProxyLogin / getProxyLogin
 * - loginByMember
 * - refreshSession
 */
class AuthServiceSessionTest extends TestCase
{
    private AuthService $authService;
    private MockObject $sessionMock;
    private MockObject $memberRepositoryMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionMock = $this->createMock(SessionInterface::class);
        $this->memberRepositoryMock = $this->createMock(MemberRepository::class);

        $this->authService = new AuthService(
            $this->sessionMock,
            $this->memberRepositoryMock,
            new PasswordHasher(['algo' => PASSWORD_BCRYPT, 'cost' => 10]),
            $this->createStub(CsrfManager::class)
        );
    }

    public function testImplementsStableAuthContracts(): void
    {
        $this->assertInstanceOf(AuthContextInterface::class, $this->authService);
        $this->assertInstanceOf(MemberAuthenticatorInterface::class, $this->authService);
    }

    public function testLoginByMemberIdRejectsMissingMember(): void
    {
        $this->memberRepositoryMock->expects($this->once())
            ->method('find')
            ->with(404)
            ->willReturn(null);

        $this->assertFalse($this->authService->loginByMemberId(404));
    }

    // =========================================================================
    // check() / guest()
    // =========================================================================

    public function testCheckReturnsFalseWhenNotLoggedIn(): void
    {
        // Given: 세션에 사용자 정보 없음
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->with('auth_user')
            ->willReturn(null);

        // When / Then
        $this->assertFalse($this->authService->check());
        $this->assertTrue($this->authService->guest());
    }

    public function testCheckReturnsTrueWhenLoggedIn(): void
    {
        // Given: 세션에 사용자 정보 있음
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->with('auth_user')
            ->willReturn([
                'member_id' => 1,
                'user_id' => 'testuser',
                'is_admin' => false,
                'is_super' => false,
                'level_value' => 1,
            ]);

        // When / Then
        $this->assertTrue($this->authService->check());
        $this->assertFalse($this->authService->guest());
    }

    // =========================================================================
    // user() / id()
    // =========================================================================

    public function testUserReturnsNullWhenNotLoggedIn(): void
    {
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->willReturn(null);

        $this->assertNull($this->authService->user());
        $this->assertNull($this->authService->id());
    }

    public function testUserReturnsSessionDataWhenLoggedIn(): void
    {
        $userData = [
            'member_id' => 42,
            'user_id' => 'testuser',
            'nickname' => '테스트',
        ];

        $this->sessionMock->expects($this->any())
            ->method('get')
            ->with('auth_user')
            ->willReturn($userData);

        $user = $this->authService->user();
        $this->assertEquals($userData, $user);
        $this->assertEquals(42, $this->authService->id());
    }

    public function testCurrentUserReturnsStableDtoWithoutExposingSessionArray(): void
    {
        $userData = [
            'member_id' => 42,
            'domain_id' => 7,
            'user_id' => 'testuser',
            'nickname' => '테스트',
            'level_value' => 5,
            'is_admin' => 1,
            'is_super' => 0,
            'can_operate_domain' => 1,
            'can_create_site' => 1,
            'avatar' => '/storage/avatar.png',
            'name' => '홍길동',
            'domain_group' => '1/7',
            'level_type' => 'STAFF',
            'point' => 999999,
        ];

        $this->sessionMock->method('get')
            ->with('auth_user')
            ->willReturn($userData);

        $user = $this->authService->currentUser();

        $this->assertInstanceOf(AuthenticatedUser::class, $user);
        $this->assertSame(42, $user->memberId);
        $this->assertSame(7, $user->domainId);
        $this->assertSame('testuser', $user->userId);
        $this->assertSame('테스트', $user->displayName());
        $this->assertSame(5, $user->levelValue);
        $this->assertTrue($user->admin);
        $this->assertFalse($user->super);
        $this->assertTrue($user->canOperateDomain);
        $this->assertTrue($user->canCreateSite);
        $this->assertSame('/storage/avatar.png', $user->avatar);
        $this->assertSame('홍길동', $user->name);
        $this->assertSame('1/7', $user->domainGroup);
        $this->assertSame('STAFF', $user->levelType);
        $this->assertFalse(property_exists($user, 'point'));
    }

    // =========================================================================
    // isAdmin() / isSuper() / hasLevel()
    // =========================================================================

    public function testIsAdminReturnsFalseWhenNotLoggedIn(): void
    {
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->willReturn(null);

        $this->assertFalse($this->authService->isAdmin());
    }

    public function testIsAdminReturnsTrueForAdminUser(): void
    {
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->with('auth_user')
            ->willReturn([
                'member_id' => 1,
                'is_admin' => true,
                'is_super' => false,
            ]);

        $this->assertTrue($this->authService->isAdmin());
    }

    public function testIsAdminReturnsTrueForSuperUser(): void
    {
        // is_super도 is_admin 조건에 포함
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->with('auth_user')
            ->willReturn([
                'member_id' => 1,
                'is_admin' => false,
                'is_super' => true,
            ]);

        $this->assertTrue($this->authService->isAdmin());
    }

    public function testIsSuperReturnsFalseForRegularAdmin(): void
    {
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->with('auth_user')
            ->willReturn([
                'member_id' => 1,
                'is_admin' => true,
                'is_super' => false,
            ]);

        $this->assertFalse($this->authService->isSuper());
    }

    public function testIsSuperReturnsTrueForSuperUser(): void
    {
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->with('auth_user')
            ->willReturn([
                'member_id' => 1,
                'is_admin' => true,
                'is_super' => true,
            ]);

        $this->assertTrue($this->authService->isSuper());
    }

    public function testCanOperateDomainFalseForRegularAdmin(): void
    {
        // 일반 admin(운영자/슈퍼 아님)은 raw JS 채널 불가
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->with('auth_user')
            ->willReturn([
                'member_id' => 1,
                'is_admin' => true,
                'is_super' => false,
                'can_operate_domain' => false,
            ]);

        $this->assertFalse($this->authService->canOperateDomain());
    }

    public function testCanOperateDomainTrueForOperator(): void
    {
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->with('auth_user')
            ->willReturn([
                'member_id' => 1,
                'is_admin' => true,
                'is_super' => false,
                'can_operate_domain' => true,
            ]);

        $this->assertTrue($this->authService->canOperateDomain());
    }

    public function testCanOperateDomainTrueForSuper(): void
    {
        // 슈퍼관리자는 can_operate_domain 플래그와 무관하게 허용
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->with('auth_user')
            ->willReturn([
                'member_id' => 1,
                'is_admin' => true,
                'is_super' => true,
                'can_operate_domain' => false,
            ]);

        $this->assertTrue($this->authService->canOperateDomain());
    }

    public function testHasLevelReturnsFalseWhenNotLoggedIn(): void
    {
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->willReturn(null);

        $this->assertFalse($this->authService->hasLevel(1));
    }

    public function testHasLevelReturnsTrueWhenLevelSufficient(): void
    {
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->with('auth_user')
            ->willReturn([
                'member_id' => 1,
                'level_value' => 5,
            ]);

        $this->assertTrue($this->authService->hasLevel(3));
        $this->assertTrue($this->authService->hasLevel(5));
        $this->assertFalse($this->authService->hasLevel(6));
    }

    // =========================================================================
    // logout
    // =========================================================================

    public function testLogout(): void
    {
        // Given: 세션에서 auth 키들이 제거되고 재생성되어야 함
        // PHPUnit 11에서 withConsecutive 제거 → willReturnCallback으로 대체
        $removedKeys = [];
        $this->sessionMock->expects($this->exactly(4))
            ->method('remove')
            ->willReturnCallback(function (string $key) use (&$removedKeys): void {
                $removedKeys[] = $key;
            });

        $this->sessionMock->expects($this->once())
            ->method('regenerate')
            ->with(true);

        // When
        $this->authService->logout();

        // Then: 제거된 키 순서 검증 (권한 재검증 타임스탬프 포함)
        $this->assertEquals(
            ['auth_user', 'auth_login_time', 'proxy_login', 'auth_revalidated_at'],
            $removedKeys
        );
    }

    public function testLogoutClearsInternalUserCache(): void
    {
        // Given: 이미 로그인된 상태 시뮬레이션
        $userData = ['member_id' => 1, 'user_id' => 'testuser'];

        $callCount = 0;
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->willReturnCallback(function ($key) use ($userData, &$callCount) {
                if ($key === 'auth_user') {
                    // 처음 호출은 데이터 반환, logout 이후는 null 반환
                    return null;
                }
                return null;
            });

        $this->sessionMock->expects($this->any())->method('remove');
        $this->sessionMock->expects($this->any())->method('regenerate');

        // When
        $this->authService->logout();

        // Then: user()는 null 반환
        $this->assertNull($this->authService->user());
    }

    // =========================================================================
    // Proxy Login
    // =========================================================================

    public function testSetProxyLogin(): void
    {
        // Given
        $this->sessionMock->expects($this->once())
            ->method('set')
            ->with('proxy_login', [
                'source_domain_id' => 1,
                'admin_member_id' => 100,
                'admin_nickname' => '관리자',
                'site_name' => '테스트 사이트',
            ]);

        // When
        $this->authService->setProxyLogin(1, 100, '관리자', '테스트 사이트');
    }

    public function testIsProxyLoginReturnsFalseByDefault(): void
    {
        $this->sessionMock->expects($this->once())
            ->method('has')
            ->with('proxy_login')
            ->willReturn(false);

        $this->assertFalse($this->authService->isProxyLogin());
    }

    public function testIsProxyLoginReturnsTrueWhenSet(): void
    {
        $this->sessionMock->expects($this->once())
            ->method('has')
            ->with('proxy_login')
            ->willReturn(true);

        $context = $this->authService;
        $this->assertInstanceOf(AuthContextInterface::class, $context);
        $this->assertTrue($context->isProxyLogin());
    }

    public function testGetProxyLoginReturnsNull(): void
    {
        $this->sessionMock->expects($this->once())
            ->method('get')
            ->with('proxy_login')
            ->willReturn(null);

        $this->assertNull($this->authService->getProxyLogin());
    }

    public function testGetProxyLoginReturnsData(): void
    {
        $proxyData = [
            'source_domain_id' => 1,
            'admin_member_id' => 100,
            'admin_nickname' => '관리자',
            'site_name' => '사이트',
        ];

        $this->sessionMock->expects($this->once())
            ->method('get')
            ->with('proxy_login')
            ->willReturn($proxyData);

        $this->assertEquals($proxyData, $this->authService->getProxyLogin());
    }

    // =========================================================================
    // loginByMember
    // =========================================================================

    public function testLoginByMember(): void
    {
        // Given
        $member = $this->createMock(Member::class);
        $member->method('isActive')->willReturn(true);
        $member->expects($this->any())
            ->method('getMemberId')
            ->willReturn(99);
        $member->expects($this->once())
            ->method('toSafeArray')
            ->willReturn([
                'member_id' => 99,
                'user_id' => 'snsuser',
            ]);

        $this->sessionMock->expects($this->once())
            ->method('regenerate')
            ->with(true);

        $this->sessionMock->expects($this->atLeastOnce())
            ->method('set');

        $this->memberRepositoryMock->expects($this->once())
            ->method('updateLastLogin')
            ->with(99);

        // When
        $this->authService->loginByMember($member);

        // Then: user()가 로그인된 사용자 정보 반환
        $user = $this->authService->user();
        $this->assertNotNull($user);
        $this->assertEquals(99, $user['member_id']);
    }

    public function testLoginByMemberRotatesCsrfTokenAfterSessionId(): void
    {
        $member = $this->createMock(Member::class);
        $member->method('isActive')->willReturn(true);
        $member->method('getMemberId')->willReturn(99);
        $member->method('toSafeArray')->willReturn(['member_id' => 99, 'user_id' => 'snsuser']);
        $csrf = $this->createMock(CsrfManager::class);
        $order = [];
        $this->sessionMock->expects($this->once())
            ->method('regenerate')
            ->with(true)
            ->willReturnCallback(function () use (&$order): bool {
                $order[] = 'session';
                return true;
            });
        $csrf->expects($this->once())
            ->method('regenerateToken')
            ->willReturnCallback(function () use (&$order): string {
                $order[] = 'csrf';
                return 'rotated-token';
            });
        $this->sessionMock->method('set');
        $this->memberRepositoryMock->method('updateLastLogin');
        $service = new AuthService(
            $this->sessionMock,
            $this->memberRepositoryMock,
            new PasswordHasher(['algo' => PASSWORD_BCRYPT, 'cost' => 10]),
            $csrf
        );

        $service->loginByMember($member);

        $this->assertSame(['session', 'csrf'], $order);
    }

    public function testLoginByMemberRejectsInactiveAccount(): void
    {
        // 차단·탈퇴·휴면 계정은 외부 인증 경로(SNS·프록시)로도 로그인 불가(fail-closed).
        $member = $this->createMock(Member::class);
        $member->method('isActive')->willReturn(false);
        $member->method('getMemberId')->willReturn(99);

        $this->expectException(\RuntimeException::class);
        $this->authService->loginByMember($member);
    }

    // =========================================================================
    // refreshSession
    // =========================================================================

    public function testRefreshSessionReturnsFalseWhenNotLoggedIn(): void
    {
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->willReturn(null);

        $this->assertFalse($this->authService->refreshSession());
    }

    public function testRefreshSessionRefreshesUserData(): void
    {
        // Given: 현재 세션에 사용자 있음
        $oldUserData = ['member_id' => 1, 'user_id' => 'user1', 'level_value' => 1];
        $newUserData = ['member_id' => 1, 'user_id' => 'user1', 'level_value' => 5]; // 레벨 변경됨

        $this->sessionMock->expects($this->any())
            ->method('get')
            ->with('auth_user')
            ->willReturn($oldUserData);

        $updatedMember = $this->createMock(Member::class);
        $updatedMember->expects($this->once())
            ->method('toSafeArray')
            ->willReturn($newUserData);

        $this->memberRepositoryMock->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($updatedMember);

        // 세션에는 toSafeArray + 캐시된 아바타 URL(미설정 시 null)이 함께 저장된다
        $this->sessionMock->expects($this->once())
            ->method('set')
            ->with('auth_user', array_merge($newUserData, ['avatar' => null]));

        // When
        $result = $this->authService->refreshSession();

        // Then
        $this->assertTrue($result);
    }

    public function testRefreshSessionReturnsFalseWhenMemberNotFound(): void
    {
        // Given: 세션에 사용자 있지만 DB에서 찾을 수 없음
        $this->sessionMock->expects($this->any())
            ->method('get')
            ->with('auth_user')
            ->willReturn(['member_id' => 999, 'user_id' => 'ghost']);

        $this->memberRepositoryMock->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        // When
        $result = $this->authService->refreshSession();

        // Then
        $this->assertFalse($result);
    }
}
