<?php
declare(strict_types=1);
namespace Mublo\Plugin\SnsLogin\Service;

use Mublo\Core\Result\Result;
use Mublo\Core\Session\SessionInterface;
use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\SnsLogin\Dto\SnsUserInfo;
use Mublo\Plugin\SnsLogin\Repository\SnsAccountRepository;
use Mublo\Contract\Member\MemberAccountGatewayInterface;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Contract\Member\MemberRegistrationRequest;
use Mublo\Contract\Auth\MemberAuthenticatorInterface;

/**
 * SNS 로그인 핵심 비즈니스 서비스
 *
 * 콜백 처리 흐름:
 * 1. provider_uid 로 sns_accounts 조회
 * 2-a. 기존 연결 → 로그인
 * 2-b. 신규 → auto_register ON: 즉시 가입+로그인 / OFF: 프로필 완성 페이지로
 */
class SnsLoginService
{
    /** 세션 키: 프로필 완성 페이지로 전달할 SNS 정보 */
    public const SESSION_SNS_PENDING = 'sns_login_pending';

    /** 닉네임 후보 충돌 시 최대 재시도 횟수 */
    private const MAX_NICKNAME_ATTEMPTS = 20;

    public function __construct(
        private SnsAccountRepository $accountRepository,
        private MemberAccountGatewayInterface $memberAccounts,
        private MemberQueryInterface $memberQueries,
        private Database             $db,
        private MemberAuthenticatorInterface $authenticator,
        private SnsLoginConfigService $configService,
        private SessionInterface     $session,
        private KoreanNicknameGenerator $nicknameGenerator,
        private SnsConnectionManager $connectionManager,
    ) {}

    /**
     * OAuth2 콜백 처리
     *
     * @return Result
     *   성공:
     *     data['action'] = 'login'           → 기존 회원 로그인 완료
     *     data['action'] = 'register'        → 자동 가입 후 로그인 완료
     *     data['action'] = 'profile_needed'  → 프로필 완성 페이지로 이동 필요
     */
    public function handleCallback(int $domainId, SnsUserInfo $userInfo, array $tokenData, ?string $domainGroup = null, ?string $ipAddress = null): Result
    {
        // 1. 기존 연결 계정 조회
        $account = $this->accountRepository->findByProvider(
            $domainId,
            $userInfo->provider,
            $userInfo->uid
        );

        if ($account) {
            // 기존 연결 → 토큰 갱신 후 로그인
            $expiresAt = isset($tokenData['expires_in'])
                ? date('Y-m-d H:i:s', time() + (int) $tokenData['expires_in'])
                : null;

            $this->accountRepository->updateTokens(
                $account->getId(),
                $tokenData['access_token'] ?? '',
                $tokenData['refresh_token'] ?? null,
                $expiresAt
            );

            return $this->loginLinkedMember($account->getMemberId(), $ipAddress);
        }

        // 2. 신규 연동 처리
        $config = $this->configService->getConfig($domainId);

        if (!empty($config['auto_register'])) {
            return $this->autoRegister($domainId, $userInfo, $tokenData, $config, $domainGroup, $ipAddress);
        }

        // 프로필 완성 페이지로 이동
        $this->session->set(self::SESSION_SNS_PENDING, [
            'domain_id'     => $domainId,
            'domain_group'  => $domainGroup,
            'provider'      => $userInfo->provider,
            'uid'           => $userInfo->uid,
            'email'         => $userInfo->email,
            'nickname'      => $userInfo->nickname,
            'profile_image' => $userInfo->profileImage,
            'access_token'  => $tokenData['access_token'] ?? '',
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'expires_in'    => $tokenData['expires_in'] ?? null,
        ]);

        return Result::success('프로필 입력 필요', ['action' => 'profile_needed']);
    }

    /**
     * 자동 가입 + SNS 연결 + 로그인
     */
    private function autoRegister(int $domainId, SnsUserInfo $userInfo, array $tokenData, array $config, ?string $domainGroup = null, ?string $ipAddress = null): Result
    {
        $password = bin2hex(random_bytes(16));
        $levelValue = (int) ($config['register_level'] ?? 1);
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $memberId = null;

        for ($attempt = 0; $attempt < self::MAX_NICKNAME_ATTEMPTS; $attempt++) {
            $nickname = $this->nicknameGenerator->generate();

            // 현재 소속뿐 아니라 이 도메인에서 최초 가입한 회원의 예약 닉네임도 확인한다.
            if ($this->memberAccounts->nicknameExists($domainId, $nickname, true)) {
                continue;
            }

            // user_id: sns_{provider}_{uid 앞 8자}_{랜덤 4자}
            $userId = 'sns_' . $userInfo->provider . '_' . substr($userInfo->uid, 0, 8)
                . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
            try {
                $memberId = $this->db->transaction(function () use (
                    $domainId,
                    $domainGroup,
                    $userId,
                    $passwordHash,
                    $nickname,
                    $levelValue,
                    $userInfo,
                    $tokenData,
                ): ?int {
                    $createdMemberId = $this->memberAccounts->create(new MemberRegistrationRequest(
                        domainId: $domainId,
                        userId: $userId,
                        passwordHash: $passwordHash,
                        nickname: $nickname,
                        levelValue: $levelValue,
                        originDomainId: $domainId,
                        domainGroup: $domainGroup,
                    ));

                    if (!$createdMemberId) {
                        return null;
                    }

                    // 회원 생성과 SNS 연결을 같은 트랜잭션에 넣어 고아 회원을 남기지 않는다.
                    $this->linkAccount($createdMemberId, $domainId, $userInfo, $tokenData);

                    return $createdMemberId;
                });
            } catch (\Throwable $e) {
                // 같은 SNS 콜백이 동시에 처리된 경우, 패배한 트랜잭션은 롤백되고
                // 먼저 연결된 계정으로 로그인하면 중복 회원과 사용자 오류를 모두 피할 수 있다.
                if ($this->isDuplicateKeyError($e)) {
                    $linkedAccount = $this->accountRepository->findByProvider(
                        $domainId,
                        $userInfo->provider,
                        $userInfo->uid,
                    );

                    if ($linkedAccount) {
                        return $this->loginLinkedMember($linkedAccount->getMemberId(), $ipAddress);
                    }

                    // 닉네임 또는 자동 user_id 경합이면 새 후보로 재시도한다.
                    continue;
                }

                throw $e;
            }

            if ($memberId) {
                break;
            }

            return Result::failure('자동 가입 처리 중 오류가 발생했습니다.');
        }

        if (!$memberId) {
            return Result::failure('사용 가능한 닉네임을 생성하지 못했습니다. 잠시 후 다시 시도해 주세요.');
        }

        if (!$this->authenticator->loginByMemberId($memberId, $ipAddress)) {
            return Result::failure('생성된 계정으로 로그인할 수 없습니다.');
        }

        return Result::success('가입 및 로그인 완료', ['action' => 'register']);
    }

    private function loginLinkedMember(int $memberId, ?string $ipAddress): Result
    {
        $member = $this->memberQueries->findProfile($memberId);
        if (!$member || !$member->active) {
            return Result::failure('연결된 계정이 비활성화되었습니다.');
        }

        if (!$this->authenticator->loginByMemberId($member->memberId, $ipAddress)) {
            return Result::failure('연결된 계정으로 로그인할 수 없습니다.');
        }

        return Result::success('로그인 성공', ['action' => 'login']);
    }

    /** MySQL/MariaDB 및 SQLite의 유니크 키 충돌 여부를 예외 체인 전체에서 확인한다. */
    private function isDuplicateKeyError(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ((string) $current->getCode() === '1062'
                || str_contains($current->getMessage(), 'Duplicate entry')
                || str_contains($current->getMessage(), 'UNIQUE constraint failed')) {
                return true;
            }
        }

        return false;
    }

    /**
     * SNS 계정 연결 저장
     */
    public function linkAccount(int $memberId, int $domainId, SnsUserInfo $userInfo, array $tokenData): void
    {
        $expiresAt = isset($tokenData['expires_in'])
            ? date('Y-m-d H:i:s', time() + (int) $tokenData['expires_in'])
            : null;

        $this->accountRepository->create([
            'domain_id'        => $domainId,
            'member_id'        => $memberId,
            'provider'         => $userInfo->provider,
            'provider_uid'     => $userInfo->uid,
            'provider_email'   => $userInfo->email,
            'access_token'     => $tokenData['access_token'] ?? null,
            'refresh_token'    => $tokenData['refresh_token'] ?? null,
            'token_expires_at' => $expiresAt,
        ]);
    }

    /**
     * SNS 연결 해제
     */
    public function unlinkAccount(int $memberId, string $provider): Result
    {
        return $this->connectionManager->revokeAndDelete($memberId, $provider);
    }

    /**
     * 세션의 pending SNS 정보 조회 (삭제 안 함 - 폼 표시용)
     */
    public function getPendingSession(): ?array
    {
        return $this->session->get(self::SESSION_SNS_PENDING);
    }

    /**
     * 세션의 pending SNS 정보 조회 후 삭제 (처리 완료용)
     */
    public function consumePendingSession(): ?array
    {
        $data = $this->session->get(self::SESSION_SNS_PENDING);
        if ($data) {
            $this->session->remove(self::SESSION_SNS_PENDING);
        }
        return $data;
    }

    /**
     * pending SNS 정보 세션에 저장 (외부에서 복원용)
     */
    public function setPendingSession(array $data): void
    {
        $this->session->set(self::SESSION_SNS_PENDING, $data);
    }
}
