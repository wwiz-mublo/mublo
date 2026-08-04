<?php
declare(strict_types=1);
namespace Mublo\Plugin\SnsLogin\Provider;

use Mublo\Plugin\SnsLogin\Contract\RevocableSnsProviderInterface;
use Mublo\Plugin\SnsLogin\Dto\SnsUserInfo;
use Mublo\Plugin\SnsLogin\Entity\SnsAccount;
use Mublo\Plugin\SnsLogin\Http\OAuthHttpClient;
use RuntimeException;

/**
 * Google OAuth2 제공자 (OpenID Connect)
 *
 * 사전 준비: https://console.cloud.google.com 에서
 * OAuth2 클라이언트 ID/Secret 생성 + Redirect URI 등록 필요
 */
class GoogleProvider implements RevocableSnsProviderInterface
{
    private const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';
    private const REVOKE_URL   = 'https://oauth2.googleapis.com/revoke';

    private OAuthHttpClient $httpClient;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $callbackUrl,
        ?OAuthHttpClient $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new OAuthHttpClient();
    }

    public function getName(): string        { return 'google'; }
    public function getLabel(): string       { return 'Google'; }
    public function getButtonClass(): string { return 'btn-sns--google'; }

    public function getAuthorizationUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->callbackUrl,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'offline',
        ]);
    }

    public function exchangeCode(string $code): array
    {
        $response = $this->httpClient->postForm(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->callbackUrl,
            'grant_type'    => 'authorization_code',
        ])->json();

        if (empty($response['access_token'])) {
            throw new RuntimeException('Google 토큰 발급 실패: ' . ($response['error_description'] ?? '알 수 없는 오류'));
        }

        return $response;
    }

    public function getUserInfo(string $accessToken): SnsUserInfo
    {
        $response = $this->httpGet(self::USERINFO_URL, $accessToken);

        if (empty($response['sub'])) {
            throw new RuntimeException('Google 사용자 정보 조회 실패');
        }

        return new SnsUserInfo(
            provider:     'google',
            uid:          $response['sub'],
            email:        $response['email'] ?? null,
            nickname:     $response['name'] ?? null,
            profileImage: $response['picture'] ?? null,
        );
    }

    public function revokeConnection(SnsAccount $account): void
    {
        // refresh token을 폐기하면 연결된 access token도 함께 무효화된다.
        $token = $account->getRefreshToken() ?: $account->getAccessToken();
        if ($token === null || $token === '') {
            throw new RuntimeException('Google 연결 해제에 필요한 토큰이 없습니다.');
        }

        $response = $this->httpClient->postForm(self::REVOKE_URL, ['token' => $token]);
        if ($response->getStatusCode() === 200) {
            return;
        }

        $error = $response->json();
        // 이미 만료·폐기된 토큰은 더 이상 서비스가 사용할 수 없으므로 멱등 성공이다.
        if ($response->getStatusCode() === 400 && ($error['error'] ?? '') === 'invalid_token') {
            return;
        }

        throw new RuntimeException(
            'Google 연결 해제 실패: ' . ($error['error_description'] ?? $error['error'] ?? 'HTTP ' . $response->getStatusCode()),
        );
    }

    private function httpGet(string $url, string $accessToken): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Google CURL 요청 실패: ' . $error);
        }
        curl_close($ch);

        return json_decode($body, true) ?? [];
    }
}
