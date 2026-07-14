<?php

namespace Tests\Board\Unit\Event;

use PHPUnit\Framework\TestCase;
use Mublo\Packages\Board\Entity\BoardReaction;
use Mublo\Packages\Board\Event\ReactionAddedEvent;

/**
 * ReactionAddedEventTest
 *
 * 반응 추가 이벤트 테스트
 */
class ReactionAddedEventTest extends TestCase
{
    private function makeReaction(array $overrides = []): BoardReaction
    {
        return BoardReaction::fromArray(array_merge([
            'reaction_id'   => 1,
            'domain_id'     => 1,
            'board_id'      => 2,
            'target_type'   => 'article',
            'target_id'     => 10,
            'member_id'     => 5,
            'reaction_type' => 'like',
            'created_at'    => '2026-06-23 00:00:00',
        ], $overrides));
    }

    /**
     * getTargetType()는 enum이 아니라 문자열을 반환해야 한다.
     * (포인트 적립 구독자가 string 을 기대 — 회귀 방지)
     */
    public function testGetTargetTypeReturnsString(): void
    {
        $event = new ReactionAddedEvent($this->makeReaction(['target_type' => 'article']), 7);

        $this->assertSame('article', $event->getTargetType());

        $commentEvent = new ReactionAddedEvent($this->makeReaction(['target_type' => 'comment']), 7);
        $this->assertSame('comment', $commentEvent->getTargetType());
    }

    public function testEventExposesReactionFields(): void
    {
        $event = new ReactionAddedEvent($this->makeReaction(), 7);

        $this->assertSame(1, $event->getReactionId());
        $this->assertSame(10, $event->getTargetId());
        $this->assertSame('like', $event->getReactionType());
        $this->assertSame(5, $event->getMemberId());
        $this->assertSame(7, $event->getTargetAuthorId());
    }

    public function testTargetDomainCanDifferFromReactionActivityDomain(): void
    {
        $event = new ReactionAddedEvent(
            $this->makeReaction(['domain_id' => 2]),
            7,
            1,
        );

        $this->assertSame(1, $event->getTargetDomainId());
    }
}
