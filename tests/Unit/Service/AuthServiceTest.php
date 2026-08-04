<?php

namespace Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Mublo\Service\Auth\AuthService;
use Mublo\Core\Crypto\PasswordHasher;
use Mublo\Core\Session\SessionInterface;
use Mublo\Infrastructure\Security\CsrfManager;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Entity\Member\Member;
use Mublo\Enum\Member\MemberStatus;

/**
 * AuthServiceTest
 *
 * 인증 서비스 테스트
 * - 로그인 성공/실패
 * - 계정 상태 검증
 * - 비밀번호 검증
 */
class AuthServiceTest extends TestCase
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

    /**
     * 정상 로그인 테스트
     */
    public function testLoginWithValidCredentials(): void
    {
        // Given: 유효한 사용자
        $member = $this->createMemberMock(1, 'testuser', 'active');
        $hashedPassword = password_hash('password123', PASSWORD_BCRYPT);
        $member->expects($this->any())
            ->method('getPassword')
            ->willReturn($hashedPassword);
        $member->expects($this->any())
            ->method('isActive')
            ->willReturn(true);

        $this->memberRepositoryMock->expects($this->once())
            ->method('findByDomainAndUserId')
            ->with(1, 'testuser')
            ->willReturn($member);

        // When: 로그인 시도
        $result = $this->authService->attempt(1, 'testuser', 'password123');

        // Then: 로그인 성공
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('로그인 성공', $result->getMessage());
    }

    /**
     * 잘못된 비밀번호 테스트
     */
    public function testLoginWithInvalidPassword(): void
    {
        // Given: 유효한 사용자이지만 비밀번호 오류
        $member = $this->createMemberMock(1, 'testuser', 'active');
        $hashedPassword = password_hash('password123', PASSWORD_BCRYPT);
        $member->expects($this->any())
            ->method('getPassword')
            ->willReturn($hashedPassword);
        $member->expects($this->any())
            ->method('isActive')
            ->willReturn(true);

        $this->memberRepositoryMock->expects($this->once())
            ->method('findByDomainAndUserId')
            ->with(1, 'testuser')
            ->willReturn($member);

        // When: 잘못된 비밀번호로 로그인
        $result = $this->authService->attempt(1, 'testuser', 'wrongpassword');

        // Then: 로그인 실패
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('일치하지 않습니다', $result->getMessage());
    }

    /**
     * 없는 사용자 테스트
     */
    public function testLoginWithNonExistentUser(): void
    {
        // Given: 없는 사용자
        $this->memberRepositoryMock->expects($this->once())
            ->method('findByDomainAndUserId')
            ->with(1, 'nonexistent')
            ->willReturn(null);

        // When: 존재하지 않는 사용자로 로그인
        $result = $this->authService->attempt(1, 'nonexistent', 'password123');

        // Then: 로그인 실패
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('일치하지 않습니다', $result->getMessage());
    }

    /**
     * 비활성 계정 테스트
     */
    public function testLoginWithInactiveAccount(): void
    {
        // Given: 비활성 계정 (올바른 비밀번호 보유)
        $member = $this->createMemberMock(1, 'testuser', 'inactive');
        $member->expects($this->any())
            ->method('getPassword')
            ->willReturn(password_hash('password123', PASSWORD_BCRYPT));
        $member->expects($this->any())
            ->method('isActive')
            ->willReturn(false);

        $this->memberRepositoryMock->expects($this->once())
            ->method('findByDomainAndUserId')
            ->with(1, 'testuser')
            ->willReturn($member);

        // When: 올바른 비밀번호로 로그인 (상태 안내는 인증 후에만 노출)
        $result = $this->authService->attempt(1, 'testuser', 'password123');

        // Then: 로그인 실패
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('비활성화된', $result->getMessage());
    }

    /**
     * 휴면 계정 테스트
     */
    public function testLoginWithDormantAccount(): void
    {
        // Given: 휴면 계정 (올바른 비밀번호 보유)
        $member = $this->createMemberMock(1, 'testuser', 'dormant');
        $member->expects($this->any())
            ->method('getPassword')
            ->willReturn(password_hash('password123', PASSWORD_BCRYPT));
        $member->expects($this->any())
            ->method('isActive')
            ->willReturn(false);

        $this->memberRepositoryMock->expects($this->once())
            ->method('findByDomainAndUserId')
            ->with(1, 'testuser')
            ->willReturn($member);

        // When: 올바른 비밀번호로 로그인
        $result = $this->authService->attempt(1, 'testuser', 'password123');

        // Then: 로그인 실패
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('휴면', $result->getMessage());
    }

    /**
     * 정지 계정 테스트
     */
    public function testLoginWithBlockedAccount(): void
    {
        // Given: 정지 계정 (올바른 비밀번호 보유)
        $member = $this->createMemberMock(1, 'testuser', 'blocked');
        $member->expects($this->any())
            ->method('getPassword')
            ->willReturn(password_hash('password123', PASSWORD_BCRYPT));
        $member->expects($this->any())
            ->method('isActive')
            ->willReturn(false);

        $this->memberRepositoryMock->expects($this->once())
            ->method('findByDomainAndUserId')
            ->with(1, 'testuser')
            ->willReturn($member);

        // When: 올바른 비밀번호로 로그인
        $result = $this->authService->attempt(1, 'testuser', 'password123');

        // Then: 로그인 실패
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('정지', $result->getMessage());
    }

    /**
     * 계정 상태 열거 방지: 잘못된 비밀번호면 비활성/휴면/정지 계정도
     * 상태를 노출하지 않고 일반 실패 메시지를 반환해야 한다.
     */
    public function testInactiveAccountWithWrongPasswordDoesNotLeakStatus(): void
    {
        $member = $this->createMemberMock(1, 'testuser', 'dormant');
        $member->expects($this->any())
            ->method('getPassword')
            ->willReturn(password_hash('password123', PASSWORD_BCRYPT));
        $member->expects($this->any())
            ->method('isActive')
            ->willReturn(false);

        $this->memberRepositoryMock->expects($this->once())
            ->method('findByDomainAndUserId')
            ->with(1, 'testuser')
            ->willReturn($member);

        // When: 잘못된 비밀번호로 시도
        $result = $this->authService->attempt(1, 'testuser', 'wrong-password');

        // Then: 상태('휴면')를 노출하지 않고 일반 메시지 반환
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('일치하지 않습니다', $result->getMessage());
        $this->assertStringNotContainsString('휴면', $result->getMessage());
    }

    /**
     * 로그인 후 세션 업데이트 테스트
     */
    public function testLoginUpdatesLastLoginTime(): void
    {
        // Given: 유효한 사용자
        $member = $this->createMemberMock(1, 'testuser', 'active');
        $hashedPassword = password_hash('password123', PASSWORD_BCRYPT);
        $member->expects($this->any())
            ->method('getPassword')
            ->willReturn($hashedPassword);
        $member->expects($this->any())
            ->method('isActive')
            ->willReturn(true);
        $member->expects($this->any())
            ->method('getMemberId')
            ->willReturn(1);

        $this->memberRepositoryMock->expects($this->once())
            ->method('findByDomainAndUserId')
            ->with(1, 'testuser')
            ->willReturn($member);

        // updateLastLogin 호출 검증
        $this->memberRepositoryMock->expects($this->once())
            ->method('updateLastLogin')
            ->with(1);

        // When: 로그인
        $this->authService->attempt(1, 'testuser', 'password123');

        // Then: (Mock을 통해 검증)
    }

    /**
     * Helper: Mock Member 객체 생성
     */
    private function createMemberMock(int $memberId, string $userId, string $status): MockObject
    {
        $member = $this->createMock(Member::class);
        $member->expects($this->any())
            ->method('getMemberId')
            ->willReturn($memberId);
        $member->expects($this->any())
            ->method('getUserId')
            ->willReturn($userId);
        $member->expects($this->any())
            ->method('getStatus')
            ->willReturn(MemberStatus::from($status));
        $member->expects($this->any())
            ->method('toSafeArray')
            ->willReturn([
                'member_id' => $memberId,
                'user_id' => $userId,
                'status' => $status,
            ]);

        return $member;
    }
}
