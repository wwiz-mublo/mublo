<?php

namespace Tests\Banner\Unit\Subscriber;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Plugin\Banner\Event\BannerContentChangedEvent;
use Mublo\Plugin\Banner\Subscriber\BannerBlockCacheSubscriber;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Service\Block\BlockRenderService;
use PHPUnit\Framework\TestCase;

final class BannerBlockCacheSubscriberTest extends TestCase
{
    protected function tearDown(): void
    {
        DependencyContainer::resetInstance();
        parent::tearDown();
    }

    public function testInvalidatesEachBannerRowOnlyOnce(): void
    {
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->expects($this->once())->method('findByContentType')->with(7, 'banner')
            ->willReturn([self::column(10), self::column(10), self::column(11)]);
        $render = $this->createMock(BlockRenderService::class);
        $invalidated = [];
        $render->expects($this->exactly(2))->method('invalidateRowCache')
            ->willReturnCallback(static function (int $rowId) use (&$invalidated): void {
                $invalidated[] = $rowId;
            });

        $container = DependencyContainer::getInstance();
        $container->set(BlockColumnRepository::class, $columns);
        $container->set(BlockRenderService::class, $render);

        (new BannerBlockCacheSubscriber($container))->onChanged(new BannerContentChangedEvent(7));

        $this->assertSame([10, 11], $invalidated);
    }

    private static function column(int $rowId): BlockColumn
    {
        return BlockColumn::fromArray([
            'column_id' => $rowId,
            'row_id' => $rowId,
            'domain_id' => 7,
            'content_type' => 'banner',
            'content_kind' => 'PLUGIN',
        ]);
    }
}
