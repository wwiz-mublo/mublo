<?php
declare(strict_types=1);
namespace Mublo\Service\Auth;

use Mublo\Contract\Auth\AuthenticatedUser;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Contract\Auth\MemberAuthenticatorInterface;
use Mublo\Core\Session\SessionInterface;
use Mublo\Core\Crypto\PasswordHasher;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Entity\Member\Member;
use Mublo\Core\Result\Result;
use Mublo\Service\Auth\Event\MemberLoggedInEvent;
use Mublo\Service\Auth\LoginAttemptService;
use Mublo\Service\CustomField\CustomFieldFileHandler;
use Mublo\Infrastructure\Security\CsrfManager;

/**
 * AuthService
 *
 * 인증 서비스
 * - 로그인/로그아웃
 * - 사용자 인증 상태 확인
 * - 권한 체크
 */
class AuthService implements AuthContextInterface, MemberAuthenticatorInterface
{
    private SessionInterface $session;
    private MemberRepository $memberRepository;
    private ?EventDispatcher $eventDispatcher;
    private ?LoginAttemptService $loginAttemptService;
    private PasswordHasher $passwordHasher;
    private CsrfManager $csrfManager;
    private ?array $user = null;

    private const SESSION_USER_KEY = 'auth_user';
    private const SESSION_LOGIN_TIME = 'auth_login_time';
    private const SESSION_PROXY_LOGIN = 'proxy_login';
    private const SESSION_REVALIDATED_AT = 'auth_revalidated_at';

    /**
     * 존재하지 않는 계정에 대한 로그인 시 검증 시간을 실제 계정과 비슷하게 맞추기 위한
     * 더미 bcrypt 해시. 타이밍 기반 계정 열거를 완화한다. (비밀값 아님)
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$AsiNtRS.cgs5je4UWBUpI.XN4EeelvXq0rr.KUb74JnN3A1AonpVK';

    public function __construct(
        SessionInterface $session,
        MemberRepository $memberRepository,
        PasswordHasher $passwordHasher,
        CsrfManager $csrfManager,
        ?EventDispatcher $eventDispatcher = null,
        ?LoginAttemptService $loginAttemptService = null
    ) {
        $this->session = $session;
        $this->memberRepository = $memberRepository;
        $this->passwordHasher = $passwordHasher;
        $this->eventDispatcher = $eventDispatcher;
        $this->loginAttemptService = $loginAttemptService;
        $this->csrfManager = $csrfManager;
    }

    /**
     * @template T of EventInterface
     * @param T $event
     * @return T
     */
    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }

    /**
     * 로그인 시도
     *
     * @param int $domainId 도메인 ID (멀티테넌트 환경에서 필수)
     * @param string $userId 사용자 아이디
     * @param string $password 비밀번호
     * @param string $ipAddress 요청 IP (rate limiting용)
     */
    public function attempt(int $domainId, string $userId, string $password, string $ipAddress = ''): Result
    {
        // Rate limit — 이번 시도를 먼저 실패로 기록하고 한도를 판정한다.
        // 세고 나서 기록하면 그 사이가 비어 있어, 병렬 요청이 전부 통과한다.
        // 아래 실패 경로들이 다시 기록하지 않는 것은 여기서 이미 남겼기 때문이다.
        $attemptRecorded = false;
        if ($this->loginAttemptService && $ipAddress !== '') {
            $gate = $this->loginAttemptService->registerAttempt($domainId, $userId, $ipAddress);
            $attemptRecorded = true;
            if (!$gate['allowed']) {
                return Result::failure($gate['message']);
            }
        }

        // 도메인+아이디로 사용자 조회 (도메인 스코프 적용)
        $member = $this->memberRepository->findByDomainAndUserId($domainId, $userId);

        // 사용자 없음: 더미 해시로 검증 시간을 평준화해 타이밍 기반 계정 열거를 완화.
        // 실패 기록은 위 게이트가 이미 남겼다.
        if (!$member) {
            password_verify($password, self::DUMMY_PASSWORD_HASH);
            return Result::failure('아이디 또는 비밀번호가 일치하지 않습니다.');
        }

        // 비밀번호 검증 (계정 상태 안내보다 먼저 — 자격증명 없이 상태를 열거하지 못하도록)
        if (!password_verify($password, $member->getPassword())) {
            return Result::failure('아이디 또는 비밀번호가 일치하지 않습니다.');
        }

        // 계정 상태 확인 (비밀번호가 확인된, 즉 정당한 소유자에게만 상태 안내를 노출)
        if (!$member->isActive()) {
            // 비밀번호는 맞았다. 게이트에서 넣어 둔 실패 기록을 지우지 않으면 휴면·정지
            // 계정 소유자가 상태 안내를 몇 번 보는 것만으로 잠긴다 — 자기 비밀번호를
            // 정확히 아는 사람인데도.
            if ($attemptRecorded && $this->loginAttemptService) {
                $this->loginAttemptService->clearFailedAttempts($domainId, $userId);
            }

            $statusMessages = [
                'inactive' => '비활성화된 계정입니다.',
                'dormant' => '휴면 계정입니다. 휴면 해제 후 이용해 주세요.',
                'blocked' => '정지된 계정입니다.',
                'withdrawn' => '탈퇴한 계정입니다.',
            ];

            return Result::failure($statusMessages[$member->getStatus()->value] ?? '로그인할 수 없는 계정입니다.');
        }

        // 로그인 성공 기록
        $this->recordAttempt($domainId, $userId, $ipAddress, true);

        // 구식 해시 점진 업그레이드 — 평문 비밀번호가 있는 유일한 시점.
        // cost/algo 설정이 바뀌어도 기존 회원은 로그인만 하면 새 설정으로 재해싱된다.
        // 실패해도 로그인은 계속돼야 하므로 (해시는 이미 검증됨) 예외는 삼킨다.
        if ($this->passwordHasher->needsRehash($member->getPassword())) {
            try {
                $this->memberRepository->updatePassword(
                    $member->getMemberId(),
                    $this->passwordHasher->hash($password)
                );
            } catch (\Throwable $e) {
                // 재해싱은 편의 기능 — DB 일시 오류로 로그인을 막지 않는다
            }
        }

        // 로그인 성공 처리
        $this->loginUser($member);

        // 마지막 로그인 시간/IP 업데이트
        $this->memberRepository->updateLastLogin($member->getMemberId(), $ipAddress);

        // 로그인 이벤트 발행
        $this->dispatch(new MemberLoggedInEvent($member));

        return Result::success('로그인 성공', ['user' => $this->user]);
    }

    /**
     * 로그인 시도 기록 (내부 헬퍼)
     */
    private function recordAttempt(int $domainId, string $userId, string $ipAddress, bool $success): void
    {
        if ($this->loginAttemptService && $ipAddress !== '') {
            $this->loginAttemptService->record($domainId, $userId, $ipAddress, $success);
        }
    }

    /**
     * Member 객체로 직접 로그인 (SNS 로그인 등 외부 인증용)
     */
    public function loginByMember(Member $member, ?string $ipAddress = null): void
    {
        // 차단·탈퇴·휴면 계정은 어떤 인증 경로(SNS·프록시 로그인 등)로도 로그인할 수 없다(fail-closed).
        // loginByMemberId 와 대칭 — 외부 인증 경로가 상태 검사를 빠뜨려도 여기서 최종 차단한다.
        if (!$member->isActive()) {
            throw new \RuntimeException('비활성 상태의 계정은 로그인할 수 없습니다.');
        }

        $this->loginUser($member);
        $this->memberRepository->updateLastLogin($member->getMemberId(), $ipAddress);
        $this->dispatch(new MemberLoggedInEvent($member));
    }

    public function loginByMemberId(int $memberId, ?string $ipAddress = null): bool
    {
        $member = $this->memberRepository->find($memberId);
        if (!$member || !$member->isActive()) {
            return false;
        }

        $this->loginByMember($member, $ipAddress);
        return true;
    }

    /**
     * 사용자 로그인 처리
     */
    private function loginUser(Member $member): void
    {
        // 세션 ID 재생성 (세션 고정 공격 방지)
        $this->session->regenerate(true);

        // 로그인 전 발급된 토큰을 권한 상승 후 재사용하지 못하게 한다.
        // 일반 로그인·SNS·loginByMemberId·proxy login이 모두 이 경계를 지난다.
        $this->csrfManager->regenerateToken();

        // 민감 정보 제거된 배열
        $safeUser = $member->toSafeArray();

        // 아바타 URL을 세션에 캐시 (닉네임처럼 매 요청 즉시 사용 — N+1 회피)
        $safeUser['avatar'] = $this->resolveAvatarUrl($member->getMemberId());

        // 세션에 저장
        $this->session->set(self::SESSION_USER_KEY, $safeUser);
        $this->session->set(self::SESSION_LOGIN_TIME, time());

        $this->user = $safeUser;
    }

    /**
     * 회원의 아바타 공개 URL 조회 (예약 필드 type=avatar)
     *
     * 아바타는 public 디스크 저장이라 parseFileMeta가 만료 없는 /storage 직링크를 반환.
     * 미설정/비공개면 null.
     */
    private function resolveAvatarUrl(int $memberId): ?string
    {
        foreach ($this->memberRepository->getFieldValues($memberId) as $row) {
            if (($row['field_type'] ?? '') !== 'avatar') {
                continue;
            }
            $meta = CustomFieldFileHandler::parseFileMeta($row['field_value'] ?? null);
            return $meta['url'] ?? null;
        }
        return null;
    }

    /**
     * 현재 로그인 세션을 DB 기준으로 갱신
     *
     * 회원 등급/도메인 변경 후 세션 동기화에 사용
     */
    public function refreshSession(): bool
    {
        // 명시적 갱신 경로에서는 legacy 세션 자동 보정이 먼저 DB를 조회하지 않게 한다.
        // 아래 단일 조회가 public_id를 포함한 전체 스냅샷을 갱신한다.
        $user = $this->user ?? $this->session->get(self::SESSION_USER_KEY);
        if (!$user) {
            return false;
        }

        $memberId = (int) ($user['member_id'] ?? 0);
        if ($memberId === 0) {
            return false;
        }

        $member = $this->memberRepository->find($memberId);
        if (!$member) {
            return false;
        }

        $safeUser = $member->toSafeArray();
        $safeUser['avatar'] = $this->resolveAvatarUrl($memberId);
        $this->session->set(self::SESSION_USER_KEY, $safeUser);
        $this->user = $safeUser;

        return true;
    }

    /**
     * 관리자 권한/계정상태를 DB 기준으로 재검증하고 세션을 동기화한다.
     *
     * 세션의 is_admin/is_super/status는 로그인 시점 스냅샷이라, 로그인 이후 강등·차단·탈퇴된
     * 관리자가 세션 만료 전까지 권한을 유지하는 문제가 있다. 이 메서드는 최대 $ttl초에 한 번
     * (관리자 트래픽 부하 억제) DB를 재조회해 세션 권한 스냅샷을 갱신하고, 계정이 더 이상
     * 존재하지 않거나 비활성(차단·탈퇴 등)이면 false를 반환한다(호출자가 로그아웃 처리).
     *
     * @param int $ttl 재검증 최소 간격(초)
     * @return bool 여전히 활성 유효 회원이면 true
     */
    public function revalidatePrivileges(int $ttl = 60): bool
    {
        // 권한 재검증 자체가 DB 동기화 경계이므로 user()의 legacy 보정 조회와 겹치지 않는다.
        $user = $this->user ?? $this->session->get(self::SESSION_USER_KEY);
        if (!$user) {
            return false;
        }

        // 스로틀: 최근 재검증이 $ttl 이내면 DB 재조회 없이 통과 처리
        $last = (int) ($this->session->get(self::SESSION_REVALIDATED_AT) ?? 0);
        $now = time();
        if ($last > 0 && ($now - $last) < $ttl) {
            return true;
        }

        $memberId = (int) ($user['member_id'] ?? 0);
        if ($memberId === 0) {
            return false;
        }

        $member = $this->memberRepository->find($memberId);
        if (!$member || !$member->isActive()) {
            return false;
        }

        $safeUser = $member->toSafeArray();
        $safeUser['avatar'] = $this->resolveAvatarUrl($memberId);
        $this->session->set(self::SESSION_USER_KEY, $safeUser);
        $this->session->set(self::SESSION_REVALIDATED_AT, $now);
        $this->user = $safeUser;

        return true;
    }

    /**
     * 대리 로그인 정보 세션에 저장
     */
    public function setProxyLogin(int $sourceDomainId, int $adminMemberId, string $adminNickname = '관리자', string $siteName = ''): void
    {
        $this->session->set(self::SESSION_PROXY_LOGIN, [
            'source_domain_id' => $sourceDomainId,
            'admin_member_id' => $adminMemberId,
            'admin_nickname' => $adminNickname,
            'site_name' => $siteName,
        ]);
    }

    /**
     * 대리 로그인 여부 확인
     */
    public function isProxyLogin(): bool
    {
        return $this->session->has(self::SESSION_PROXY_LOGIN);
    }

    /**
     * 대리 로그인 정보 조회
     */
    public function getProxyLogin(): ?array
    {
        return $this->session->get(self::SESSION_PROXY_LOGIN);
    }

    public function logout(): void
    {
        $this->session->remove(self::SESSION_USER_KEY);
        $this->session->remove(self::SESSION_LOGIN_TIME);
        $this->session->remove(self::SESSION_PROXY_LOGIN);
        $this->session->remove(self::SESSION_REVALIDATED_AT);
        $this->session->regenerate(true);
        $this->user = null;
    }

    /**
     * 로그인 상태 확인
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * 게스트(비로그인) 여부
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * 현재 로그인 사용자 정보
     */
    public function user(): ?array
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $this->user = $this->session->get(self::SESSION_USER_KEY);
        if (is_array($this->user)
            && preg_match('/\A[0-9a-f]{22}\z/', (string) ($this->user['public_id'] ?? '')) !== 1
        ) {
            $memberId = (int) ($this->user['member_id'] ?? 0);
            $member = $memberId > 0 ? $this->memberRepository->find($memberId) : null;
            if ($member instanceof Member) {
                $safeUser = $member->toSafeArray();
                $safeUser['avatar'] = $this->user['avatar'] ?? $this->resolveAvatarUrl($memberId);
                $this->session->set(self::SESSION_USER_KEY, $safeUser);
                $this->user = $safeUser;
            }
        }
        return $this->user;
    }

    /**
     * 확장 API에 노출하는 현재 인증 사용자.
     *
     * 내부 세션 배열은 유지하되 안정 Contract 경계에서는 명시적인 DTO만 반환한다.
     */
    public function currentUser(): ?AuthenticatedUser
    {
        $user = $this->user();
        if ($user === null) {
            return null;
        }

        return new AuthenticatedUser(
            memberId: (int) ($user['member_id'] ?? 0),
            domainId: (int) ($user['domain_id'] ?? 0),
            userId: (string) ($user['user_id'] ?? ''),
            nickname: isset($user['nickname']) ? (string) $user['nickname'] : null,
            levelValue: (int) ($user['level_value'] ?? 0),
            admin: (bool) ($user['is_admin'] ?? false),
            super: (bool) ($user['is_super'] ?? false),
            canOperateDomain: (bool) ($user['can_operate_domain'] ?? false),
            avatar: isset($user['avatar']) ? (string) $user['avatar'] : null,
            canCreateSite: (bool) ($user['can_create_site'] ?? false),
            name: isset($user['name']) ? (string) $user['name'] : null,
            domainGroup: (string) ($user['domain_group'] ?? ''),
            levelType: (string) ($user['level_type'] ?? ''),
            publicId: (string) ($user['public_id'] ?? ''),
        );
    }

    /**
     * 현재 사용자 ID
     */
    public function id(): ?int
    {
        $user = $this->user();
        return $user['member_id'] ?? null;
    }

    /**
     * 관리자 여부
     */
    public function isAdmin(): bool
    {
        $user = $this->user();
        return $user && ($user['is_admin'] || $user['is_super']);
    }

    /**
     * 최고관리자 여부
     */
    public function isSuper(): bool
    {
        $user = $this->user();
        return $user && $user['is_super'];
    }

    /**
     * 도메인 운영 권한 여부 (슈퍼관리자 또는 can_operate_domain)
     *
     * 직접 입력 JS 등 '신뢰 관리자' 전용 자유 채널의 게이트로 사용.
     */
    public function canOperateDomain(): bool
    {
        $user = $this->user();
        return $user && (($user['is_super'] ?? false) || ($user['can_operate_domain'] ?? false));
    }

    /**
     * 특정 레벨 이상 여부
     */
    public function hasLevel(int $requiredLevel): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        return ($user['level_value'] ?? 0) >= $requiredLevel;
    }
}
