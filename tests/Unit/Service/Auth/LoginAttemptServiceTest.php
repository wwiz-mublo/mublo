<?php

namespace Tests\Unit\Service\Auth;

use PHPUnit\Framework\TestCase;
use Mublo\Service\Auth\LoginAttemptService;
use Mublo\Infrastructure\Database\Database;

/**
 * LoginAttemptServiceTest
 *
 * 로그인 시도 Rate Limiting — 잠금이 (계정, IP) 단위임을 고정한다.
 * 핵심 회귀: 공격자가 임의 IP로 피해자 계정에 실패를 쌓아도, 다른 IP의
 * 정상 사용자는 잠기지 않아야 한다(계정 단독 집계였다면 잠겼을 상황).
 */
class LoginAttemptServiceTest extends TestCase
{
    private const DOMAIN = 10;

    /** @var list<array{domain_id:int, user_id:string, ip:string}> */
    private array $failures = [];

    private function makeService(array $config = []): LoginAttemptService
    {
        $db = $this->createMock(Database::class);

        $db->method('insert')->willReturnCallback(function (string $sql, array $params): int {
            // INSERT ... (domain_id, user_id, ip_address, is_successful, ...)
            [$domainId, $userId, $ip, $success] = $params;
            if ((int) $success === 0) {
                $this->failures[] = ['domain_id' => (int) $domainId, 'user_id' => (string) $userId, 'ip' => (string) $ip];
            }
            return 1;
        });

        $db->method('selectOne')->willReturnCallback(function (string $sql, array $params): ?array {
            if (str_contains($sql, 'COUNT(*)')) {
                if (str_contains($sql, 'user_id = ?') && str_contains($sql, 'ip_address = ?')) {
                    // 계정+IP 집계: [domainId, userId, ip, since]
                    [$domainId, $userId, $ip] = $params;
                    $cnt = 0;
                    foreach ($this->failures as $f) {
                        if ($f['domain_id'] === (int) $domainId && $f['user_id'] === (string) $userId && $f['ip'] === (string) $ip) {
                            $cnt++;
                        }
                    }
                    return ['cnt' => $cnt];
                }
                // IP 집계: [ip, since]
                [$ip] = $params;
                $cnt = 0;
                foreach ($this->failures as $f) {
                    if ($f['ip'] === (string) $ip) {
                        $cnt++;
                    }
                }
                return ['cnt' => $cnt];
            }
            // getRetryAfter 의 MIN(attempted_at) — 테스트에선 잠금시간 기본값으로 폴백
            return ['oldest' => null];
        });

        // clearFailedAttempts / maybeCleanup 의 DELETE 는 스토어에 영향 주지 않음(테스트 무관)
        $db->method('execute')->willReturn(0);

        return new LoginAttemptService($db, array_merge([
            'max_attempts_per_user' => 5,
            'max_attempts_per_ip' => 20,
            'cleanup_probability' => 0,
        ], $config));
    }

    public function testAttackerIpCannotLockOutLegitimateUserOnDifferentIp(): void
    {
        $service = $this->makeService();
        $attackerIp = '203.0.113.9';
        $legitIp = '198.51.100.4';

        // 공격자가 임의 IP로 피해자 계정에 5회 실패
        for ($i = 0; $i < 5; $i++) {
            $service->record(self::DOMAIN, 'victim', $attackerIp, false);
        }

        // 공격자 IP 는 잠긴다
        $this->assertFalse($service->registerAttempt(self::DOMAIN, 'victim', $attackerIp)['allowed']);

        // 그러나 같은 계정이라도 다른(정상) IP 는 잠기지 않는다 — DoS 방지 핵심
        $this->assertTrue($service->registerAttempt(self::DOMAIN, 'victim', $legitIp)['allowed']);
    }

    public function testSameAccountAndIpLocksAfterThreshold(): void
    {
        $service = $this->makeService();
        $ip = '203.0.113.9';

        for ($i = 0; $i < 5; $i++) {
            $service->record(self::DOMAIN, 'victim', $ip, false);
        }

        $result = $service->registerAttempt(self::DOMAIN, 'victim', $ip);
        $this->assertFalse($result['allowed']);
        $this->assertSame(0, $result['remaining']);
    }

    public function testUnderThresholdStillAllowed(): void
    {
        $service = $this->makeService();
        $ip = '203.0.113.9';

        for ($i = 0; $i < 3; $i++) {
            $service->record(self::DOMAIN, 'victim', $ip, false);
        }

        // 이번 시도까지 4회 — 한도 5 에 아직 한 번 남는다
        $result = $service->registerAttempt(self::DOMAIN, 'victim', $ip);
        $this->assertTrue($result['allowed']);
        $this->assertSame(1, $result['remaining']);
    }

    public function testDistinctAccountsFromSameIpAccumulateIpLimit(): void
    {
        // IP 단독 남용(계정을 바꿔가며)은 여전히 IP 상한으로 막힌다
        $service = $this->makeService(['max_attempts_per_ip' => 6]);
        $ip = '203.0.113.9';

        for ($i = 0; $i < 6; $i++) {
            $service->record(self::DOMAIN, 'user' . $i, $ip, false);
        }

        // 계정별로는 1회씩이라 계정+IP 잠금엔 안 걸리지만, IP 상한(6)엔 걸린다
        $this->assertFalse($service->registerAttempt(self::DOMAIN, 'user99', $ip)['allowed']);
    }

    // ─── 허용 횟수 경계 ───

    /**
     * 실패를 반복할 때 몇 번까지 시도할 수 있는지 센다.
     *
     * 이 숫자가 이 서비스의 사용자 가시 계약이다. registerAttempt() 가 기록까지
     * 하므로 호출자는 아무것도 더 하지 않는다 — 실제 로그인 흐름과 같다.
     * off-by-one 은 이 테스트를 통과할 수 없다.
     */
    private function countAllowedAttempts(LoginAttemptService $service, string $userId, string $ip, int $max = 50): int
    {
        $allowed = 0;

        for ($i = 0; $i < $max; $i++) {
            if (!$service->registerAttempt(self::DOMAIN, $userId, $ip)['allowed']) {
                break;
            }
            $allowed++;
        }

        return $allowed;
    }

    public function testAccountAndIpLimitAllowsExactlyTheConfiguredNumberOfAttempts(): void
    {
        $service = $this->makeService(['max_attempts_per_user' => 5]);

        $this->assertSame(5, $this->countAllowedAttempts($service, 'victim', '203.0.113.9'));
    }

    public function testAttemptCountFollowsTheConfiguredLimit(): void
    {
        foreach ([1, 3, 10] as $limit) {
            $service = $this->makeService(['max_attempts_per_user' => $limit]);
            $this->failures = [];

            $this->assertSame(
                $limit,
                $this->countAllowedAttempts($service, 'victim', '203.0.113.9'),
                "한도 {$limit} 에서 허용 횟수가 어긋났다"
            );
        }
    }

    public function testIpLimitAllowsExactlyTheConfiguredNumberOfAttempts(): void
    {
        // 계정을 매번 바꿔 계정+IP 상한을 피하고 IP 상한만 남긴다
        $service = $this->makeService(['max_attempts_per_user' => 99, 'max_attempts_per_ip' => 7]);
        $ip = '203.0.113.9';
        $allowed = 0;

        for ($i = 0; $i < 50; $i++) {
            if (!$service->registerAttempt(self::DOMAIN, 'user' . $i, $ip)['allowed']) {
                break;
            }
            $allowed++;
        }

        $this->assertSame(7, $allowed);
    }

    public function testSuccessfulLoginDoesNotConsumeAnAttemptForTheNextUser(): void
    {
        // 성공은 실패 집계에 들어가면 안 된다.
        $service = $this->makeService(['max_attempts_per_user' => 3]);
        $ip = '203.0.113.9';

        $service->record(self::DOMAIN, 'victim', $ip, true);

        $this->assertSame(3, $this->countAllowedAttempts($service, 'victim', $ip));
    }

    // ─── 기록이 먼저라는 성질 ───

    public function testRegisterAttemptHonoursTheIpLimitIdentically(): void
    {
        $service = $this->makeService(['max_attempts_per_user' => 99, 'max_attempts_per_ip' => 7]);
        $ip = '203.0.113.9';
        $allowed = 0;

        for ($i = 0; $i < 50; $i++) {
            if (!$service->registerAttempt(self::DOMAIN, 'user' . $i, $ip)['allowed']) {
                break;
            }
            $allowed++;
        }

        $this->assertSame(7, $allowed);
    }

    public function testRegisterAttemptRecordsTheAttemptEvenWhenDenied(): void
    {
        // 거부된 시도도 기록이 남아야 한다 — 요청이 도중에 죽어도 집계가 사라지지 않는
        // 것이 이 방식의 요점이다.
        $service = $this->makeService(['max_attempts_per_user' => 2]);
        $ip = '203.0.113.9';

        $service->registerAttempt(self::DOMAIN, 'victim', $ip);
        $service->registerAttempt(self::DOMAIN, 'victim', $ip);
        $denied = $service->registerAttempt(self::DOMAIN, 'victim', $ip);

        $this->assertFalse($denied['allowed']);
        $this->assertCount(3, $this->failures, '거부된 시도도 기록돼야 한다');
    }

    public function testRegisterAttemptIsDisabledWithTheRestOfTheLimiter(): void
    {
        $service = $this->makeService(['enabled' => false, 'max_attempts_per_user' => 1]);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($service->registerAttempt(self::DOMAIN, 'victim', '203.0.113.9')['allowed']);
        }

        $this->assertSame([], $this->failures, '비활성 상태에서는 기록도 하지 않는다');
    }
}
