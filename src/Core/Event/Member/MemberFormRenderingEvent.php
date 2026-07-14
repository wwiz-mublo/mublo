<?php

namespace Mublo\Core\Event\Member;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\AbstractEvent;

/**
 * 관리자 회원폼 렌더링 확장 이벤트
 *
 * Admin 회원 등록/수정 폼에 Plugin이 UI 섹션/스크립트를 추가할 수 있는 확장점.
 * Admin/MemberController::create(), edit()에서 발행.
 *
 * Front 가입 이벤트(RegisterFormRenderingEvent)와 분리된 이유:
 * - Admin에서는 SMS 인증, 캡차 등 가입 전용 검증이 불필요
 * - 주로 읽기 전용 정보 표시 또는 관리자 전용 필드 추가에 사용
 *
 * 사용 예 (ReferralPlugin):
 * ```php
 * public function onFormRendering(MemberFormRenderingEvent $event): void
 * {
 *     if (!$event->isEdit()) return;
 *     $referral = $this->referralRepo->getByMemberId($event->getMemberId());
 *     $event->addSection('<div class="card">추천인 정보...</div>', 600);
 * }
 * ```
 */
class MemberFormRenderingEvent extends AbstractEvent
{
    private string $mode;
    private ?array $member;
    private Context $context;

    /** @var array<int, array{html: string, order: int}> */
    private array $sections = [];

    /** @var array<int, array{script: string, order: int}> */
    private array $scripts = [];

    /**
     * @param string $mode 'create' | 'edit'
     * @param array|null $member edit 시 회원 데이터
     * @param Context $context 현재 컨텍스트
     */
    public function __construct(string $mode, ?array $member, Context $context)
    {
        $this->mode = $mode;
        $this->member = $member;
        $this->context = $context;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getMember(): ?array
    {
        return $this->member;
    }

    public function getMemberId(): ?int
    {
        return $this->member['member_id'] ?? null;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function isCreate(): bool
    {
        return $this->mode === 'create';
    }

    public function isEdit(): bool
    {
        return $this->mode === 'edit';
    }

    /**
     * UI 섹션 추가
     *
     * @param string $html 주입할 HTML
     * @param int $order 정렬 순서 (낮을수록 먼저 표시)
     */
    public function addSection(string $html, int $order = 500): void
    {
        $this->sections[] = ['html' => $html, 'order' => $order];
    }

    /**
     * order 정렬된 섹션 HTML 문자열 배열 반환
     *
     * @return string[]
     */
    public function getSectionsSorted(): array
    {
        $sections = $this->sections;
        usort($sections, fn($a, $b) => $a['order'] <=> $b['order']);

        return array_column($sections, 'html');
    }

    /**
     * JS 스크립트 추가
     *
     * @param string $script 주입할 JavaScript 코드
     * @param int $order 정렬 순서 (낮을수록 먼저 실행)
     */
    public function addScript(string $script, int $order = 500): void
    {
        $this->scripts[] = ['script' => $script, 'order' => $order];
    }

    /**
     * order 정렬된 스크립트 문자열 배열 반환
     *
     * @return string[]
     */
    public function getScriptsSorted(): array
    {
        $scripts = $this->scripts;
        usort($scripts, fn($a, $b) => $a['order'] <=> $b['order']);

        return array_column($scripts, 'script');
    }

    public function hasSections(): bool
    {
        return !empty($this->sections);
    }

    public function hasScripts(): bool
    {
        return !empty($this->scripts);
    }
}
