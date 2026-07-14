<?php

namespace Mublo\Core\Install;

/**
 * 설치 전 라이선스 동의 상태와 CSRF 토큰을 관리한다.
 */
final class LicenseAgreement
{
    public const ACCEPTED_SESSION_KEY = 'installer_license_accepted';
    public const TOKEN_SESSION_KEY = 'installer_license_token';

    public function __construct(
        private readonly string $licensePath = MUBLO_ROOT_PATH . '/LICENSE'
    ) {
    }

    /**
     * @param array<string, mixed> $session
     */
    public function isAccepted(array $session): bool
    {
        return ($session[self::ACCEPTED_SESSION_KEY] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $session
     */
    public function issueToken(array &$session): string
    {
        $token = $session[self::TOKEN_SESSION_KEY] ?? null;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            $token = bin2hex(random_bytes(32));
            $session[self::TOKEN_SESSION_KEY] = $token;
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $session
     */
    public function accept(array &$session, string $submittedToken, bool $agreed): bool
    {
        $expectedToken = $session[self::TOKEN_SESSION_KEY] ?? null;
        if (!$agreed || !is_string($expectedToken) || $submittedToken === '') {
            return false;
        }

        if (!hash_equals($expectedToken, $submittedToken)) {
            return false;
        }

        $session[self::ACCEPTED_SESSION_KEY] = true;
        unset($session[self::TOKEN_SESSION_KEY]);

        return true;
    }

    public function licenseText(): ?string
    {
        if (!is_file($this->licensePath) || !is_readable($this->licensePath)) {
            return null;
        }

        $text = file_get_contents($this->licensePath);

        return $text === false ? null : $text;
    }
}
