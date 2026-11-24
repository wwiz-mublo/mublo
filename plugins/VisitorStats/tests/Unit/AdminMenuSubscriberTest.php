<?php
declare(strict_types=1);

namespace Tests\VisitorStats\Unit;

use Mublo\Plugin\VisitorStats\AdminMenuSubscriber;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;
use PHPUnit\Framework\TestCase;

final class AdminMenuSubscriberTest extends TestCase
{
    public function testVisitorStatsIsADirectTopLevelMenu(): void
    {
        $event = new AdminMenuBuildingEvent();
        (new AdminMenuSubscriber())->onAdminMenuBuilding($event);

        $items = $event->getGroup('plugin');
        self::assertCount(1, $items);
        self::assertSame('방문통계', $items[0]['label']);
        self::assertSame('/admin/visitor-stats/dashboard', $items[0]['url']);
        self::assertSame('P_VisitorStats_001', $items[0]['code']);
        self::assertSame([], $items[0]['submenu']);
    }
}
