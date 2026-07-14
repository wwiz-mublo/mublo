<?php
/**
 * packages/Board/tests/Unit/Subscriber/ReactionPointSubscriberTest.php
 *
 * ReactionPointSubscriber 단위 테스트 — 포인트 파밍 회귀 방지
 *
 * 배경: 반응(좋아요) 토글은 add→적립, remove→회수다. BalanceManager는 idempotency_key로
 * 중복을 막는다. 과거엔 키가 reactionId(인스턴스)별로 고유했는데, 이 경우 반응 취소 후 재부여 시
 * 새 reactionId → 새 키 → 재적립되어, revoke 액션이 꺼져 있으면 add→remove→add 반복으로
 * 무제한 파밍이 가능했다.
 *
 * 현재 설계는 멱등키를 (대상, 반응자) 기준으로 고정한다:
 * - award  키 = "bp_reaction_{domain}_{targetType}_{targetId}_{reactorId}"
 * - revoke 키 = "bp_reaction_revoke_{domain}_{targetType}_{targetId}_{reactorId}"
 *
 * 이 테스트는 다음을 영구 고정한다:
 * - 한 반응자가 한 대상에 대해 최초 1회만 적립(재부여는 동일 키 no-op) → revoke 설정과 무관하게 파밍 불가
 * - 서로 다른 reactionId라도 같은 (대상,반응자)면 동일 키(회귀 감지 핵심 — 이전과 반대)
 * - award/revoke는 접두어로 구분되어 서로 상쇄하지 않음
 * - 자추(self)·대상작성자 없음·도메인 없음이면 적립/회수 스킵
 */

namespace Tests\Board\Unit\Subscriber;

use Tests\Board\TestCase;
use Mublo\Packages\Board\Subscriber\ReactionPointSubscriber;
use Mublo\Packages\Board\Service\BoardPointService;
use Mublo\Packages\Board\Entity\BoardReaction;
use Mublo\Packages\Board\Event\ReactionAddedEvent;
use Mublo\Packages\Board\Event\ReactionRemovedEvent;

class ReactionPointSubscriberTest extends TestCase
{
    private const DOMAIN_ID     = 1;
    private const REACTOR_ID    = 7;   // 반응을 누른 회원
    private const AUTHOR_ID     = 42;  // 반응 받은 글/댓글 작성자
    private const BOARD_ID      = 3;
    private const TARGET_ID     = 55;

    // (대상, 반응자) 기준 멱등키 — reactionId 와 무관하게 고정
    private const AWARD_KEY  = 'bp_reaction_1_article_55_7';
    private const REVOKE_KEY = 'bp_reaction_revoke_1_article_55_7';

    private BoardPointService $pointService;
    private ReactionPointSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pointService = $this->createMock(BoardPointService::class);
        $this->subscriber   = new ReactionPointSubscriber($this->pointService);
    }

    private function makeReaction(int $reactionId): BoardReaction
    {
        return BoardReaction::fromArray($this->makeReactionData([
            'reaction_id' => $reactionId,
            'domain_id'   => self::DOMAIN_ID,
            'board_id'    => self::BOARD_ID,
            'target_type' => 'article',
            'target_id'   => self::TARGET_ID,
            'member_id'   => self::REACTOR_ID,
            'reaction_type' => 'like',
        ]));
    }

    private function makeRemovedEvent(int $reactionId, ?int $authorId = self::AUTHOR_ID, ?int $domainId = self::DOMAIN_ID): ReactionRemovedEvent
    {
        return new ReactionRemovedEvent(
            $reactionId,
            'article',
            self::TARGET_ID,
            'like',
            self::REACTOR_ID,
            $authorId,
            $domainId,
            self::BOARD_ID
        );
    }

    // =========================================================
    // award (적립)
    // =========================================================

    public function testAwardUsesReactorScopedKey(): void
    {
        $captured = null;
        $this->pointService->expects($this->once())
            ->method('award')
            ->with(
                self::DOMAIN_ID,
                self::AUTHOR_ID,
                'reaction_received',
                $this->callback(function (array $extra) use (&$captured) {
                    $captured = $extra['idempotency_key'] ?? null;
                    return true;
                })
            )
            ->willReturn(true);

        $this->subscriber->onReactionAdded(new ReactionAddedEvent($this->makeReaction(100), self::AUTHOR_ID));

        $this->assertSame(self::AWARD_KEY, $captured);
    }

    public function testAwardSkippedForSelfReaction(): void
    {
        // 대상 작성자 == 반응자 → 자추, 적립 없음
        $this->pointService->expects($this->never())->method('award');
        $this->subscriber->onReactionAdded(new ReactionAddedEvent($this->makeReaction(100), self::REACTOR_ID));
    }

    public function testAwardSkippedWhenNoTargetAuthor(): void
    {
        $this->pointService->expects($this->never())->method('award');
        $this->subscriber->onReactionAdded(new ReactionAddedEvent($this->makeReaction(100), null));
    }

    public function testAwardUsesTargetDomainForGlobalBoardReaction(): void
    {
        $this->pointService->expects($this->once())
            ->method('award')
            ->with(
                9,
                self::AUTHOR_ID,
                'reaction_received',
                $this->isType('array')
            )
            ->willReturn(true);

        $this->subscriber->onReactionAdded(new ReactionAddedEvent(
            $this->makeReaction(100),
            self::AUTHOR_ID,
            9,
        ));
    }

    // =========================================================
    // revoke (회수)
    // =========================================================

    public function testRevokeUsesReactorScopedKey(): void
    {
        $captured = null;
        $this->pointService->expects($this->once())
            ->method('revoke')
            ->with(
                self::DOMAIN_ID,
                self::AUTHOR_ID,
                'reaction_received',
                $this->callback(function (array $extra) use (&$captured) {
                    $captured = $extra['idempotency_key'] ?? null;
                    return true;
                })
            )
            ->willReturn(true);

        $this->subscriber->onReactionRemoved($this->makeRemovedEvent(100));

        $this->assertSame(self::REVOKE_KEY, $captured);
    }

    public function testRevokeSkippedForSelfReaction(): void
    {
        $this->pointService->expects($this->never())->method('revoke');
        $this->subscriber->onReactionRemoved($this->makeRemovedEvent(100, self::REACTOR_ID));
    }

    public function testRevokeSkippedWhenNoTargetAuthor(): void
    {
        $this->pointService->expects($this->never())->method('revoke');
        $this->subscriber->onReactionRemoved($this->makeRemovedEvent(100, null));
    }

    public function testRevokeSkippedWhenDomainMissing(): void
    {
        $this->pointService->expects($this->never())->method('revoke');
        $this->subscriber->onReactionRemoved($this->makeRemovedEvent(100, self::AUTHOR_ID, null));
    }

    // =========================================================
    // 회귀 감지 핵심: 같은 (대상,반응자)는 reactionId가 달라도 동일 키
    // =========================================================

    /**
     * 파밍 방지의 본질은 "한 반응자가 한 대상에 대해 최초 1회만 적립"이다.
     * 취소 후 재부여(새 reactionId)라도 같은 (대상,반응자)면 동일 멱등키여야,
     * BalanceManager 가 재적립을 no-op 으로 막아 revoke 설정과 무관하게 파밍이 불가능하다.
     */
    public function testSameReactorTargetProducesSameKeyAcrossReinstances(): void
    {
        $keys = [];
        $this->pointService->method('revoke')
            ->willReturnCallback(function (int $d, int $m, string $a, array $extra) use (&$keys) {
                $keys[] = $extra['idempotency_key'] ?? null;
                return true;
            });

        // 좋아요(100) → 취소 → 재좋아요(101) → 취소  같은 실제 토글 시퀀스
        $this->subscriber->onReactionRemoved($this->makeRemovedEvent(100));
        $this->subscriber->onReactionRemoved($this->makeRemovedEvent(101));

        $this->assertCount(2, $keys);
        $this->assertSame(
            $keys[0],
            $keys[1],
            '같은 (대상,반응자)는 reactionId가 달라도 동일 키여야 재적립이 막혀 파밍이 불가능하다'
        );
    }

    /**
     * award 키와 revoke 키는 같은 (대상,반응자)를 공유하되 접두어(revoke)로 구분되어
     * 서로를 상쇄하지 않는 별개의 원장 조작이어야 한다.
     */
    public function testAwardAndRevokeKeysArePairedButDistinct(): void
    {
        $awardKey = null;
        $revokeKey = null;
        $this->pointService->method('award')
            ->willReturnCallback(function ($d, $m, $a, array $extra) use (&$awardKey) {
                $awardKey = $extra['idempotency_key'] ?? null;
                return true;
            });
        $this->pointService->method('revoke')
            ->willReturnCallback(function ($d, $m, $a, array $extra) use (&$revokeKey) {
                $revokeKey = $extra['idempotency_key'] ?? null;
                return true;
            });

        $this->subscriber->onReactionAdded(new ReactionAddedEvent($this->makeReaction(200), self::AUTHOR_ID));
        $this->subscriber->onReactionRemoved($this->makeRemovedEvent(200));

        $this->assertSame(self::AWARD_KEY, $awardKey);
        $this->assertSame(self::REVOKE_KEY, $revokeKey);
        $this->assertNotSame($awardKey, $revokeKey);
    }
}
