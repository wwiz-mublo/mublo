<?php
namespace Mublo\Service\Member\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Entity\Member\Member;

/**
 * 회원 등록 완료 이벤트 (부모 클래스)
 *
 * 모든 회원 등록 완료 시 발행되는 이벤트의 기본 클래스
 *
 * 구독 방법:
 * - MemberRegisteredEvent::class 구독 → 모든 등록에 반응 (User, Admin, API)
 * - MemberRegisteredByUserEvent::class 구독 → 사용자 직접 가입만
 * - MemberRegisteredByAdminEvent::class 구독 → 관리자 등록만
 *
 * 활용 예시:
 * - 포인트 Plugin: 가입 축하 포인트 지급
 * - 메일 Plugin: 환영 이메일 발송
 * - 로그 Plugin: 가입 로그 기록
 */
class MemberRegisteredEvent extends AbstractEvent
{
    public const SOURCE_USER = 'user';
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_API = 'api';

    protected Member $member;
    protected string $source;

    /** @var array<string, array> 플러그인별 데이터 (PreparingEvent에서 전달) */
    protected array $pluginData = [];

    /**
     * @param Member $member 등록된 회원 엔티티
     * @param string $source 등록 출처 (user|admin|api)
     */
    public function __construct(Member $member, string $source = self::SOURCE_USER)
    {
        $this->member = $member;
        $this->source = $source;
    }

    /**
     * 등록된 회원 엔티티 반환
     */
    public function getMember(): Member
    {
        return $this->member;
    }

    /**
     * 회원 ID 반환 (편의 메서드)
     */
    public function getMemberId(): int
    {
        return $this->member->getMemberId();
    }

    /**
     * 도메인 ID 반환 (편의 메서드)
     */
    public function getDomainId(): int
    {
        return $this->member->getDomainId();
    }

    /**
     * 등록 출처 반환
     *
     * @return string 'user' | 'admin' | 'api'
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * 사용자 직접 가입인지 확인
     */
    public function isUserRegistration(): bool
    {
        return $this->source === self::SOURCE_USER;
    }

    /**
     * 관리자 등록인지 확인
     */
    public function isAdminRegistration(): bool
    {
        return $this->source === self::SOURCE_ADMIN;
    }

    /**
     * API 등록인지 확인
     */
    public function isApiRegistration(): bool
    {
        return $this->source === self::SOURCE_API;
    }

    /**
     * 플러그인 데이터 일괄 설정
     *
     * MemberRegisterPreparingEvent에서 수집한 pluginData를 전달받아 설정.
     *
     * @param array<string, array> $pluginData
     */
    public function setAllPluginData(array $pluginData): void
    {
        $this->pluginData = $pluginData;
    }

    /**
     * 특정 플러그인 데이터 반환
     *
     * @param string $pluginName 플러그인 이름 (예: 'referral', 'geo')
     */
    public function getPluginData(string $pluginName): array
    {
        return $this->pluginData[$pluginName] ?? [];
    }

    /**
     * 모든 플러그인 데이터 반환
     *
     * @return array<string, array>
     */
    public function getAllPluginData(): array
    {
        return $this->pluginData;
    }
}
