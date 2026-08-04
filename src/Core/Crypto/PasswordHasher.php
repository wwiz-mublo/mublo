<?php
declare(strict_types=1);

namespace Mublo\Core\Crypto;

use Mublo\Core\ConfigFile;

/**
 * PasswordHasher
 *
 * 회원 비밀번호 해싱 단일 지점. config/security.php 의 password.algo / password.cost 를
 * 실제로 소비한다 — 과거에는 각 서비스가 옵션 없는 password_hash(PASSWORD_BCRYPT) 를
 * 직접 호출해 설치 시 기록한 cost 설정이 무시됐고(PHP 8.2/8.3 기본 cost 10),
 * 해시 강도가 서버 PHP 버전에 따라 달라졌다.
 *
 * needsRehash(): cost/algo 설정 변경 후에도 기존 해시는 그대로 남으므로,
 * 로그인 성공 직후(평문 비밀번호가 있는 유일한 시점)에 검사해 점진 재해싱한다.
 *
 * 새로 비밀번호를 해싱하는 코드는 password_hash() 직접 호출 대신 반드시 이 클래스를
 * 쓸 것 — 그래야 설정이 거짓말하지 않는다. (검증은 password_verify() 그대로 사용:
 * 해시 문자열 자체에 algo/cost 가 담겨 있어 설정과 무관하게 동작한다.)
 */
class PasswordHasher
{
    /** config 부재 시 기본 cost (설치기 기본값과 동일) */
    private const DEFAULT_COST = 12;

    /** bcrypt cost 안전 범위 — 너무 낮으면 크래킹 취약, 너무 높으면 로그인 DoS */
    private const MIN_COST = 10;
    private const MAX_COST = 15;

    private string|int|null $algo;
    private int $cost;

    /**
     * @param array|null $passwordConfig security.php 의 password 섹션.
     *                                   null 이면 config 파일에서 직접 로드 (테스트에서는 주입)
     */
    public function __construct(?array $passwordConfig = null)
    {
        if ($passwordConfig === null) {
            $passwordConfig = $this->loadConfig();
        }

        $this->algo = $passwordConfig['algo'] ?? PASSWORD_DEFAULT;

        // 설정 실수 방지 클램프: 범위 밖 값은 예외 대신 안전 범위로 보정한다
        // (초보 운영자가 cost 를 4 나 31 로 적어도 로그인이 마비되지 않도록)
        $cost = (int) ($passwordConfig['cost'] ?? self::DEFAULT_COST);
        $this->cost = max(self::MIN_COST, min(self::MAX_COST, $cost));
    }

    private function loadConfig(): array
    {
        // 설치 전·테스트 환경에서는 config 가 없을 수 있다 — 기본값으로 동작한다.
        // 다만 "없음" 만 허용한다. 읽기 실패까지 삼키면 cost 가 조용히 기본값으로
        // 내려앉고, 로그인마다 도는 needsRehash() 가 강한 해시를 약한 쪽으로 다시
        // 써 버린다. ConfigFile 이 그 계약을 보장한다.
        $password = ConfigFile::load('security')['password'] ?? null;

        return is_array($password) ? $password : [];
    }

    /**
     * 비밀번호 해싱 (설정된 algo/cost 반영)
     */
    public function hash(string $password): string
    {
        return password_hash($password, $this->algo, ['cost' => $this->cost]);
    }

    /**
     * 기존 해시가 현재 설정(algo/cost)보다 구식인지
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algo, ['cost' => $this->cost]);
    }

    /**
     * 현재 적용 cost (검증·테스트용)
     */
    public function getCost(): int
    {
        return $this->cost;
    }
}
