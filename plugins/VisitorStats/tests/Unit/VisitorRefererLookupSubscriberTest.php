<?php
declare(strict_types=1);

namespace Tests\VisitorStats\Unit;

use Mublo\Contract\Tracking\VisitorRefererLookupEvent;
use Mublo\Plugin\VisitorStats\Repository\VisitorLogRepository;
use Mublo\Plugin\VisitorStats\Subscriber\VisitorRefererLookupSubscriber;
use PHPUnit\Framework\TestCase;

class VisitorRefererLookupSubscriberTest extends TestCase
{
    public function testSubscribesToTheContractEvent(): void
    {
        $this->assertSame(
            [VisitorRefererLookupEvent::class => 'onLookup'],
            VisitorRefererLookupSubscriber::getSubscribedEvents()
        );
    }

    public function testFillsRefererFromVisitLog(): void
    {
        $repo = $this->createMock(VisitorLogRepository::class);
        $repo->expects($this->exactly(2))->method('findVisitAt')
            ->willReturnCallback(fn(int $did, ?string $sid, ?string $ip, string $at) => $sid === 'abc'
                ? ['referer_type' => 'search', 'referer_domain' => 'naver', 'referer_url' => 'https://search.naver.com/x', 'landing_url' => '/goods/1']
                : null);

        $event = new VisitorRefererLookupEvent([
            11 => ['domain_id' => 3, 'session_id' => 'abc', 'ip' => '1.2.3.4', 'at' => '2026-11-14 10:00:00'],
            12 => ['domain_id' => 3, 'session_id' => 'zzz', 'ip' => '5.6.7.8', 'at' => '2026-11-14 10:00:00'],
            13 => ['domain_id' => 0, 'session_id' => 'abc', 'ip' => '1.2.3.4', 'at' => '2026-11-14 10:00:00'],
            14 => ['domain_id' => 3, 'session_id' => 'abc', 'ip' => '1.2.3.4', 'at' => ''],
        ]);
        (new VisitorRefererLookupSubscriber($repo))->onLookup($event);

        $this->assertSame(
            ['type' => 'search', 'domain' => 'naver', 'url' => 'https://search.naver.com/x', 'landing_url' => '/goods/1'],
            $event->getResult(11)
        );
        $this->assertNull($event->getResult(12));
        $this->assertNull($event->getResult(13));
        $this->assertNull($event->getResult(14));
    }

    public function testEmptyRefererTypeFallsBackToDirect(): void
    {
        $repo = $this->createMock(VisitorLogRepository::class);
        $repo->method('findVisitAt')->willReturn(
            ['referer_type' => '', 'referer_domain' => null, 'referer_url' => null, 'landing_url' => '/']
        );

        $event = new VisitorRefererLookupEvent(['a' => ['domain_id' => 1, 'session_id' => 's', 'ip' => null, 'at' => '2026-11-14 10:00:00']]);
        (new VisitorRefererLookupSubscriber($repo))->onLookup($event);

        $this->assertSame(['type' => 'direct', 'domain' => '', 'url' => '', 'landing_url' => '/'], $event->getResult('a'));
    }

    public function testStopsAtLookupLimit(): void
    {
        $repo = $this->createMock(VisitorLogRepository::class);
        $repo->expects($this->exactly(VisitorRefererLookupSubscriber::MAX_LOOKUPS))->method('findVisitAt')->willReturn(null);

        $lookups = [];
        for ($i = 0; $i < VisitorRefererLookupSubscriber::MAX_LOOKUPS + 5; $i++) {
            $lookups[$i] = ['domain_id' => 1, 'session_id' => "s{$i}", 'ip' => null, 'at' => '2026-11-14 10:00:00'];
        }
        (new VisitorRefererLookupSubscriber($repo))->onLookup(new VisitorRefererLookupEvent($lookups));
    }

    public function testRepositoryFailureLeavesResultsEmpty(): void
    {
        $repo = $this->createMock(VisitorLogRepository::class);
        $repo->method('findVisitAt')->willThrowException(new \RuntimeException('no table'));

        $event = new VisitorRefererLookupEvent([1 => ['domain_id' => 1, 'session_id' => 'a', 'ip' => null, 'at' => '2026-11-14 10:00:00']]);
        (new VisitorRefererLookupSubscriber($repo))->onLookup($event);

        $this->assertSame([], $event->getResults());
    }
}
