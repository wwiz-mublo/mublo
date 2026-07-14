<?php
namespace Mublo\Plugin\SnsLogin\Provider;

use Mublo\Plugin\SnsLogin\Contract\RevocableSnsProviderInterface;
use Mublo\Plugin\SnsLogin\Dto\SnsUserInfo;
use Mublo\Plugin\SnsLogin\Entity\SnsAccount;
use Mublo\Plugin\SnsLogin\Http\OAuthHttpClient;
use RuntimeException;

/**
 * 네이버 OAuth2 제공자
 *
 * 사전 준비: https://developers.naver.com 에서 애플리케이션 등록 후
 * Client ID / Client Secret / Callback URL 설정 필요
 */
class NaverProvider implements RevocableSnsProviderInterface
{
    private const AUTH_URL     = 'https://nid.naver.com/oauth2.0/authorize';
    private const TOKEN_URL    = 'https://nid.naver.com/oauth2.0/token';
    private const USERINFO_URL = 'https://openapi.naver.com/v1/nid/me';
    private const REVOKE_URL   = 'https://nid.naver.com/oauth2.0/revoke';

    private OAuthHttpClient $httpClient;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $callbackUrl,
        ?OAuthHttpClient $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new OAuthHttpClient();
    }

    public function getName(): string       { return 'naver'; }
    public function getLabel(): string      { return '네이버'; }
    public function getButtonClass(): string { return 'btn-sns--naver'; }

    public function getAuthorizationUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->callbackUrl,
            'state'         => $state,
        ]);
    }

    public function exchangeCode(string $code): array
    {
        $response = $this->httpClient->postForm(self::TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
            'state'         => '', // 토큰 교환 시 state 재전송 (네이버 요구사항)
        ])->json();

        if (empty($response['access_token'])) {
            throw new RuntimeException('네이버 토큰 발급 실패: ' . ($response['error_description'] ?? '알 수 없는 오류'));
        }

        return $response;
    }

    public function getUserInfo(string $accessToken): SnsUserInfo
    {
        $response = $this->httpGet(self::USERINFO_URL, $accessToken);

        if (($response['resultcode'] ?? '') !== '00') {
            throw new RuntimeException('네이버 사용자 정보 조회 실패');
        }

        $info = $response['response'] ?? [];

        return new SnsUserInfo(
            provider:     'naver',
            uid:          (string) $info['id'],
            email:        $info['email'] ?? null,
            nickname:     $info['nickname'] ?? null,
            profileImage: $info['profile_image'] ?? null,
        );
    }

    public function revokeConnection(SnsAccount $account): void
    {
        $token = $account->getRefreshToken() ?: $account->getAccessToken();
        if ($token === null || $token === '') {
            throw new RuntimeException('네이버 연결 해제에 필요한 토큰이 없습니다.');
        }

        $response = $this->httpClient->postForm(self::REVOKE_URL, [
            'client_id'       => $this->clientId,
            'client_secret'   => $this->clientSecret,
            'token'           => $token,
            'token_type_hint' => $account->getRefreshToken() ? 'refresh_token' : 'access_token',
        ]);

        if ($response->getStatusCode() !== 200) {
            $error = $response->json();
            throw new RuntimeException(
                '네이버 연결 해제 실패: ' . ($error['error_description'] ?? $error['error'] ?? 'HTTP ' . $response->getStatusCode()),
            );
        }
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
            throw new RuntimeException('네이버 CURL 요청 실패: ' . $error);
        }
        curl_close($ch);

        return json_decode($body, true) ?? [];
    }
}
