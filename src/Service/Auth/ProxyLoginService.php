<?php
declare(strict_types=1);
namespace Mublo\Service\Auth;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Entity\Member\Member;
use Mublo\Core\Result\Result;

/**
 * ProxyLoginService
 *
 * 상위 관리자가 하위 도메인의 관리자 패널에 접속하기 위한
 * 일회용 토큰 발행/검증 서비스
 *
 * 감사: 토큰 테이블의 사용 행은 다음 발급 시 정리되므로 영구 흔적은
 * Logger의 proxy-login 채널이 담당한다 (발급·사용·실패 기록).
 */
class ProxyLoginService
{
    private Database $db;
    private MemberRepository $memberRepository;
    private ?Logger $logger;

    private const TOKEN_TTL_SECONDS = 30;
    private const AUDIT_CHANNEL = 'proxy-login';

    public function __construct(Database $db, MemberRepository $memberRepository, ?Logger $logger = null)
    {
        $this->db = $db;
        $this->memberRepository = $memberRepository;
        $this->logger = $logger;
    }

    /**
     * 대리 로그인 토큰 생성
     *
     * @param int $sourceDomainId 상위 관리자의 도메인 ID
     * @param int $targetDomainId 접속할 하위 도메인 ID
     * @param int $adminMemberId 상위 관리자 회원 ID
     * @param string $redirectUrl 로그인 후 리다이렉트할 URL (기본: /admin/dashboard)
     * @param int|null $loginAsMemberId 이 회원으로 로그인 (null이면 대상 도메인 소유자)
     *        호스트명 변경 후 새 주소로 세션을 인계할 때, 소유자가 아니라
     *        변경을 실행한 그 관리자로 다시 로그인시키기 위해 사용한다.
     * @return Result 성공 시 token 반환
     */
    public function generateToken(
        int $sourceDomainId,
        int $targetDomainId,
        int $adminMemberId,
        string $redirectUrl = '/admin/dashboard',
        ?int $loginAsMemberId = null
    ): Result
    {
        // 만료된 토큰 정리
        $this->cleanExpiredTokens();

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS);

        $this->db->insert(
            "INSERT INTO proxy_login_tokens (token, source_domain_id, target_domain_id, admin_member_id, login_member_id, redirect_url, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$token, $sourceDomainId, $targetDomainId, $adminMemberId, $loginAsMemberId ?: null, $redirectUrl, $expiresAt]
        );

        $this->logger?->channel(self::AUDIT_CHANNEL)->info('대리 로그인 토큰 발급', [
            'admin_member_id' => $adminMemberId,
            'source_domain_id' => $sourceDomainId,
            'target_domain_id' => $targetDomainId,
        ]);

        return Result::success('토큰이 생성되었습니다.', ['token' => $token]);
    }

    /**
     * 토큰 검증 및 대상 도메인 소유자 반환
     *
     * @param string $token 대리 로그인 토큰
     * @param int $currentDomainId 현재 요청의 도메인 ID (대상 도메인과 일치해야 함)
     * @return Result 성공 시 Member 객체 반환
     */
    public function verifyToken(string $token, int $currentDomainId): Result
    {
        $row = $this->db->selectOne(
            "SELECT * FROM proxy_login_tokens WHERE token = ? AND used = 0",
            [$token]
        );

        if (!$row) {
            $this->logger?->channel(self::AUDIT_CHANNEL)->warning('대리 로그인 검증 실패 — 유효하지 않은 토큰', [
                'target_domain_id' => $currentDomainId,
            ]);
            return Result::failure('유효하지 않은 토큰입니다.');
        }

        // 만료 확인
        if (strtotime($row['expires_at']) < time()) {
            $this->markUsed($row['token_id']);
            return Result::failure('만료된 토큰입니다.');
        }

        // 대상 도메인 확인
        if ((int) $row['target_domain_id'] !== $currentDomainId) {
            return Result::failure('잘못된 도메인으로 접근했습니다.');
        }

        // 토큰 사용 처리
        $this->markUsed($row['token_id']);

        // 로그인 대상 결정
        // - login_member_id가 지정되면 그 회원 (호스트명 변경 후 자기 세션 인계)
        // - 지정되지 않으면 대상 도메인의 소유자 (기존 대리 로그인)
        $loginMemberId = (int) ($row['login_member_id'] ?? 0);

        if ($loginMemberId <= 0) {
            $domainConfig = $this->db->selectOne(
                "SELECT member_id FROM domain_configs WHERE domain_id = ?",
                [$currentDomainId]
            );

            if (!$domainConfig || !$domainConfig['member_id']) {
                return Result::failure('도메인 소유자를 찾을 수 없습니다.');
            }

            $loginMemberId = (int) $domainConfig['member_id'];
        }

        $member = $this->memberRepository->find($loginMemberId);

        if (!$member) {
            return Result::failure('로그인 대상 계정을 찾을 수 없습니다.');
        }

        // 지정 로그인은 그 회원이 대상 도메인을 관리할 자격이 있어야 한다.
        // (토큰만 있으면 임의 회원으로 로그인되는 경로가 되지 않도록 fail-closed)
        if ((int) ($row['login_member_id'] ?? 0) > 0 && !$this->canAdministerDomain($member, $currentDomainId)) {
            $this->logger?->channel(self::AUDIT_CHANNEL)->warning('세션 인계 거부 — 대상 도메인 관리 자격 없음', [
                'login_member_id' => $loginMemberId,
                'target_domain_id' => $currentDomainId,
            ]);

            return Result::failure('해당 도메인을 관리할 자격이 없는 계정입니다.');
        }

        // 영구 감사 기록 — 누가(admin) 어느 도메인에 누구 계정으로 들어갔는가
        $this->logger?->channel(self::AUDIT_CHANNEL)->info('대리 로그인 사용', [
            'admin_member_id' => (int) $row['admin_member_id'],
            'source_domain_id' => (int) $row['source_domain_id'],
            'target_domain_id' => $currentDomainId,
            'logged_in_as_member_id' => $member->getMemberId(),
        ]);

        // 발행 관리자 닉네임 조회
        $adminMember = $this->memberRepository->find((int) $row['admin_member_id']);
        $adminNickname = $adminMember ? ($adminMember->getNickname() ?: $adminMember->getUserId()) : '관리자';

        // 대상 도메인 사이트명 조회 (site_title은 site_config JSON 내부)
        $targetDomain = $this->db->selectOne(
            "SELECT domain, JSON_UNQUOTE(JSON_EXTRACT(site_config, '$.site_title')) AS site_title FROM domain_configs WHERE domain_id = ?",
            [$currentDomainId]
        );
        $siteName = ($targetDomain['site_title'] ?? '') ?: ($targetDomain['domain'] ?? '');

        // 자기 세션 인계 판정 — 같은 도메인, 발행자 자신으로 로그인.
        // 이 경우는 "대리 로그인"이 아니라 주소만 옮겨 탄 것이므로
        // 호출자가 대리 로그인 배너를 걸지 않도록 알려준다.
        $isSelfHandoff = (int) $row['source_domain_id'] === $currentDomainId
            && $member->getMemberId() === (int) $row['admin_member_id'];

        return Result::success('인증 성공', [
            'member' => $member,
            'source_domain_id' => (int) $row['source_domain_id'],
            'admin_member_id' => (int) $row['admin_member_id'],
            'admin_nickname' => $adminNickname,
            'site_name' => $siteName,
            'redirect_url' => $row['redirect_url'] ?? '/admin/dashboard',
            'is_self_handoff' => $isSelfHandoff,
        ]);
    }

    /**
     * 지정 로그인(login_member_id) 자격 검증
     *
     * 관리자여야 하고, 대상 도메인이 자기 도메인이거나 자신의 관리 범위
     * (domain_group 상위)여야 한다. 슈퍼관리자는 전체 범위를 갖는다.
     */
    private function canAdministerDomain(Member $member, int $targetDomainId): bool
    {
        if (!$member->isAdmin() || !$member->isActive()) {
            return false;
        }

        if ($member->isSuper() || $member->getDomainId() === $targetDomainId) {
            return true;
        }

        $targetRow = $this->db->selectOne(
            "SELECT domain_group FROM domain_configs WHERE domain_id = ?",
            [$targetDomainId]
        );
        $targetGroup = (string) ($targetRow['domain_group'] ?? '');
        $memberGroup = (string) ($member->getDomainGroup() ?? '');

        // 그룹 정보가 없으면 계층 판정이 불가능하므로 거부한다(fail-closed).
        if ($targetGroup === '' || $memberGroup === '') {
            return false;
        }

        return str_starts_with($targetGroup, $memberGroup . '/');
    }

    private function markUsed(int $tokenId): void
    {
        $this->db->execute(
            "UPDATE proxy_login_tokens SET used = 1 WHERE token_id = ?",
            [$tokenId]
        );
    }

    private function cleanExpiredTokens(): void
    {
        $this->db->execute(
            "DELETE FROM proxy_login_tokens WHERE expires_at < NOW() OR used = 1"
        );
    }
}
