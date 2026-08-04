<?php

namespace Tests\Unit\Service\Member;

use PHPUnit\Framework\TestCase;
use Mublo\Service\Member\PasswordPolicy;

class PasswordPolicyTest extends TestCase
{
    public function testDefaultPolicyIsMinSixCharacters(): void
    {
        $policy = new PasswordPolicy(); // 기본 6

        $this->assertTrue($policy->validate('123456')->isSuccess());
        $this->assertTrue($policy->validate('12345')->isFailure());
        $this->assertTrue($policy->validate('')->isFailure());
    }

    public function testMinLengthUsesCharacterCountNotBytes(): void
    {
        $policy = new PasswordPolicy(minLength: 6);

        // 한글 4자 = 12바이트지만 문자 수는 4 → 6자 미만이므로 실패
        $this->assertTrue($policy->validate('한글단어')->isFailure());
        // 한글 6자 → 통과
        $this->assertTrue($policy->validate('한글단어여섯')->isSuccess());
    }

    public function testFromSiteConfigAppliesMinLength(): void
    {
        $policy = PasswordPolicy::fromSiteConfig(['password_min_length' => 8]);

        $this->assertTrue($policy->validate('1234567')->isFailure());
        $this->assertTrue($policy->validate('12345678')->isSuccess());
    }

    public function testRequireNumber(): void
    {
        $policy = PasswordPolicy::fromSiteConfig([
            'password_min_length' => 6,
            'password_require_number' => true,
        ]);

        $this->assertTrue($policy->validate('abcdefg')->isFailure());
        $this->assertTrue($policy->validate('abcde1')->isSuccess());
    }

    public function testRequireLower(): void
    {
        $policy = PasswordPolicy::fromSiteConfig([
            'password_min_length' => 6,
            'password_require_lower' => true,
        ]);

        $this->assertTrue($policy->validate('ABCDEF1')->isFailure());
        $this->assertTrue($policy->validate('ABCdef1')->isSuccess());
    }

    public function testRequireSpecialAndKoreanIsNotSpecial(): void
    {
        $policy = PasswordPolicy::fromSiteConfig([
            'password_min_length' => 6,
            'password_require_special' => true,
        ]);

        $this->assertTrue($policy->validate('abcdef1')->isFailure());       // 특수문자 없음
        $this->assertTrue($policy->validate('abcde1!')->isSuccess());       // ! 포함
        // 한글 문자는 특수문자로 인정되지 않아야 한다
        $this->assertTrue($policy->validate('비밀번호1a')->isFailure());
    }

    public function testCombinedRules(): void
    {
        $policy = PasswordPolicy::fromSiteConfig([
            'password_min_length' => 8,
            'password_require_number' => true,
            'password_require_special' => true,
            'password_require_lower' => true,
        ]);

        $this->assertTrue($policy->validate('abcdefg1!')->isSuccess());
        $this->assertTrue($policy->validate('ABCDEFG1!')->isFailure());  // 소문자 없음
        $this->assertTrue($policy->validate('abcdefg!')->isFailure());   // 숫자 없음
        $this->assertTrue($policy->validate('abcde1!')->isFailure());    // 7자 (8자 미만)
    }

    public function testZeroOrNegativeMinLengthClampedToOne(): void
    {
        $policy = new PasswordPolicy(minLength: 0);
        $this->assertTrue($policy->validate('a')->isSuccess());
        $this->assertTrue($policy->validate('')->isFailure());
    }
}
