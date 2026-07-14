<?php
namespace Tests\SnsLogin\Unit;

use Mublo\Plugin\SnsLogin\Entity\SnsAccount;
use Mublo\Plugin\SnsLogin\Http\OAuthHttpClient;
use Mublo\Plugin\SnsLogin\Http\OAuthHttpResponse;
use Mublo\Plugin\SnsLogin\Provider\GoogleProvider;
use Mublo\Plugin\SnsLogin\Provider\KakaoProvider;
use Mublo\Plugin\SnsLogin\Provider\NaverProvider;
use PHPUnit\Framework\TestCase;

class ProviderRevocationTest extends TestCase
{
    public function testNaverRevokesRefreshTokenAndItsAccessTokenPair(): void
    {
        $http = $this->createMock(OAuthHttpClient::class);
        $http->expects($this->once())->method('postForm')->with(
            'https://nid.naver.com/oauth2.0/revoke',
            [
                'client_id' => 'naver-id',
                'client_secret' => 'naver-secret',
                'token' => 'refresh-token',
                'token_type_hint' => 'refresh_token',
            ],
        )->willReturn(new OAuthHttpResponse(200, ''));

        $provider = new NaverProvider('naver-id', 'naver-secret', 'https://callback', $http);
        $provider->revokeConnection($this->account('naver'));
    }

    public function testGooglePrefersRefreshTokenForRevocation(): void
    {
        $http = $this->createMock(OAuthHttpClient::class);
        $http->expects($this->once())->method('postForm')->with(
            'https://oauth2.googleapis.com/revoke',
            ['token' => 'refresh-token'],
        )->willReturn(new OAuthHttpResponse(200, ''));

        $provider = new GoogleProvider('google-id', 'google-secret', 'https://callback', $http);
        $provider->revokeConnection($this->account('google'));
    }

    public function testGoogleAlreadyInvalidTokenIsIdempotentSuccess(): void
    {
        $http = $this->createMock(OAuthHttpClient::class);
        $http->method('postForm')->willReturn(
            new OAuthHttpResponse(400, '{"error":"invalid_token"}'),
        );

        $provider = new GoogleProvider('google-id', 'google-secret', 'https://callback', $http);
        $provider->revokeConnection($this->account('google'));

        $this->addToAssertionCount(1);
    }

    public function testKakaoUsesAdminKeyAndProviderUidForUnlink(): void
    {
        $http = $this->createMock(OAuthHttpClient::class);
        $http->expects($this->once())->method('postForm')->with(
            'https://kapi.kakao.com/v1/user/unlink',
            ['target_id_type' => 'user_id', 'target_id' => 'provider-uid'],
            ['Authorization: KakaoAK admin-key'],
        )->willReturn(new OAuthHttpResponse(200, '{"id":123}'));

        $provider = new KakaoProvider(
            'rest-key',
            'login-secret',
            'admin-key',
            '',
            'https://callback',
            null,
            $http,
        );
        $provider->revokeConnection($this->account('kakao'));
    }

    public function testKakaoTokenExchangeIncludesConfiguredLoginSecret(): void
    {
        $http = $this->createMock(OAuthHttpClient::class);
        $http->expects($this->once())->method('postForm')->with(
            'https://kauth.kakao.com/oauth/token',
            [
                'grant_type' => 'authorization_code',
                'client_id' => 'rest-key',
                'redirect_uri' => 'https://callback',
                'code' => 'authorization-code',
                'client_secret' => 'login-secret',
            ],
        )->willReturn(new OAuthHttpResponse(200, '{"access_token":"token"}'));

        $provider = new KakaoProvider(
            'rest-key',
            'login-secret',
            'admin-key',
            '',
            'https://callback',
            null,
            $http,
        );

        $this->assertSame('token', $provider->exchangeCode('authorization-code')['access_token']);
    }

    public function testKakaoTokenExchangeOmitsDisabledLoginSecret(): void
    {
        $http = $this->createMock(OAuthHttpClient::class);
        $http->expects($this->once())->method('postForm')->with(
            'https://kauth.kakao.com/oauth/token',
            [
                'grant_type' => 'authorization_code',
                'client_id' => 'rest-key',
                'redirect_uri' => 'https://callback',
                'code' => 'authorization-code',
            ],
        )->willReturn(new OAuthHttpResponse(200, '{"access_token":"token"}'));

        $provider = new KakaoProvider(
            'rest-key',
            '',
            'admin-key',
            '',
            'https://callback',
            null,
            $http,
        );

        $this->assertSame('token', $provider->exchangeCode('authorization-code')['access_token']);
    }

    private function account(string $provider): SnsAccount
    {
        return new SnsAccount(
            id: 1,
            domainId: 7,
            memberId: 10,
            provider: $provider,
            providerUid: 'provider-uid',
            providerEmail: null,
            linkedAt: '2026-07-26 21:00:00',
            accessToken: 'access-token',
            refreshToken: 'refresh-token',
        );
    }
}
