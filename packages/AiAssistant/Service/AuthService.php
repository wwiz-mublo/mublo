<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Service;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Repository\AuthTokenRepository;
use Mublo\Packages\AiAssistant\Repository\CompanyUserRepository;
use Mublo\Packages\AiAssistant\Support\TokenCodec;
use Mublo\Packages\AiAssistant\Support\Uuid;

final class AuthService
{
    private const ACCESS_TTL = 900;
    private const REFRESH_TTL = 2592000;

    public function __construct(
        private CompanyUserRepository $companyUsers,
        private AuthTokenRepository $tokens
    ) {
    }

    /** @return array<string, mixed> */
    public function login(
        ?string $companySlug,
        ?int $frameworkDomainId,
        string $loginId,
        string $password
    ): array {
        $company = $this->companyUsers->findCompany($companySlug, $frameworkDomainId);
        $user = $company === null ? null : $this->companyUsers->findActiveUser(
            (string) $company['company_id'],
            trim($loginId)
        );

        $valid = $user !== null && password_verify($password, (string) $user['password_hash']);
        if (!$valid) {
            password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            throw new ApiException('AUTH_INVALID_CREDENTIALS', '아이디 또는 비밀번호가 올바르지 않습니다.', 401);
        }

        return $this->issuePair($this->principalFrom($user));
    }

    /** @return array<string, mixed> */
    public function refresh(string $refreshToken): array
    {
        if (strlen($refreshToken) < 32) {
            throw new ApiException('AUTH_REFRESH_INVALID', '갱신 토큰이 올바르지 않습니다.', 401);
        }
        $row = $this->tokens->findRefresh(TokenCodec::hash($refreshToken));
        if ($row === null) {
            throw new ApiException('AUTH_REFRESH_INVALID', '갱신 토큰이 만료되었거나 폐기되었습니다.', 401);
        }
        if ($row['revoked_at'] !== null) {
            throw new ApiException('AUTH_REFRESH_REUSED', '이미 사용된 갱신 토큰입니다.', 409);
        }
        if (strtotime((string) $row['refresh_expires_at'] . ' UTC') <= time()
            || (string) $row['user_status'] !== 'ACTIVE'
            || (string) $row['company_status'] !== 'ACTIVE'
        ) {
            throw new ApiException('AUTH_REFRESH_INVALID', '갱신 토큰이 만료되었거나 폐기되었습니다.', 401);
        }

        $principal = $this->principalFrom($row);
        $newTokenId = Uuid::v4();
        $pair = $this->newPair($principal, $newTokenId);

        return $this->tokens->transaction(function () use ($row, $newTokenId, $pair): array {
            if (!$this->tokens->consumeForRotation((string) $row['token_id'], $newTokenId)) {
                throw new ApiException('AUTH_REFRESH_REUSED', '이미 사용된 갱신 토큰입니다.', 409);
            }
            $this->persistPair($pair);
            return $this->publicPair($pair);
        });
    }

    /** @return array{company_id: string, user_id: string, login_id: string, nickname: string, role: string, token_id: string} */
    public function authenticate(?string $accessToken): array
    {
        if ($accessToken === null || strlen($accessToken) < 32) {
            throw new ApiException('AUTH_REQUIRED', '인증이 필요합니다.', 401);
        }
        $row = $this->tokens->findActiveAccess(TokenCodec::hash($accessToken));
        if ($row === null) {
            throw new ApiException('AUTH_ACCESS_INVALID', '인증이 만료되었거나 폐기되었습니다.', 401);
        }

        $principal = $this->principalFrom($row);
        $principal['token_id'] = (string) $row['token_id'];
        return $principal;
    }

    public function logout(?string $accessToken): void
    {
        if ($accessToken === null || strlen($accessToken) < 32) {
            throw new ApiException('AUTH_REQUIRED', '인증이 필요합니다.', 401);
        }
        if (!$this->tokens->revokeAccess(TokenCodec::hash($accessToken))) {
            throw new ApiException('AUTH_ACCESS_INVALID', '이미 만료되었거나 폐기된 인증입니다.', 401);
        }
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    private function issuePair(array $principal): array
    {
        $pair = $this->newPair($principal, Uuid::v4());
        $this->persistPair($pair);
        return $this->publicPair($pair);
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    private function newPair(array $principal, string $tokenId): array
    {
        $now = time();
        return [
            'token_id' => $tokenId,
            'principal' => $principal,
            'access_token' => TokenCodec::generate(),
            'refresh_token' => TokenCodec::generate(48),
            'access_expires_at' => $now + self::ACCESS_TTL,
            'refresh_expires_at' => $now + self::REFRESH_TTL,
        ];
    }

    /** @param array<string, mixed> $pair */
    private function persistPair(array $pair): void
    {
        $principal = $pair['principal'];
        $this->tokens->create(
            (string) $pair['token_id'],
            (string) $principal['company_id'],
            (string) $principal['user_id'],
            TokenCodec::hash((string) $pair['access_token']),
            TokenCodec::hash((string) $pair['refresh_token']),
            (int) $pair['access_expires_at'],
            (int) $pair['refresh_expires_at']
        );
    }

    /** @param array<string, mixed> $pair @return array<string, mixed> */
    private function publicPair(array $pair): array
    {
        return [
            'principal' => $pair['principal'],
            'tokens' => [
                'access_token' => $pair['access_token'],
                'access_expires_in' => self::ACCESS_TTL,
                'refresh_token' => $pair['refresh_token'],
                'refresh_expires_in' => self::REFRESH_TTL,
            ],
        ];
    }

    /** @param array<string, mixed> $row @return array{company_id: string, user_id: string, login_id: string, nickname: string, role: string} */
    private function principalFrom(array $row): array
    {
        return [
            'company_id' => (string) $row['company_id'],
            'user_id' => (string) $row['user_id'],
            'login_id' => (string) $row['login_id'],
            'nickname' => (string) $row['nickname'],
            'role' => (string) $row['role'],
        ];
    }
}
