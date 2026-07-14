<?php

namespace Tests\Unit\Service\Auth;

use Mublo\Core\Crypto\PasswordHasher;
use Mublo\Core\Session\SessionInterface;
use Mublo\Infrastructure\Security\CsrfManager;
use Mublo\Entity\Member\Member;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Service\Auth\AuthService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * 로그인 성공 시 구식 해시 점진 재해싱 테스트
 *
 * cost/algo 설정 변경 후에도 기존 해시는 DB 에 그대로 남는다.
 * 로그인 성공 직후(평문 비밀번호가 있는 유일한 시점)에 needsRehash 를 검사해
 * 새 설정으로 조용히 교체하는 동작을 보증한다.
 */
class AuthServicePasswordRehashTest extends TestCase
{
    private MockObject $memberRepository;

    private function makeService(): AuthService
    {
        $this->memberRepository = $this->createMock(MemberRepository::class);

        return new AuthService(
            $this->createMock(SessionInterface::class),
            $this->memberRepository,
            new PasswordHasher(['algo' => PASSWORD_BCRYPT, 'cost' => 12]),
            $this->createStub(CsrfManager::class)
        );
    }

    private function stubMember(string $passwordHash): void
    {
        $member = $this->createMock(Member::class);
        $member->method('getMemberId')->willReturn(7);
        $member->method('getPassword')->willReturn($passwordHash);
        $member->method('isActive')->willReturn(true);

        $this->memberRepository->method('findByDomainAndUserId')->willReturn($member);
    }

    public function testOutdatedHashIsRehashedOnSuccessfulLogin(): void
    {
        $service = $this->makeService();
        // 과거 코드가 만들던 cost 10 해시
        $this->stubMember(password_hash('password123', PASSWORD_BCRYPT, ['cost' => 10]));

        $this->memberRepository->expects($this->once())
            ->method('updatePassword')
            ->with(7, $this->callback(function (string $newHash): bool {
                // 새 해시는 현재 설정(cost 12)이고, 같은 비밀번호를 검증한다
                return str_starts_with($newHash, '$2y$12$')
                    && password_verify('password123', $newHash);
            }));

        $result = $service->attempt(1, 'testuser', 'password123');

        $this->assertTrue($result->isSuccess());
    }

    public function testUpToDateHashIsNotRehashed(): void
    {
        $service = $this->makeService();
        $this->stubMember(password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]));

        $this->memberRepository->expects($this->never())->method('updatePassword');

        $result = $service->attempt(1, 'testuser', 'password123');

        $this->assertTrue($result->isSuccess());
    }

    public function testFailedLoginDoesNotRehash(): void
    {
        $service = $this->makeService();
        $this->stubMember(password_hash('password123', PASSWORD_BCRYPT, ['cost' => 10]));

        // 비밀번호 불일치 → 구식 해시라도 재해싱하지 않는다
        $this->memberRepository->expects($this->never())->method('updatePassword');

        $result = $service->attempt(1, 'testuser', 'wrong-password');

        $this->assertTrue($result->isFailure());
    }
}
