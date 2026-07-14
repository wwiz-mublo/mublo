<?php
/**
 * packages/Board/tests/Unit/Entity/BoardCommentTest.php
 *
 * BoardComment 엔티티 단위 테스트
 *
 * 검증 항목:
 * - fromArray()          — 필드 매핑, 기본값, 타입 캐스팅
 * - isMemberComment()/isGuestComment() — member_id 유무로 회원/비회원 판별
 * - isReply()/isRootComment()          — parent_id 유무로 계층 판별
 * - isAuthor()           — 소유권 판별 (권한 로직의 근거 — null/불일치 거부)
 * - getAuthorDisplayName() — author_name 없으면 '익명'
 * - toArray()            — 라운드트립
 */

namespace Tests\Board\Unit\Entity;

use Tests\Board\TestCase;
use Mublo\Packages\Board\Entity\BoardComment;

class BoardCommentTest extends TestCase
{
    public function testFromArrayMapsFieldsAndCasts(): void
    {
        $comment = BoardComment::fromArray($this->makeCommentData([
            'comment_id' => '15',   // 문자열도 int로 캐스팅되어야 함
            'is_secret'  => 1,
            'depth'      => '2',
        ]));

        $this->assertSame(15, $comment->getCommentId());
        $this->assertSame(1, $comment->getDomainId());
        $this->assertSame(1, $comment->getArticleId());
        $this->assertSame(42, $comment->getMemberId());
        $this->assertTrue($comment->isSecret());
        $this->assertSame(2, $comment->getDepth());
        $this->assertSame('댓글 내용', $comment->getContent());
    }

    public function testDefaultsWhenKeysMissing(): void
    {
        $comment = BoardComment::fromArray([]);

        $this->assertSame(0, $comment->getCommentId());
        $this->assertNull($comment->getMemberId());
        $this->assertNull($comment->getParentId());
        $this->assertFalse($comment->isSecret());
        $this->assertSame('', $comment->getContent());
        $this->assertSame(0, $comment->getDepth());
    }

    public function testMemberCommentDetection(): void
    {
        $member = BoardComment::fromArray($this->makeCommentData(['member_id' => 42]));
        $this->assertTrue($member->isMemberComment());
        $this->assertFalse($member->isGuestComment());
    }

    public function testGuestCommentDetection(): void
    {
        // member_id 키 자체를 제거해 비회원 댓글 구성
        $data = $this->makeCommentData();
        unset($data['member_id']);
        $guest = BoardComment::fromArray($data);

        $this->assertTrue($guest->isGuestComment());
        $this->assertFalse($guest->isMemberComment());
    }

    public function testReplyVsRootDetection(): void
    {
        $root  = BoardComment::fromArray($this->makeCommentData(['parent_id' => null]));
        $reply = BoardComment::fromArray($this->makeCommentData(['parent_id' => 10]));

        $this->assertTrue($root->isRootComment());
        $this->assertFalse($root->isReply());
        $this->assertTrue($reply->isReply());
        $this->assertFalse($reply->isRootComment());
    }

    /**
     * isAuthor는 댓글 수정/삭제 권한(canModifyComment)의 근거다.
     * null 뷰어와 타 회원은 반드시 거부되어야 소유권이 보장된다.
     */
    public function testIsAuthorEnforcesOwnership(): void
    {
        $comment = BoardComment::fromArray($this->makeCommentData(['member_id' => 42]));

        $this->assertTrue($comment->isAuthor(42));
        $this->assertFalse($comment->isAuthor(99));
        $this->assertFalse($comment->isAuthor(null));
    }

    public function testGuestCommentNeverMatchesAnyAuthor(): void
    {
        $data = $this->makeCommentData();
        unset($data['member_id']);
        $guest = BoardComment::fromArray($data);

        // 비회원 댓글은 member_id가 null이므로 어떤 회원 id로도 소유권이 성립하지 않는다.
        $this->assertFalse($guest->isAuthor(42));
        $this->assertFalse($guest->isAuthor(0));
        $this->assertFalse($guest->isAuthor(null));
    }

    public function testAuthorDisplayNameFallsBackToAnonymous(): void
    {
        $named = BoardComment::fromArray($this->makeCommentData(['author_name' => '홍길동']));
        $this->assertSame('홍길동', $named->getAuthorDisplayName());

        $data = $this->makeCommentData();
        unset($data['author_name']);
        $anon = BoardComment::fromArray($data);
        $this->assertSame('익명', $anon->getAuthorDisplayName());
    }

    public function testToArrayRoundTrip(): void
    {
        $original = $this->makeCommentData(['comment_id' => 33, 'content' => '왕복 테스트']);
        $comment  = BoardComment::fromArray($original);
        $array    = $comment->toArray();

        $this->assertSame(33, $array['comment_id']);
        $this->assertSame('왕복 테스트', $array['content']);
        $this->assertSame(1, $array['article_id']);
        // 재구성해도 동일한 식별/소유 정보 유지
        $rebuilt = BoardComment::fromArray($array);
        $this->assertSame($comment->getCommentId(), $rebuilt->getCommentId());
        $this->assertSame($comment->getMemberId(), $rebuilt->getMemberId());
    }
}
