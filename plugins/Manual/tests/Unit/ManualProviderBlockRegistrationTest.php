<?php

namespace Tests\Manual\Unit;

use Mublo\Contract\Block\BlockContentCacheInvalidatorInterface;
use Mublo\Core\Block\BlockRegistry;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Http\Request;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\Manual\Block\ManualBookItemsProvider;
use Mublo\Plugin\Manual\Block\ManualPageItemsProvider;
use Mublo\Plugin\Manual\ManualProvider;
use PHPUnit\Framework\TestCase;

final class ManualProviderBlockRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        BlockRegistry::reset();
        DependencyContainer::resetInstance();
    }

    protected function tearDown(): void
    {
        BlockRegistry::reset();
        DependencyContainer::resetInstance();
        parent::tearDown();
    }

    public function testRegistersAllManualBlockTypesWithPortableSelectors(): void
    {
        $container = DependencyContainer::getInstance();
        $container->set(Database::class, $this->createMock(Database::class));
        $container->set(EventDispatcher::class, new EventDispatcher());
        $container->set(ContractRegistry::class, new ContractRegistry());
        $container->set(
            BlockContentCacheInvalidatorInterface::class,
            $this->createMock(BlockContentCacheInvalidatorInterface::class)
        );

        $provider = new ManualProvider();
        $provider->register($container);
        $provider->boot($container, new Context(new Request('GET', '/')));

        $books = BlockRegistry::getContentType('manual_books');
        $toc = BlockRegistry::getContentType('manual_toc');
        $page = BlockRegistry::getContentType('manual_page');
        $recent = BlockRegistry::getContentType('manual_recent');

        $this->assertSame(ManualBookItemsProvider::class, $books['options']['itemsProvider']);
        $this->assertSame(0, $books['options']['maxItems']);
        $this->assertSame(1, $toc['options']['maxItems']);
        $this->assertSame(ManualPageItemsProvider::class, $page['options']['itemsProvider']);
        $this->assertSame(1, $page['options']['maxItems']);
        $this->assertSame('MubloBlockManualRecent', $recent['options']['adminScriptInit']);
        $this->assertArrayNotHasKey('noCache', $recent['options']);
    }
}
