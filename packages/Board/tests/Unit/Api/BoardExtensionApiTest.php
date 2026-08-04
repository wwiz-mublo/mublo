<?php

namespace Tests\Board\Unit\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Result\Result;
use Mublo\Packages\Board\Api\BoardArticleCommand;
use Mublo\Packages\Board\Api\BoardArticleReader;
use Mublo\Packages\Board\Api\BoardExtensionApi;
use Mublo\Packages\Board\Contract\Extension\BoardArticleCommandInterface;
use Mublo\Packages\Board\Contract\Extension\BoardArticleReaderInterface;
use Mublo\Packages\Board\Contract\Extension\BoardExtensionApiInterface;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\BoardProvider;
use Mublo\Packages\Board\Service\BoardArticleService;
use Mublo\Packages\Board\Service\BoardConfigService;
use PHPUnit\Framework\TestCase;

class BoardExtensionApiTest extends TestCase
{
    protected function tearDown(): void
    {
        DependencyContainer::resetInstance();
        parent::tearDown();
    }

    public function testReaderConvertsInternalEntityToReadonlySnapshot(): void
    {
        $service = $this->createMock(BoardArticleService::class);
        $service->expects($this->once())
            ->method('findById')
            ->with(17)
            ->willReturn(BoardArticle::fromArray([
                'article_id' => 17,
                'domain_id' => 3,
                'board_id' => 5,
                'title' => '공개 API 게시글',
            ]));
        $boards = $this->createMock(BoardConfigService::class);
        $boards->method('getBoard')->with(5)->willReturn($this->board(3));

        $snapshot = (new BoardArticleReader($service, $boards))->findAccessibleById(17, 3);

        $this->assertNotNull($snapshot);
        $this->assertSame(17, $snapshot->getArticleId());
        $this->assertSame(3, $snapshot->getDomainId());
        $this->assertSame(5, $snapshot->getBoardId());
        $this->assertSame('공개 API 게시글', $snapshot->getTitle());
        $this->assertTrue((new \ReflectionClass($snapshot))->isReadOnly());
    }

    public function testReaderAllowsGlobalBoardArticleFromAnotherDomain(): void
    {
        $service = $this->createMock(BoardArticleService::class);
        $service->method('findById')->willReturn(BoardArticle::fromArray([
            'article_id' => 17,
            'domain_id' => 2,
            'board_id' => 5,
            'title' => '전역 게시글',
        ]));
        $boards = $this->createMock(BoardConfigService::class);
        $boards->method('getBoard')->willReturn($this->board(2, true));

        $snapshot = (new BoardArticleReader($service, $boards))->findAccessibleById(17, 1);

        $this->assertNotNull($snapshot);
        $this->assertSame(2, $snapshot->getDomainId());
    }

    public function testReaderRejectsForeignArticleOnNonGlobalBoard(): void
    {
        $service = $this->createMock(BoardArticleService::class);
        $service->method('findById')->willReturn(BoardArticle::fromArray([
            'article_id' => 17,
            'domain_id' => 2,
            'board_id' => 5,
            'title' => '다른 사이트 게시글',
        ]));
        $boards = $this->createMock(BoardConfigService::class);
        $boards->method('getBoard')->willReturn($this->board(2));

        $this->assertNull(
            (new BoardArticleReader($service, $boards))->findAccessibleById(17, 1)
        );
    }

    public function testCommandDelegatesDeleteWithoutExposingBoardService(): void
    {
        $context = $this->createMock(Context::class);
        $expected = Result::success('deleted');
        $service = $this->createMock(BoardArticleService::class);
        $service->expects($this->once())
            ->method('delete')
            ->with(9, $context)
            ->willReturn($expected);

        $this->assertSame($expected, (new BoardArticleCommand($service))->delete(9, $context));
    }

    public function testFacadeExposesOnlyPublicReaderAndCommandContracts(): void
    {
        $reader = $this->createMock(BoardArticleReaderInterface::class);
        $command = $this->createMock(BoardArticleCommandInterface::class);
        $api = new BoardExtensionApi($reader, $command);

        $this->assertSame($reader, $api->articles());
        $this->assertSame($command, $api->commands());
    }

    public function testBoardProviderBindsPublicApiFacade(): void
    {
        $container = DependencyContainer::getInstance();
        (new BoardProvider())->register($container);
        $container->set(BoardArticleService::class, $this->createMock(BoardArticleService::class));
        $container->set(BoardConfigService::class, $this->createMock(BoardConfigService::class));

        $api = $container->get(BoardExtensionApiInterface::class);

        $this->assertInstanceOf(BoardExtensionApiInterface::class, $api);
        $this->assertInstanceOf(BoardArticleReaderInterface::class, $api->articles());
        $this->assertInstanceOf(BoardArticleCommandInterface::class, $api->commands());
    }

    private function board(int $domainId, bool $global = false): BoardConfig
    {
        return BoardConfig::fromArray([
            'board_id' => 5,
            'domain_id' => $domainId,
            'group_id' => 1,
            'board_slug' => 'notice',
            'board_name' => 'Notice',
            'is_global' => $global ? 1 : 0,
        ]);
    }
}
