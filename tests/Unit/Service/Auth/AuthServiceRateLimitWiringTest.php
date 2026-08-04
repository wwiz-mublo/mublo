<?php

namespace Tests\Unit\Service\Auth;

use Mublo\Core\Crypto\PasswordHasher;
use Mublo\Core\Session\SessionInterface;
use Mublo\Entity\Member\Member;
use Mublo\Enum\Member\MemberStatus;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Security\CsrfManager;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Auth\LoginAttemptService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AuthService 와 LoginAttemptService 의 배선을 실제 로그인 흐름으로 검증한다.
 *
 * 기존 attempt() 테스트들은 IP 없이 호출해 레이트 리미터 경로를 건너뛴다.
 * 게이트가 시도를 먼저 기록하도록 바뀐 뒤로는 그 경로가 다음을 좌우한다.
 *
 *  - 실패를 반복하면 설정된 횟수에서 정확히 잠기는가
 *  - 로그인에 성공하면 쌓인 실패가 지워지는가
 *  - 비밀번호가 맞은 휴면·정지 계정이 상태 안내만 보고도 잠기지 않는가
 *
 * 마지막 항목이 이 변경에서 실제로 발견된 회귀다.
 */
class AuthServiceRateLimitWiringTest extends TestCase
{
    private const IP = '203.0.113.9';
    private const PASSWORD = 'password123';

    /** @var list<array{user_id: string, ip: string, success: int}> */
    private array $rows = [];

    private MockObject $memberRepository;

    private function makeService(int $maxPerUser, MemberStatus $status = MemberStatus::ACTIVE): AuthService
    {
        $this->rows = [];
        $this->memberRepository = $this->createMock(MemberRepository::class);

        $member = $this->createMock(Member::class);
        $member->method('getMemberId')->willReturn(7);
        $member->method('getPassword')->willReturn(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 12]));
        $member->method('isActive')->willReturn($status === MemberStatus::ACTIVE);
        $member->method('getStatus')->willReturn($status);
        $this->memberRepository->method('findByDomainAndUserId')->willReturn($member);

        return new AuthService(
            $this->createMock(SessionInterface::class),
            $this->memberRepository,
            new PasswordHasher(['algo' => PASSWORD_BCRYPT, 'cost' => 12]),
            $this->createStub(CsrfManager::class),
            null,
            $this->makeAttemptService($maxPerUser)
        );
    }

    private function makeAttemptService(int $maxPerUser): LoginAttemptService
    {
        $db = $this->createMock(Database::class);

        $db->method('insert')->willReturnCallback(function (string $sql, array $params): int {
            [, $userId, $ip, $success] = $params;
            $this->rows[] = ['user_id' => (string) $userId, 'ip' => (string) $ip, 'success' => (int) $success];
            return 1;
        });

        $db->method('selectOne')->willReturnCallback(function (string $sql, array $params): ?array {
            if (!str_contains($sql, 'COUNT(*)')) {
                return ['oldest' => null];
            }

            $failures = array_filter($this->rows, static fn (array $r): bool => $r['success'] === 0);

            if (str_contains($sql, 'user_id = ?')) {
                [, $userId, $ip] = $params;
                $n = count(array_filter(
                    $failures,
                    static fn (array $r): bool => $r['user_id'] === (string) $userId && $r['ip'] === (string) $ip
                ));
            } else {
                [$ip] = $params;
                $n = count(array_filter($failures, static fn (array $r): bool => $r['ip'] === (string) $ip));
            }

            return ['cnt' => $n];
        });

        // clearFailedAttempts 의 DELETE — 실패 행만 지운다
        $db->method('execute')->willReturnCallback(function (string $sql, array $params): int {
            if (!str_contains($sql, 'DELETE') || !str_contains($sql, 'is_successful = 0')) {
                return 0;
            }
            $before = count($this->rows);
            $this->rows = array_values(array_filter($this->rows, static fn (array $r): bool => $r['success'] !== 0));
            return $before - count($this->rows);
        });

        return new LoginAttemptService($db, [
            'max_attempts_per_user' => $maxPerUser,
            'max_attempts_per_ip' => 999,
            'cleanup_probability' => 0,
        ]);
    }

    private function failedAttemptCount(): int
    {
        return count(array_filter($this->rows, static fn (array $r): bool => $r['success'] === 0));
    }

    public function testWrongPasswordLocksOutAfterExactlyTheConfiguredAttempts(): void
    {
        $service = $this->makeService(3);

        for ($i = 1; $i <= 3; $i++) {
            $result = $service->attempt(1, 'testuser', 'wrong-password', self::IP);
            $this->assertStringContainsString('일치하지 않습니다', $result->getMessage(), "{$i}회째는 아직 잠기면 안 된다");
        }

        // 4회째부터 잠금 안내로 바뀐다
        $this->assertStringContainsString(
            '너무 많습니다',
            $service->attempt(1, 'testuser', 'wrong-password', self::IP)->getMessage()
        );
    }

    public function testCorrectPasswordStillWorksAfterSomeFailures(): void
    {
        $service = $this->makeService(3);

        $service->attempt(1, 'testuser', 'wrong-password', self::IP);
        $service->attempt(1, 'testuser', 'wrong-password', self::IP);

        $this->assertTrue($service->attempt(1, 'testuser', self::PASSWORD, self::IP)->isSuccess());
    }

    public function testSuccessfulLoginClearsAccumulatedFailures(): void
    {
        $service = $this->makeService(3);

        $service->attempt(1, 'testuser', 'wrong-password', self::IP);
        $service->attempt(1, 'testuser', 'wrong-password', self::IP);
        $service->attempt(1, 'testuser', self::PASSWORD, self::IP);

        $this->assertSame(0, $this->failedAttemptCount(), '성공하면 실패 기록이 남아 있으면 안 된다');
    }

    /**
     * 비밀번호가 맞은 휴면 계정은 상태 안내를 반복해서 봐도 잠기지 않아야 한다.
     *
     * 게이트가 시도를 먼저 실패로 기록하므로, 이 경로에서 정리하지 않으면 자기
     * 비밀번호를 정확히 아는 사람이 몇 번 만에 잠긴다. 휴면 계정은 국내 사이트에서
     * 흔한 상태라 실제로 발생했을 회귀다.
     */
    public function testDormantAccountWithCorrectPasswordNeverLocksOut(): void
    {
        $service = $this->makeService(3, MemberStatus::DORMANT);

        for ($i = 0; $i < 10; $i++) {
            $this->assertStringContainsString(
                '휴면',
                $service->attempt(1, 'testuser', self::PASSWORD, self::IP)->getMessage(),
                "{$i}회째에 잠금 안내로 바뀌었다"
            );
        }

        $this->assertSame(0, $this->failedAttemptCount());
    }

    /**
     * 반대로, 휴면 계정에 틀린 비밀번호를 던지는 것은 여전히 막혀야 한다.
     */
    public function testDormantAccountStillRateLimitsWrongPasswords(): void
    {
        $service = $this->makeService(3, MemberStatus::DORMANT);

        for ($i = 0; $i < 3; $i++) {
            $service->attempt(1, 'testuser', 'wrong-password', self::IP);
        }

        $this->assertStringContainsString(
            '너무 많습니다',
            $service->attempt(1, 'testuser', 'wrong-password', self::IP)->getMessage()
        );
    }
}
