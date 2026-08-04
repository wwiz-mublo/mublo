<?php
declare(strict_types=1);
namespace Mublo\Contract\Auth;

/**
 * 현재 요청의 인증 상태를 읽는 안정 Contract.
 */
interface AuthContextInterface
{
    public function check(): bool;

    public function guest(): bool;

    public function currentUser(): ?AuthenticatedUser;

    public function id(): ?int;

    public function isAdmin(): bool;

    public function isSuper(): bool;

    /** 현재 요청이 다른 회원으로 전환된 대리 로그인 세션인지 여부. */
    public function isProxyLogin(): bool;

    public function canOperateDomain(): bool;

    public function hasLevel(int $requiredLevel): bool;
}
