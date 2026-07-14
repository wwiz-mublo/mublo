<?php
namespace Mublo\Plugin\SnsLogin\Contract;

use Mublo\Plugin\SnsLogin\Entity\SnsAccount;

/**
 * 외부 SNS 제공자에 저장된 서비스 연결을 해제할 수 있는 제공자 계약.
 */
interface RevocableSnsProviderInterface extends SnsProviderInterface
{
    /**
     * 제공자 측 사용자 연결과 발급 토큰을 폐기한다.
     *
     * 이미 해제되었거나 토큰이 폐기된 상태는 성공으로 취급한다.
     * 실제 요청 실패나 설정 오류는 예외를 던진다.
     */
    public function revokeConnection(SnsAccount $account): void;
}
