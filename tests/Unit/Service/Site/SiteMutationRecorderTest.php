<?php

namespace Tests\Unit\Service\Site;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Site\SiteMutationRecordedEvent;
use Mublo\Infrastructure\Database\Database;
use Mublo\Service\Site\SiteMutationRecorder;
use PHPUnit\Framework\TestCase;

final class SiteMutationRecorderTest extends TestCase
{
    public function testRecordsRevisionsInsideTransactionAndDispatchesOnlyAfterCommit(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('inTransaction')->willReturn(true);
        $db->expects(self::exactly(3))->method('execute')->willReturn(1);
        $db->expects(self::exactly(3))->method('selectOne')->willReturnOnConsecutiveCalls(
            ['revision' => 7],
            ['resource_type' => 'block', 'revision' => 4, 'fingerprint' => str_repeat('a', 64)],
            ['resource_type' => 'menu_children', 'revision' => 9, 'fingerprint' => str_repeat('b', 64)]
        );
        $db->expects(self::once())->method('insert')->with(
            self::stringContains('INSERT INTO site_revisions'),
            self::callback(static function (array $params): bool {
                $resources = json_decode((string) $params[4], true, 512, JSON_THROW_ON_ERROR);
                return $params[0] === 3
                    && $params[1] === 7
                    && $params[2] === SiteMutationContext::SOURCE_EXTENSION
                    && $params[3] === 'change-set-1'
                    && array_column($resources, 'resource_id') === ['block:12', 'menu_children:root'];
            })
        )->willReturn(41);

        $afterCommit = null;
        $db->expects(self::once())->method('afterCommit')->willReturnCallback(
            static function (callable $callback) use (&$afterCommit): void {
                $afterCommit = $callback;
            }
        );

        $events = new EventDispatcher();
        $received = [];
        $events->addListener(
            SiteMutationRecordedEvent::class,
            static function (SiteMutationRecordedEvent $event) use (&$received): void {
                $received[] = $event->receipt;
            }
        );

        $context = new SiteMutationContext(3, SiteMutationContext::SOURCE_EXTENSION, 'change-set-1');
        $context
            ->changed('menu_children:root', 'menu_children', str_repeat('b', 64))
            ->changed('block:12', 'block', str_repeat('a', 64));

        $receipt = (new SiteMutationRecorder($db, $events))->record($context);

        self::assertSame(41, $receipt->eventId);
        self::assertSame(7, $receipt->siteRevision);
        self::assertSame(['block:12' => 4, 'menu_children:root' => 9], $receipt->resourceRevisions);
        self::assertSame([], $received, '최상위 commit 전에는 외부 이벤트를 발행하면 안 된다.');
        self::assertIsCallable($afterCommit);

        $afterCommit();
        self::assertSame([$receipt], $received);
    }

    public function testRejectsRecordingOutsideTransaction(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('inTransaction')->willReturn(false);
        $db->expects(self::never())->method('execute');
        $context = (new SiteMutationContext(1, SiteMutationContext::SOURCE_ADMIN))
            ->changed('config:site', 'config', str_repeat('a', 64));

        $this->expectException(\LogicException::class);
        (new SiteMutationRecorder($db))->record($context);
    }

    public function testReadsOnlyRequestedResourceRevisions(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects(self::once())->method('select')->with(
            self::stringContains('resource_id IN (?, ?)'),
            [2, 'menu:2', 'block:1']
        )->willReturn([
            ['resource_id' => 'menu:2', 'resource_type' => 'menu', 'revision' => '5', 'fingerprint' => str_repeat('b', 64)],
            ['resource_id' => 'block:1', 'resource_type' => 'block', 'revision' => '3', 'fingerprint' => str_repeat('a', 64)],
        ]);

        $result = (new SiteMutationRecorder($db))->resourceRevisions(2, ['menu:2', 'block:1', 'menu:2']);

        self::assertSame(['block:1', 'menu:2'], array_keys($result));
        self::assertSame(3, $result['block:1']['revision']);
    }
}
