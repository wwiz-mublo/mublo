<?php

namespace Mublo\Core\Event\Member;

use Mublo\Core\Event\AbstractEvent;

/**
 * 회원 상세 데이터 보강 이벤트
 *
 * 회원 상세 조회(1건) 시 Plugin이 부가 데이터를 첨부할 수 있는 확장점.
 * Admin/MemberController::edit() 등에서 발행.
 *
 * 적용 범위: 상세 조회 전용. 목록은 성능 문제로 제외.
 *
 * 사용 예:
 * ```php
 * // MemberPoint 플러그인
 * public function onDataEnriching(MemberDataEnrichingEvent $event): void
 * {
 *     $balance = $this->pointService->getBalance($event->getMemberId());
 *     $event->addExtra('point', ['balance' => $balance]);
 * }
 *
 * // Referral 플러그인
 * public function onDataEnriching(MemberDataEnrichingEvent $event): void
 * {
 *     $referral = $this->referralRepo->getByMemberId($event->getMemberId());
 *     if ($referral) {
 *         $event->addExtra('referral', [
 *             'referrer_userid' => $referral['referrer_userid'],
 *             'referred_count' => $this->referralRepo->countReferred($event->getMemberId()),
 *         ]);
 *     }
 * }
 * ```
 */
class MemberDataEnrichingEvent extends AbstractEvent
{
    private int $memberId;
    private array $member;
    private string $viewContext;

    /** @var array<string, array> [pluginName => data] */
    private array $extras = [];

    /**
     * @param int $memberId 회원 ID
     * @param array $member Core 기본 회원 데이터
     * @param string $viewContext 조회 맥락 ('admin_detail' | 'front_profile' | 'api')
     */
    public function __construct(int $memberId, array $member, string $viewContext = 'admin_detail')
    {
        $this->memberId = $memberId;
        $this->member = $member;
        $this->viewContext = $viewContext;
    }

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    public function getMember(): array
    {
        return $this->member;
    }

    public function getViewContext(): string
    {
        return $this->viewContext;
    }

    /**
     * 플러그인별 부가 데이터 추가
     *
     * @param string $pluginName 플러그인 이름 (예: 'point', 'referral')
     * @param array $data 부가 데이터
     */
    public function addExtra(string $pluginName, array $data): void
    {
        $this->extras[$pluginName] = $data;
    }

    /**
     * 특정 플러그인의 부가 데이터 반환
     */
    public function getExtra(string $pluginName): ?array
    {
        return $this->extras[$pluginName] ?? null;
    }

    /**
     * 모든 부가 데이터 반환
     *
     * @return array<string, array>
     */
    public function getExtras(): array
    {
        return $this->extras;
    }

    public function hasExtras(): bool
    {
        return !empty($this->extras);
    }
}
