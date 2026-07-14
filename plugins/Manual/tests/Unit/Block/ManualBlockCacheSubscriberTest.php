<?php

namespace Tests\Manual\Unit\Block;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Plugin\Manual\Event\ManualContentChangedEvent;
use Mublo\Plugin\Manual\Subscriber\ManualBlockCacheSubscriber;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Service\Block\BlockRenderService;
use PHPUnit\Framework\TestCase;

final class ManualBlockCacheSubscriberTest extends TestCase
{
    protected function tearDown(): void
    {
        DependencyContainer::resetInstance();
        parent::tearDown();
    }

    public function testInvalidatesEachAffectedRowOnlyOnce(): void
    {
        $columns = $this->createMock(BlockColumnRepository::class);
        $columns->method('findByContentType')->willReturnCallback(
            static fn (int $domainId, string $type): array => match ($type) {
                'manual_books' => [self::column(10), self::column(11)],
                'manual_page' => [self::column(10)],
                default => [],
            }
        );
        $render = $this->createMock(BlockRenderService::class);
        $invalidated = [];
        $render->expects($this->exactly(2))->method('invalidateRowCache')
            ->willReturnCallback(static function (int $rowId) use (&$invalidated): void {
                $invalidated[] = $rowId;
            });

        $container = DependencyContainer::getInstance();
        $container->set(BlockColumnRepository::class, $columns);
        $container->set(BlockRenderService::class, $render);

        (new ManualBlockCacheSubscriber($container))->onContentChanged(
            new ManualContentChangedEvent(7, 'page_updated')
        );

        sort($invalidated);
        $this->assertSame([10, 11], $invalidated);
    }

    private static function column(int $rowId): BlockColumn
    {
        return BlockColumn::fromArray([
            'column_id' => $rowId,
            'row_id' => $rowId,
            'domain_id' => 7,
            'content_type' => 'manual_books',
            'content_kind' => 'PLUGIN',
            'is_active' => 1,
        ]);
    }
}
