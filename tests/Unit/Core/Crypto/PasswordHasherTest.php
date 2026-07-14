<?php

namespace Tests\Unit\Core\Crypto;

use Mublo\Core\Crypto\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * PasswordHasher 회귀 테스트
 *
 * 배경: 과거 각 서비스가 옵션 없는 password_hash(PASSWORD_BCRYPT) 를 직접 호출해
 * config/security.php 의 password.cost 가 무시됐다(PHP 8.2/8.3 기본 cost 10).
 * 이 테스트가 "설정이 실제로 소비되는지"를 보증한다.
 */
class PasswordHasherTest extends TestCase
{
    public function testHashHonorsConfiguredCost(): void
    {
        $hasher = new PasswordHasher(['algo' => PASSWORD_BCRYPT, 'cost' => 12]);

        $hash = $hasher->hash('secret123');

        // bcrypt 해시 문자열에 cost 가 각인된다: $2y$12$...
        $this->assertStringStartsWith('$2y$12$', $hash);
        $this->assertTrue(password_verify('secret123', $hash));
    }

    public function testOutOfRangeCostIsClamped(): void
    {
        // 너무 낮은 값은 크래킹 취약 → 하한으로 보정
        $this->assertSame(10, (new PasswordHasher(['cost' => 4]))->getCost());
        // 너무 높은 값은 로그인 마비(DoS) → 상한으로 보정
        $this->assertSame(15, (new PasswordHasher(['cost' => 31]))->getCost());
        // 정상 범위는 그대로
        $this->assertSame(12, (new PasswordHasher(['cost' => 12]))->getCost());
    }

    public function testDefaultCostWhenConfigMissing(): void
    {
        // config 부재(설치 전·테스트 환경)에도 설치기 기본값과 동일하게 동작
        $this->assertSame(12, (new PasswordHasher([]))->getCost());
    }

    public function testNeedsRehashDetectsOutdatedCost(): void
    {
        $hasher = new PasswordHasher(['algo' => PASSWORD_BCRYPT, 'cost' => 12]);

        // 과거 코드가 만들던 cost 10 해시 → 재해싱 필요
        $legacyHash = password_hash('secret123', PASSWORD_BCRYPT, ['cost' => 10]);
        $this->assertTrue($hasher->needsRehash($legacyHash));

        // 현재 설정으로 만든 해시 → 재해싱 불필요
        $this->assertFalse($hasher->needsRehash($hasher->hash('secret123')));
    }
}
