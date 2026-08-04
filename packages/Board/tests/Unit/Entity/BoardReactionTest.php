<?php
/**
 * packages/Board/tests/Unit/Entity/BoardReactionTest.php
 *
 * BoardReaction 엔티티 단위 테스트
 *
 * 검증 항목:
 * - fromArray()   — 필드 매핑, target_type enum 파싱, 기본값
 * - getReactionId() — 인스턴스 식별자 (포인트 멱등키의 근거)
 * - isArticleReaction()/isCommentReaction() — 대상 타입 판별
 * - isLike()      — 반응 타입 판별
 */

namespace Tests\Board\Unit\Entity;

use Tests\Board\TestCase;
use Mublo\Packages\Board\Entity\BoardReaction;
use Mublo\Packages\Board\Enum\ReactionTargetType;

class BoardReactionTest extends TestCase
{
    public function testFromArrayMapsFields(): void
    {
        $reaction = BoardReaction::fromArray($this->makeReactionData([
            'reaction_id' => '101',
            'target_id'   => '55',
            'member_id'   => '7',
        ]));

        $this->assertSame(101, $reaction->getReactionId());
        $this->assertSame(1, $reaction->getDomainId());
        $this->assertSame(55, $reaction->getTargetId());
        $this->assertSame(7, $reaction->getMemberId());
        $this->assertSame('like', $reaction->getReactionType());
    }

    public function testTargetTypeParsesToEnum(): void
    {
        $article = BoardReaction::fromArray($this->makeReactionData(['target_type' => 'article']));
        $comment = BoardReaction::fromArray($this->makeReactionData(['target_type' => 'comment']));

        $this->assertSame(ReactionTargetType::ARTICLE, $article->getTargetType());
        $this->assertTrue($article->isArticleReaction());
        $this->assertFalse($article->isCommentReaction());

        $this->assertTrue($comment->isCommentReaction());
        $this->assertFalse($comment->isArticleReaction());
    }

    public function testInvalidTargetTypeFallsBackToArticle(): void
    {
        $reaction = BoardReaction::fromArray($this->makeReactionData(['target_type' => 'garbage']));
        $this->assertSame(ReactionTargetType::ARTICLE, $reaction->getTargetType());
    }

    public function testIsLike(): void
    {
        $like = BoardReaction::fromArray($this->makeReactionData(['reaction_type' => 'like']));
        $love = BoardReaction::fromArray($this->makeReactionData(['reaction_type' => 'love']));

        $this->assertTrue($like->isLike());
        $this->assertFalse($love->isLike());
    }

    /**
     * reaction_id는 포인트 적립/회수 멱등키의 판별자다.
     * 서로 다른 반응 인스턴스는 반드시 서로 다른 id를 가져야 한다.
     */
    public function testDistinctInstancesHaveDistinctIds(): void
    {
        $first  = BoardReaction::fromArray($this->makeReactionData(['reaction_id' => 100]));
        $second = BoardReaction::fromArray($this->makeReactionData(['reaction_id' => 101]));

        $this->assertNotSame($first->getReactionId(), $second->getReactionId());
    }
}
