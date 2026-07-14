<?php

namespace Tests\Faq\Unit\Subscriber;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Plugin\Faq\Event\FaqContentChangedEvent;
use Mublo\Plugin\Faq\Subscriber\FaqBlockCacheSubscriber;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Service\Block\BlockRenderService;
use PHPUnit\Framework\TestCase;

final class FaqBlockCacheSubscriberTest extends TestCase
{
    protected function tearDown(): void
    {
        DependencyContainer::resetInstance();
        parent::tearDown();
    }

    public function testInvalidatesEachFaqRowOnlyOnce(): void
    {
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->expects($this->once())->method('findByContentType')->with(7, 'faq')
            ->willReturn([self::column(20), self::column(20), self::column(21)]);
        $render = $this->createMock(BlockRenderService::class);
        $invalidated = [];
        $render->expects($this->exactly(2))->method('invalidateRowCache')
            ->willReturnCallback(static function (int $rowId) use (&$invalidated): void {
                $invalidated[] = $rowId;
            });

        $container = DependencyContainer::getInstance();
        $container->set(BlockColumnRepository::class, $columns);
        $container->set(BlockRenderService::class, $render);

        (new FaqBlockCacheSubscriber($container))->onChanged(new FaqContentChangedEvent(7));

        $this->assertSame([20, 21], $invalidated);
    }

    private static function column(int $rowId): BlockColumn
    {
        return BlockColumn::fromArray([
            'column_id' => $rowId,
            'row_id' => $rowId,
            'domain_id' => 7,
            'content_type' => 'faq',
            'content_kind' => 'PLUGIN',
        ]);
    }
}
