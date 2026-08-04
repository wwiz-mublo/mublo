<?php

namespace Tests\Board\Unit\Plugin;

use Mublo\Packages\Board\Api\DTO\ArticleSnapshot;
use Mublo\Packages\Board\Contract\Extension\BoardArticleReaderInterface;
use Mublo\Packages\Board\Contract\Extension\BoardExtensionApiInterface;
use Mublo\Packages\Board\Plugins\BoardReport\Repository\BoardReportRepository;
use Mublo\Packages\Board\Plugins\BoardReport\Service\BoardReportService;
use PHPUnit\Framework\TestCase;

class BoardReportServiceTest extends TestCase
{
    public function testGlobalBoardArticleCanBeReportedFromCurrentDomain(): void
    {
        $snapshot = new ArticleSnapshot(17, 2, 5, '전역 공지');
        $reader = $this->createMock(BoardArticleReaderInterface::class);
        $reader->expects($this->once())
            ->method('findAccessibleById')
            ->with(17, 1)
            ->willReturn($snapshot);
        $board = $this->createMock(BoardExtensionApiInterface::class);
        $board->method('articles')->willReturn($reader);

        $repository = $this->createMock(BoardReportRepository::class);
        $repository->method('hasReported')->willReturn(false);
        $repository->expects($this->once())
            ->method('insertReport')
            ->with($this->callback(static fn(array $data): bool =>
                $data['domain_id'] === 1
                && $data['article_id'] === 17
                && $data['board_id'] === 5
                && $data['article_title'] === '전역 공지'
            ));

        $result = (new BoardReportService($repository, $board))->submit(
            1,
            17,
            'spam',
            '',
            3,
            '127.0.0.1'
        );

        $this->assertTrue($result->isSuccess());
    }

    public function testGlobalBoardBlindIsScopedToCurrentDomain(): void
    {
        $reader = $this->createMock(BoardArticleReaderInterface::class);
        $reader->expects($this->once())
            ->method('findAccessibleById')
            ->with(17, 1)
            ->willReturn(new ArticleSnapshot(17, 2, 5, '전역 공지'));
        $board = $this->createMock(BoardExtensionApiInterface::class);
        $board->method('articles')->willReturn($reader);

        $repository = $this->createMock(BoardReportRepository::class);
        $repository->expects($this->once())
            ->method('insertBlind')
            ->with(1, 17, '현재 사이트에서 블라인드');
        $repository->expects($this->once())
            ->method('resolvePendingByArticle')
            ->with(1, 17)
            ->willReturn(1);

        $result = (new BoardReportService($repository, $board))->setBlind(
            1,
            17,
            true,
            '현재 사이트에서 블라인드'
        );

        $this->assertTrue($result->isSuccess());
    }
}
