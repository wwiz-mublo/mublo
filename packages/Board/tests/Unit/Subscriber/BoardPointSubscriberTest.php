<?php
/**
 * packages/Board/tests/Unit/Subscriber/BoardPointSubscriberTest.php
 *
 * BoardPointSubscriber 단위 테스트 — "차단된 조회에 과금" 회귀 방지
 *
 * 배경: 열람/다운로드 차단 이벤트(ArticleViewingEvent·FileDownloadingEvent)에는
 * 과금 구독자와 차단 게이트(블라인드 등)가 함께 걸린다. 디스패처는 setBlocked()
 * 로 리스너 실행을 끊지 않으므로, 과금 구독자가 차단 여부를 보지 않으면 볼 수 없는
 * 글에 포인트만 빠져나간다. 차감은 즉시 커밋되고 원장은 INSERT ONLY 라 되돌릴 수도 없다.
 *
 * 이 테스트는 다음을 고정한다:
 * - 이미 blocked 인 이벤트에는 consume() 을 호출하지 않는다
 * - 과금 리스너는 음수 우선순위로 등록된다 (차단 게이트보다 뒤에서 판정)
 * - 차단되지 않은 이벤트의 기존 동작(소비 실패 → 차단)은 그대로다
 */

namespace Tests\Board\Unit\Subscriber;

use Tests\Board\TestCase;
use Mublo\Packages\Board\Subscriber\BoardPointSubscriber;
use Mublo\Packages\Board\Service\BoardPointService;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardAttachment;
use Mublo\Packages\Board\Event\ArticleViewingEvent;
use Mublo\Packages\Board\Event\FileDownloadingEvent;

class BoardPointSubscriberTest extends TestCase
{
    private const DOMAIN_ID = 1;
    private const READER_ID = 7;   // 글을 읽는 회원 (작성자 42 와 달라야 유료)

    private BoardPointService $pointService;
    private BoardConfigRepository $boardConfigRepository;
    private BoardPointSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pointService = $this->createMock(BoardPointService::class);
        $this->boardConfigRepository = $this->createMock(BoardConfigRepository::class);
        $this->subscriber = new BoardPointSubscriber(
            $this->pointService,
            $this->boardConfigRepository
        );
    }

    private function makeViewingEvent(): ArticleViewingEvent
    {
        $article = BoardArticle::fromArray($this->makeArticleData([
            'article_id' => 55,
            'domain_id'  => self::DOMAIN_ID,
            'board_id'   => 3,
            'member_id'  => 42,
        ]));

        return new ArticleViewingEvent($article, self::READER_ID, '127.0.0.1', self::DOMAIN_ID);
    }

    private function makeDownloadingEvent(): FileDownloadingEvent
    {
        $attachment = BoardAttachment::fromArray([
            'attachment_id' => 9,
            'domain_id'     => self::DOMAIN_ID,
            'board_id'      => 3,
            'article_id'    => 55,
        ]);

        return new FileDownloadingEvent($attachment, self::READER_ID, '127.0.0.1', self::DOMAIN_ID);
    }

    // =========================================================
    // 차단된 이벤트에는 과금하지 않는다
    // =========================================================

    public function testDoesNotConsumeWhenViewingAlreadyBlocked(): void
    {
        $event = $this->makeViewingEvent();
        $event->setBlocked(true);
        $event->setBlockReason('블라인드 처리된 게시글입니다.');

        $this->pointService->expects($this->never())->method('consume');

        $this->subscriber->onArticleViewing($event);

        // 앞선 게이트의 차단 사유가 그대로 남아야 한다
        $this->assertTrue($event->isBlocked());
        $this->assertSame('블라인드 처리된 게시글입니다.', $event->getBlockReason());
    }

    public function testDoesNotConsumeWhenDownloadAlreadyBlocked(): void
    {
        $event = $this->makeDownloadingEvent();
        $event->setBlocked(true);

        $this->pointService->expects($this->never())->method('consume');

        $this->subscriber->onFileDownloading($event);

        $this->assertTrue($event->isBlocked());
    }

    public function testDoesNotConsumeForNonBillableView(): void
    {
        $article = BoardArticle::fromArray($this->makeArticleData([
            'article_id' => 55,
            'domain_id'  => self::DOMAIN_ID,
            'board_id'   => 3,
            'member_id'  => 42,
        ]));
        // 관리자 화면·수정 폼에서 온 조회
        $event = new ArticleViewingEvent($article, self::READER_ID, '127.0.0.1', self::DOMAIN_ID, false);

        $this->pointService->expects($this->never())->method('consume');

        $this->subscriber->onArticleViewing($event);

        $this->assertFalse($event->isBlocked());
    }

    // =========================================================
    // 차단되지 않은 이벤트의 기존 동작
    // =========================================================

    public function testBlocksViewingWhenPointConsumeFails(): void
    {
        $event = $this->makeViewingEvent();

        $this->pointService->method('consume')->willReturn([
            'success' => false,
            'message' => '포인트가 부족합니다.',
        ]);

        $this->subscriber->onArticleViewing($event);

        $this->assertTrue($event->isBlocked());
        $this->assertSame('포인트가 부족합니다.', $event->getBlockReason());
    }

    public function testAllowsViewingWhenPointConsumeSucceeds(): void
    {
        $event = $this->makeViewingEvent();

        $this->pointService->expects($this->once())
            ->method('consume')
            ->willReturn(['success' => true, 'already_paid' => false]);

        $this->subscriber->onArticleViewing($event);

        $this->assertFalse($event->isBlocked());
    }

    public function testDoesNotConsumeForOwnArticle(): void
    {
        $article = BoardArticle::fromArray($this->makeArticleData([
            'article_id' => 55,
            'domain_id'  => self::DOMAIN_ID,
            'board_id'   => 3,
            'member_id'  => self::READER_ID,
        ]));
        $event = new ArticleViewingEvent($article, self::READER_ID, '127.0.0.1', self::DOMAIN_ID);

        $this->pointService->expects($this->never())->method('consume');

        $this->subscriber->onArticleViewing($event);

        $this->assertFalse($event->isBlocked());
    }

    // =========================================================
    // 등록 순서
    // =========================================================

    public function testConsumeListenersRegisterWithNegativePriority(): void
    {
        $events = BoardPointSubscriber::getSubscribedEvents();

        foreach ([ArticleViewingEvent::class, FileDownloadingEvent::class] as $eventClass) {
            $this->assertIsArray(
                $events[$eventClass],
                "{$eventClass} 구독은 [method, priority] 형식이어야 한다"
            );
            $this->assertLessThan(
                0,
                $events[$eventClass][1],
                "{$eventClass} 과금 리스너는 차단 게이트(기본 우선순위 0)보다 뒤여야 한다"
            );
        }
    }
}
