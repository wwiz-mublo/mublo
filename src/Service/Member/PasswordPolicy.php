<?php
declare(strict_types=1);

namespace Mublo\Service\Member;

use Mublo\Core\Result\Result;

/**
 * PasswordPolicy
 *
 * 도메인별 비밀번호 정책 값 객체.
 * site_config(domain_configs)에 저장된 운영자 설정으로부터 규칙을 구성하고
 * 비밀번호를 검증한다.
 *
 * 규칙:
 * - 최소 길이 (기본 6, mb_strlen 문자 수 기준)
 * - 숫자 포함 (옵션)
 * - 특수문자 포함 (옵션, 유니코드 문자/숫자가 아닌 기호)
 * - 영문 소문자 포함 (옵션)
 *
 * 강제 대문자 규칙은 제공하지 않는다(운영 정책상 미채택).
 */
class PasswordPolicy
{
    public const DEFAULT_MIN_LENGTH = 6;

    public function __construct(
        private int $minLength = self::DEFAULT_MIN_LENGTH,
        private bool $requireNumber = false,
        private bool $requireSpecial = false,
        private bool $requireLower = false,
    ) {
        // 방어적 하한 — 0/음수 설정 방지
        if ($this->minLength < 1) {
            $this->minLength = 1;
        }
    }

    /**
     * site_config 배열에서 정책 구성 (미설정 키는 기본값)
     */
    public static function fromSiteConfig(array $siteConfig): self
    {
        return new self(
            minLength: (int) ($siteConfig['password_min_length'] ?? self::DEFAULT_MIN_LENGTH),
            requireNumber: (bool) ($siteConfig['password_require_number'] ?? false),
            requireSpecial: (bool) ($siteConfig['password_require_special'] ?? false),
            requireLower: (bool) ($siteConfig['password_require_lower'] ?? false),
        );
    }

    /**
     * 비밀번호가 정책을 만족하는지 검증
     */
    public function validate(string $password): Result
    {
        if ($password === '') {
            return Result::failure('비밀번호를 입력해주세요.');
        }

        if (mb_strlen($password) < $this->minLength) {
            return Result::failure("비밀번호는 최소 {$this->minLength}자 이상이어야 합니다.");
        }

        if ($this->requireLower && !preg_match('/[a-z]/', $password)) {
            return Result::failure('비밀번호에 영문 소문자를 포함해야 합니다.');
        }

        if ($this->requireNumber && !preg_match('/[0-9]/', $password)) {
            return Result::failure('비밀번호에 숫자를 포함해야 합니다.');
        }

        // 특수문자: 유니코드 문자(\p{L})·숫자(\p{N})가 아닌 모든 문자(공백 제외)
        if ($this->requireSpecial && !preg_match('/[^\p{L}\p{N}\s]/u', $password)) {
            return Result::failure('비밀번호에 특수문자를 포함해야 합니다.');
        }

        return Result::success('사용 가능한 비밀번호입니다.');
    }

    public function getMinLength(): int
    {
        return $this->minLength;
    }

    public function requiresNumber(): bool
    {
        return $this->requireNumber;
    }

    public function requiresSpecial(): bool
    {
        return $this->requireSpecial;
    }

    /**
     * 사람이 읽는 규칙 설명 (폼 힌트용)
     *
     * 예: "최소 8자 이상, 영문 소문자 필수, 숫자 필수, 특수문자 필수"
     */
    public function describe(): string
    {
        $parts = ["최소 {$this->minLength}자 이상"];
        if ($this->requireLower) {
            $parts[] = '영문 소문자 필수';
        }
        if ($this->requireNumber) {
            $parts[] = '숫자 필수';
        }
        if ($this->requireSpecial) {
            $parts[] = '특수문자 필수';
        }
        return implode(', ', $parts);
    }
}
