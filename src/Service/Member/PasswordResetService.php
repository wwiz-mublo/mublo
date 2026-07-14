<?php

namespace Mublo\Service\Member;

use Mublo\Core\Result\Result;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Repository\Member\PasswordResetTokenRepository;
use Mublo\Infrastructure\Crypto\CryptoManager;
use Mublo\Infrastructure\Mail\Mailer;

/**
 * PasswordResetService
 *
 * 이메일 인증 토큰 기반 비밀번호 재설정
 *
 * 플로우:
 * 1. requestReset() — 토큰 생성 + 이메일 발송
 * 2. verifyToken() — 토큰 검증 (폼 표시용)
 * 3. resetPassword() — 토큰 검증 + 비밀번호 변경
 *
 * 보안:
 * - 토큰: SHA-256 해시로 DB 저장, 평문은 이메일 링크에만
 * - 만료: 30분 (TOKEN_LIFETIME)
 * - 1회용: used_at 기록
 * - 열거 방어: 회원 미존재 시에도 동일 응답
 * - Rate limiting: 이메일당 5분/1회, IP당 15분/5회
 */
class PasswordResetService
{
    private const TOKEN_LIFETIME = 1800;       // 30분
    private const RATE_LIMIT_EMAIL_SECONDS = 300;  // 5분
    private const RATE_LIMIT_IP_SECONDS = 900;     // 15분
    private const RATE_LIMIT_IP_MAX = 5;

    private PasswordResetTokenRepository $tokenRepository;
    private MemberRepository $memberRepository;
    private MemberService $memberService;
    private CryptoManager $cryptoManager;
    private Mailer $mailer;

    public function __construct(
        PasswordResetTokenRepository $tokenRepository,
        MemberRepository $memberRepository,
        MemberService $memberService,
        CryptoManager $cryptoManager,
        Mailer $mailer
    ) {
        $this->tokenRepository = $tokenRepository;
        $this->memberRepository = $memberRepository;
        $this->memberService = $memberService;
        $this->cryptoManager = $cryptoManager;
        $this->mailer = $mailer;
    }

    /**
     * 비밀번호 재설정 요청
     *
     * 회원 존재 여부에 관계없이 동일한 성공 메시지 반환 (열거 공격 방어)
     */
    public function requestReset(
        int $domainId,
        array $data,
        bool $useEmailAsUserId,
        string $ipAddress,
        string $baseUrl
    ): Result {
        $email = trim($data['email'] ?? '');
        $userId = trim($data['user_id'] ?? '');

        // 기본 검증
        if (empty($email)) {
            return Result::failure('이메일을 입력해주세요.');
        }

        if (!$useEmailAsUserId && empty($userId)) {
            return Result::failure('아이디를 입력해주세요.');
        }

        $successMessage = '등록된 이메일이면 비밀번호 재설정 링크를 발송했습니다.';

        // Rate limit 확인 (IP)
        $ipCount = $this->tokenRepository->countRecentByIp($ipAddress, self::RATE_LIMIT_IP_SECONDS);
        if ($ipCount >= self::RATE_LIMIT_IP_MAX) {
            return Result::failure('요청이 너무 많습니다. 잠시 후 다시 시도해주세요.');
        }

        // Rate limit 확인 (이메일)
        $emailCount = $this->tokenRepository->countRecentByEmail($domainId, $email, self::RATE_LIMIT_EMAIL_SECONDS);
        if ($emailCount > 0) {
            // 열거 방어: 이미 발송했어도 동일 성공 메시지
            return Result::success($successMessage);
        }

        // 회원 조회
        $member = $this->findMember($domainId, $email, $userId, $useEmailAsUserId);
        if ($member === null) {
            // 열거 방어: 미존재해도 성공 응답
            return Result::success($successMessage);
        }

        // 기존 미사용 토큰 무효화
        $this->tokenRepository->invalidateByMember($member->getMemberId());

        // 토큰 생성
        $plainToken = $this->cryptoManager->generateToken(32);
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_LIFETIME);

        $this->tokenRepository->create(
            $domainId,
            $member->getMemberId(),
            $tokenHash,
            $email,
            $ipAddress,
            $expiresAt
        );

        // 이메일 발송
        $resetUrl = rtrim($baseUrl, '/') . '/find-account/reset-password?token=' . $plainToken;
        $memberName = $member->getNickname() ?: $member->getUserId();

        // 계정 필수 메일 — 알림 이메일 정책(email_channel_enabled)의 의도된 예외.
        // 운영자가 알림 이메일을 꺼도 계정 복구는 막히면 안 되므로 Mailer 직접 경로를 쓴다.
        $this->mailer->sendTemplate($email, '비밀번호 재설정 안내', 'password-reset', [
            'member_name' => $memberName,
            'reset_url' => $resetUrl,
            'expires_in' => '30분',
        ]);

        // 만료 토큰 정리 (10% 확률로 GC)
        if (random_int(1, 10) === 1) {
            $this->tokenRepository->deleteExpired();
        }

        return Result::success($successMessage);
    }

    /**
     * 토큰 검증 (폼 표시용)
     *
     * @return Result 성공 시 data에 'token' 포함
     */
    public function verifyToken(string $token): Result
    {
        if (empty($token)) {
            return Result::failure('유효하지 않은 링크입니다.');
        }

        $tokenHash = hash('sha256', $token);
        $record = $this->tokenRepository->findValidByTokenHash($tokenHash);

        if ($record === null) {
            return Result::failure('만료되었거나 이미 사용된 링크입니다. 비밀번호 재설정을 다시 요청해주세요.');
        }

        return Result::success('토큰이 유효합니다.', ['token' => $token]);
    }

    /**
     * 토큰으로 비밀번호 변경
     */
    public function resetPassword(string $token, string $newPassword, string $newPasswordConfirm): Result
    {
        if (empty($token)) {
            return Result::failure('유효하지 않은 요청입니다.');
        }

        // 비밀번호 검증
        if (empty($newPassword)) {
            return Result::failure('새 비밀번호를 입력해주세요.');
        }

        if ($newPassword !== $newPasswordConfirm) {
            return Result::failure('비밀번호가 일치하지 않습니다.');
        }

        // 토큰 검증
        $tokenHash = hash('sha256', $token);
        $record = $this->tokenRepository->findValidByTokenHash($tokenHash);

        if ($record === null) {
            return Result::failure('만료되었거나 이미 사용된 링크입니다. 비밀번호 재설정을 다시 요청해주세요.');
        }

        $memberId = (int) $record['member_id'];

        // 비밀번호 정책 검증 (해당 회원 도메인의 정책 적용)
        $member = $this->memberRepository->find($memberId);
        $passwordResult = $this->memberService->validatePassword($newPassword, $member?->getDomainId());
        if ($passwordResult->isFailure()) {
            return $passwordResult;
        }

        // 비밀번호 변경
        $hashedPassword = $this->memberService->getPasswordHasher()->hash($newPassword);
        $this->memberRepository->updatePassword($memberId, $hashedPassword);

        // 토큰 사용 처리
        $this->tokenRepository->markUsed((int) $record['token_id']);

        // 동일 회원의 다른 미사용 토큰 무효화
        $this->tokenRepository->invalidateByMember($memberId);

        return Result::success('비밀번호가 변경되었습니다. 새 비밀번호로 로그인해주세요.');
    }

    /**
     * 만료 토큰 정리 (관리자/cron용)
     */
    public function purgeExpiredTokens(): int
    {
        return $this->tokenRepository->deleteExpired();
    }

    /**
     * 회원 조회 (모드별)
     */
    private function findMember(int $domainId, string $email, string $userId, bool $useEmailAsUserId): ?\Mublo\Entity\Member\Member
    {
        if ($useEmailAsUserId) {
            // Mode A: 이메일 = 아이디
            $member = $this->memberRepository->findByDomainAndUserId($domainId, $email);
        } else {
            // Mode B: 아이디 + 이메일 검증
            $member = $this->memberRepository->findByDomainAndUserId($domainId, $userId);
            if ($member !== null) {
                // 이메일 Blind Index 일치 검증
                if (!$this->memberService->verifyMemberEmailPublic($domainId, $member->getMemberId(), $email)) {
                    return null;
                }
            }
        }

        // 활성 회원만
        if ($member !== null && $member->getStatus()->value !== 'active') {
            return null;
        }

        return $member;
    }
}
