<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Block;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Event\Block\BlockPositionRenderingEvent;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Infrastructure\Cache\CacheInterface;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Service\Block\BlockRenderService;
use PHPUnit\Framework\TestCase;

/**
 * renderPosition() 직전 확장점 — 위치 대체·캐시 끄기
 */
class BlockPositionRenderingEventTest extends TestCase
{
    private ?string $previousDebug = null;

    protected function setUp(): void
    {
        // 디버그 모드는 캐시를 늘 끄고 주석을 붙여 단언을 흐린다
        $this->previousDebug = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'false';
    }

    protected function tearDown(): void
    {
        if ($this->previousDebug === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $this->previousDebug;
        }
    }

    public function testReplacementSkipsStoredBlocksAndCache(): void
    {
        $rows = $this->createMock(BlockRowRepository::class);
        $rows->expects($this->never())->method('findByPosition');
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('get');
        $cache->expects($this->never())->method('set');

        $seen = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BlockPositionRenderingEvent::class, function (BlockPositionRenderingEvent $e) use (&$seen): void {
            $seen = [$e->getDomainId(), $e->getPosition(), $e->getMenuCode(), $e->isMainScreen()];
            $e->replaceWith('<main>preview</main>');
        });

        $html = $this->service($rows, $cache, $dispatcher)->renderPosition(5, 'index', null, true, true);

        $this->assertSame('<main>preview</main>', $html);
        $this->assertSame([5, 'index', null, true], $seen);
    }

    public function testHigherPriorityReplacementWins(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BlockPositionRenderingEvent::class, fn(BlockPositionRenderingEvent $e) => $e->replaceWith('low'), 0);
        $dispatcher->addListener(BlockPositionRenderingEvent::class, fn(BlockPositionRenderingEvent $e) => $e->replaceWith('high'), 10);

        $html = $this->service($this->createMock(BlockRowRepository::class), $this->createMock(CacheInterface::class), $dispatcher)
            ->renderPosition(1, 'index');

        $this->assertSame('high', $html);
    }

    public function testDisableCacheReadsRowsFromRepositoryWithoutTouchingCache(): void
    {
        $rows = $this->createMock(BlockRowRepository::class);
        $rows->expects($this->once())->method('findByPosition')->with(2, 'left', 'm01', false)->willReturn([]);
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('get');
        $cache->expects($this->never())->method('set');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BlockPositionRenderingEvent::class, fn(BlockPositionRenderingEvent $e) => $e->disableCache());

        $html = $this->service($rows, $cache, $dispatcher)->renderPosition(2, 'left', 'm01');

        $this->assertSame('', $html);
    }

    public function testWithoutListenerUsesCacheAsBefore(): void
    {
        $rows = $this->createMock(BlockRowRepository::class);
        $rows->expects($this->never())->method('findByPosition');
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('get')->willReturn([]);

        $this->assertSame('', $this->service($rows, $cache, new EventDispatcher())->renderPosition(1, 'index'));
    }

    public function testWorksWithoutDispatcherForBackwardCompatibility(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('get')->willReturn([]);

        $service = new BlockRenderService(
            $this->createMock(BlockRowRepository::class),
            $this->createMock(BlockColumnRepository::class),
            $cache,
            $this->createMock(DependencyContainer::class)
        );

        $this->assertSame('', $service->renderPosition(1, 'index'));
    }

    public function testEventDefaults(): void
    {
        $event = new BlockPositionRenderingEvent(1, 'right');

        $this->assertNull($event->getMenuCode());
        $this->assertFalse($event->isMainScreen());
        $this->assertFalse($event->isReplaced());
        $this->assertNull($event->getReplacement());
        $this->assertFalse($event->isCacheDisabled());
        $this->assertFalse($event->isPropagationStopped());

        $event->replaceWith('');
        $this->assertTrue($event->isReplaced(), '빈 문자열 대체는 "그 위치를 비운다"는 뜻이다');
        $this->assertTrue($event->isPropagationStopped());
    }

    private function service(BlockRowRepository $rows, CacheInterface $cache, EventDispatcher $dispatcher): BlockRenderService
    {
        return new BlockRenderService(
            $rows,
            $this->createMock(BlockColumnRepository::class),
            $cache,
            $this->createMock(DependencyContainer::class),
            null,
            $dispatcher
        );
    }
}
