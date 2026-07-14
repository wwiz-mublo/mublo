<?php
namespace Mublo\Plugin\SnsLogin\Provider;

use Mublo\Infrastructure\Log\Logger;
use Mublo\Plugin\SnsLogin\Contract\RevocableSnsProviderInterface;
use Mublo\Plugin\SnsLogin\Dto\SnsUserInfo;
use Mublo\Plugin\SnsLogin\Entity\SnsAccount;
use Mublo\Plugin\SnsLogin\Http\OAuthHttpClient;
use RuntimeException;

/**
 * 카카오 OAuth2 제공자
 *
 * 사전 준비: https://developers.kakao.com 에서 애플리케이션 등록 후
 * REST API 키 / Redirect URI / 동의항목(profile_nickname, account_email) 설정 필요
 *
 * 키 구분:
 *   - REST API 키 (clientId) : OAuth2 인가 코드 발급, 토큰 발급
 *   - Admin 키   (adminKey)  : 회원 연결 해제 등 관리 API (/v1/user/unlink)
 *   - JavaScript 키 (jsKey)  : 프론트엔드 SDK 초기화
 *
 * 이메일: 비즈 앱이 아닌 경우 사용자 선택 동의이므로 null일 수 있음
 */
class KakaoProvider implements RevocableSnsProviderInterface
{
    private const AUTH_URL     = 'https://kauth.kakao.com/oauth/authorize';
    private const TOKEN_URL    = 'https://kauth.kakao.com/oauth/token';
    private const USERINFO_URL = 'https://kapi.kakao.com/v2/user/me';
    private const UNLINK_URL   = 'https://kapi.kakao.com/v1/user/unlink';

    private OAuthHttpClient $httpClient;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $adminKey,
        private string $javascriptKey,
        private string $callbackUrl,
        private ?Logger $logger = null,
        ?OAuthHttpClient $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new OAuthHttpClient();
    }

    public function getName(): string        { return 'kakao'; }
    public function getLabel(): string       { return '카카오'; }
    public function getButtonClass(): string { return 'btn-sns--kakao'; }
    public function getAdminKey(): string    { return $this->adminKey; }
    public function getJavascriptKey(): string { return $this->javascriptKey; }

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
        $payload = [
            'grant_type'   => 'authorization_code',
            'client_id'    => $this->clientId,
            'redirect_uri' => $this->callbackUrl,
            'code'         => $code,
        ];
        if ($this->clientSecret !== '') {
            $payload['client_secret'] = $this->clientSecret;
        }

        $httpResponse = $this->httpClient->postForm(self::TOKEN_URL, $payload);
        $response = $httpResponse->json();

        $this->log('debug', '카카오 API POST 응답', [
            'httpCode' => $httpResponse->getStatusCode(),
            'url'      => self::TOKEN_URL,
        ]);

        if (empty($response['access_token'])) {
            $this->log('error', '카카오 토큰 발급 실패', [
                'response'    => $response,
                'callbackUrl' => $this->callbackUrl,
            ]);
            throw new RuntimeException('카카오 토큰 발급 실패: ' . ($response['error_description'] ?? $response['error'] ?? '알 수 없는 오류'));
        }

        return $response;
    }

    public function getUserInfo(string $accessToken): SnsUserInfo
    {
        $response = $this->httpGet(self::USERINFO_URL, $accessToken);

        if (empty($response['id'])) {
            $this->log('error', '카카오 사용자 정보 조회 실패', [
                'response' => $response,
            ]);
            throw new RuntimeException('카카오 사용자 정보 조회 실패');
        }

        $profile = $response['kakao_account']['profile'] ?? [];
        $account = $response['kakao_account'] ?? [];

        return new SnsUserInfo(
            provider:     'kakao',
            uid:          (string) $response['id'],
            email:        $account['email'] ?? null,
            nickname:     $profile['nickname'] ?? null,
            profileImage: $profile['profile_image_url'] ?? null,
        );
    }

    public function revokeConnection(SnsAccount $account): void
    {
        if ($this->adminKey !== '') {
            $response = $this->httpClient->postForm(
                self::UNLINK_URL,
                ['target_id_type' => 'user_id', 'target_id' => $account->getProviderUid()],
                ["Authorization: KakaoAK {$this->adminKey}"],
            );
        } elseif ($account->getAccessToken()) {
            $response = $this->httpClient->postForm(
                self::UNLINK_URL,
                [],
                ["Authorization: Bearer {$account->getAccessToken()}"],
            );
        } else {
            throw new RuntimeException('카카오 연결 해제에 필요한 Admin 키 또는 액세스 토큰이 없습니다.');
        }

        $body = $response->json();
        if ($response->getStatusCode() === 200 && isset($body['id'])) {
            return;
        }

        // 이미 앱과 연결되지 않은 사용자는 재시도 시 멱등 성공으로 처리한다.
        if (($body['code'] ?? null) === -101) {
            return;
        }

        throw new RuntimeException(
            '카카오 연결 해제 실패: ' . ($body['msg'] ?? 'HTTP ' . $response->getStatusCode()),
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
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            $this->log('error', '카카오 CURL GET 실패', [
                'errno' => $errno,
                'error' => $error,
                'url'   => $url,
            ]);
            throw new RuntimeException('카카오 CURL 요청 실패: ' . $error);
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->log('debug', '카카오 API GET 응답', [
            'httpCode' => $httpCode,
            'url'      => $url,
        ]);

        return json_decode($body, true) ?? [];
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $this->logger?->log($level, $message, $context);
    }
}
