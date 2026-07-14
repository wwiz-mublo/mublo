<?php

namespace Mublo\Core\Event\Member;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\AbstractEvent;

/**
 * 회원가입 폼 렌더링 확장 이벤트
 *
 * Front 회원가입 폼에 Plugin이 필드/스크립트를 추가할 수 있는 확장점.
 * Front/MemberController::registerForm()에서 발행.
 *
 * 사용 예 (ReferralPlugin):
 * ```php
 * public static function getSubscribedEvents(): array
 * {
 *     return [RegisterFormRenderingEvent::class => 'onFormRendering'];
 * }
 *
 * public function onFormRendering(RegisterFormRenderingEvent $event): void
 * {
 *     $event->addHtml('<div class="form-group">...</div>', 600);
 *     $event->addScript('document.getElementById("btn").addEventListener(...)', 600);
 * }
 * ```
 */
class RegisterFormRenderingEvent extends AbstractEvent
{
    private Context $context;

    /** @var array<int, array{html: string, order: int}> */
    private array $htmlBlocks = [];

    /** @var array<int, array{script: string, order: int}> */
    private array $scripts = [];

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * HTML 블록 추가 (폼 필드 영역)
     *
     * @param string $html 주입할 HTML
     * @param int $order 정렬 순서 (낮을수록 먼저 표시)
     */
    public function addHtml(string $html, int $order = 500): void
    {
        $this->htmlBlocks[] = ['html' => $html, 'order' => $order];
    }

    /**
     * order 정렬된 HTML 문자열 배열 반환
     *
     * @return string[]
     */
    public function getHtmlSorted(): array
    {
        $blocks = $this->htmlBlocks;
        usort($blocks, fn($a, $b) => $a['order'] <=> $b['order']);

        return array_column($blocks, 'html');
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

    public function hasHtml(): bool
    {
        return !empty($this->htmlBlocks);
    }

    public function hasScripts(): bool
    {
        return !empty($this->scripts);
    }
}
