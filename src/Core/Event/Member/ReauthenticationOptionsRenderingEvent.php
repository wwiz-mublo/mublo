<?php
declare(strict_types=1);

namespace Mublo\Core\Event\Member;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\AbstractEvent;

/**
 * 민감한 작업 앞에서 회원이 고를 수 있는 본인 확인 수단을 모은다.
 *
 * 코어가 가진 수단은 현재 비밀번호 입력 하나뿐이다. 그런데 자기 비밀번호를 모르는
 * 회원(SNS 로만 가입해 임의 비밀번호가 들어간 계정)은 그 수단을 쓸 수 없다. 확장이
 * 자기 수단(제공자 재로그인 등)을 이 자리에 내놓으면 그런 회원도 본인 확인을 마칠 수
 * 있다. 확인에 성공한 확장은 `Contract\Auth\ReauthenticationInterface::confirm()` 으로
 * 그 사실을 남긴다.
 *
 * 코어는 어떤 수단이 붙었는지 알지 못하고, 확인이 끝났다는 사실만 본다.
 */
class ReauthenticationOptionsRenderingEvent extends AbstractEvent
{
    /** @var list<array{html: string, order: int}> */
    private array $htmlBlocks = [];

    public function __construct(
        private readonly int $memberId,
        private readonly bool $confirmed,
        private readonly Context $context,
    ) {
    }

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    /**
     * 이 회원의 본인 확인이 이미 끝나 있는지.
     *
     * 끝난 뒤에도 수단을 그대로 보여주면 회원은 무엇을 더 해야 하는지 알 수 없다.
     * 확장은 이 값을 보고 안내를 바꾸거나 수단을 감출 수 있다.
     */
    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function addHtml(string $html, int $order = 500): void
    {
        $this->htmlBlocks[] = ['html' => $html, 'order' => $order];
    }

    /** @return list<string> */
    public function getHtmlSorted(): array
    {
        $blocks = $this->htmlBlocks;
        usort($blocks, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_values(array_column($blocks, 'html'));
    }

    public function hasHtml(): bool
    {
        return $this->htmlBlocks !== [];
    }
}
