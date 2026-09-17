<?php
declare(strict_types=1);

namespace Mublo\Service\Auth;

use Mublo\Contract\Auth\ReauthenticationInterface;
use Mublo\Core\Session\SessionInterface;

/**
 * 본인 재확인 사실을 현재 세션에 짧게 보관한다.
 *
 * 세션에 두는 이유: 확인은 "이 사람이 이 단말 앞에 있다"는 사실이라 단말을 벗어나면
 * 의미가 없다. DB 에 두면 다른 브라우저에서도 통과해 버린다. 로그아웃하면 세션이
 * 사라지므로 확인도 함께 사라진다.
 *
 * 회원 ID 를 함께 저장해 확인을 남긴 회원에게만 통과시킨다. 계정을 바꿔 로그인한
 * 세션이 앞사람의 확인을 물려받으면 안 된다.
 */
final class ReauthenticationService implements ReauthenticationInterface
{
    private const SESSION_KEY = 'reauth_confirmed';

    /**
     * 확인 유효시간(초).
     *
     * 길면 자리를 비운 사이 남이 쓰고, 짧으면 한 화면에서 두 작업을 하는 동안에도
     * 다시 묻는다. 10분은 비밀번호를 설정하고 이어서 탈퇴까지 가기에 넉넉하면서,
     * 자리를 뜬 뒤까지 남지는 않는 길이다.
     */
    private const TTL_SECONDS = 600;

    public function __construct(private SessionInterface $session)
    {
    }

    public function confirm(int $memberId): void
    {
        $this->session->set(self::SESSION_KEY, [
            'member_id' => $memberId,
            'expires_at' => time() + self::TTL_SECONDS,
        ]);
    }

    public function isConfirmed(int $memberId): bool
    {
        $stored = $this->session->get(self::SESSION_KEY);

        if (!is_array($stored)
            || ($stored['member_id'] ?? null) !== $memberId
            || time() > ($stored['expires_at'] ?? 0)
        ) {
            return false;
        }

        return true;
    }
}
