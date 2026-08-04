<?php
declare(strict_types=1);
namespace Mublo\Plugin\SnsLogin\Contract;

use Mublo\Plugin\SnsLogin\Dto\SnsUserInfo;

/**
 * SNS OAuth2 제공자 계약 인터페이스
 *
 * 새 제공자 추가 시:
 * 1. 이 인터페이스를 구현하는 Provider 클래스 작성
 * 2. SnsLoginProvider::boot()에서 registry->register() 호출
 */
interface SnsProviderInterface
{
    /** 내부 식별자 (소문자 영문, URL에 사용됨) */
    public function getName(): string;

    /** 로그인 버튼 표시명 */
    public function getLabel(): string;

    /** 버튼 CSS 클래스 */
    public function getButtonClass(): string;

    /** OAuth2 인증 URL 생성 */
    public function getAuthorizationUrl(string $state): string;

    /**
     * Authorization code → access token 교환
     *
     * @return array{access_token: string, ...} 토큰 엔드포인트 응답 (access_token 보장, refresh_token·expires_in 등 업체별 추가 키 포함)
     */
    public function exchangeCode(string $code): array;

    /** access_token → SNS 사용자 정보 */
    public function getUserInfo(string $accessToken): SnsUserInfo;
}
