<?php
declare(strict_types=1);
namespace Mublo\Contract\Auth;

/**
 * 신뢰할 수 있는 인증 확장이 기존 회원 세션을 시작하기 위한 Contract.
 */
interface MemberAuthenticatorInterface
{
    /**
     * 활성 회원이면 로그인 세션을 시작한다.
     */
    public function loginByMemberId(int $memberId, ?string $ipAddress = null): bool;
}
