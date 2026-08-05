<?php
/**
 * packages/Board/tests/Unit/Plugin/BoardReportArticleSubscriberTest.php
 *
 * BoardReport ArticleSubscriber — 블라인드 게이트 회귀 방지
 *
 * 블라인드는 본문과 첨부를 함께 막아야 한다. 본문만 막으면 첨부 URL 이
 * 목록·검색 결과·외부 링크로 이미 퍼져 있어 글을 거치지 않고 그대로 받아진다.
 * 관리자는 신고를 판단해야 하므로 양쪽 모두 예외다.
 */

namespace Tests\Board\Unit\Plugin;

use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Packages\Board\Entity\BoardAttachment;
use Mublo\Packages\Board\Event\FileDownloadingEvent;
use Mublo\Packages\Board\Plugins\BoardReport\Service\BoardReportService;
use Mublo\Packages\Board\Plugins\BoardReport\Subscriber\ArticleSubscriber;
use PHPUnit\Framework\TestCase;

class BoardReportArticleSubscriberTest extends TestCase
{
    private const DOMAIN_ID  = 1;
    private const ARTICLE_ID = 55;

    private function makeEvent(): FileDownloadingEvent
    {
        $attachment = BoardAttachment::fromArray([
            'attachment_id' => 9,
            'domain_id'     => self::DOMAIN_ID,
            'board_id'      => 3,
            'article_id'    => self::ARTICLE_ID,
        ]);

        return new FileDownloadingEvent($attachment, 7, '127.0.0.1', self::DOMAIN_ID);
    }

    private function makeSubscriber(BoardReportService $service, bool $isAdmin): ArticleSubscriber
    {
        $auth = $this->createMock(AuthContextInterface::class);
        $auth->method('isAdmin')->willReturn($isAdmin);

        return new ArticleSubscriber($service, $auth);
    }

    public function testBlocksDownloadOfBlindedArticleAttachment(): void
    {
        $service = $this->createMock(BoardReportService::class);
        $service->expects($this->once())
            ->method('getBlindReason')
            ->with(self::DOMAIN_ID, self::ARTICLE_ID)
            ->willReturn('블라인드 처리된 게시글입니다.');

        $event = $this->makeEvent();
        $this->makeSubscriber($service, false)->onFileDownloading($event);

        $this->assertTrue($event->isBlocked());
        $this->assertSame('블라인드 처리된 게시글입니다.', $event->getBlockReason());
    }

    public function testAllowsDownloadWhenArticleIsNotBlinded(): void
    {
        $service = $this->createMock(BoardReportService::class);
        $service->method('getBlindReason')->willReturn(null);

        $event = $this->makeEvent();
        $this->makeSubscriber($service, false)->onFileDownloading($event);

        $this->assertFalse($event->isBlocked());
    }

    public function testAdminCanDownloadBlindedArticleAttachment(): void
    {
        $service = $this->createMock(BoardReportService::class);
        $service->expects($this->never())->method('getBlindReason');

        $event = $this->makeEvent();
        $this->makeSubscriber($service, true)->onFileDownloading($event);

        $this->assertFalse($event->isBlocked());
    }
}
