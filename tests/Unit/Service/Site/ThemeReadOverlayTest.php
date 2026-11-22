<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Site;

use Mublo\Contract\Site\ThemeReadSnapshot;
use Mublo\Service\Site\ThemeReadOverlay;
use PHPUnit\Framework\TestCase;

final class ThemeReadOverlayTest extends TestCase
{
    public function testEmptyConfigsAreDifferentFromNoOverlay(): void
    {
        $overlay = new ThemeReadOverlay();
        $this->assertNull($overlay->snapshot(7));
        $snapshot = new ThemeReadSnapshot(7, [], [], [], []);
        $this->assertTrue($overlay->replace($snapshot)->isSuccess());
        $this->assertSame($snapshot, $overlay->snapshot(7));
        $overlay->clear();
        $this->assertNull($overlay->snapshot(7));
    }

    public function testRejectsNonPortableValuesWithoutReplacingSnapshot(): void
    {
        $overlay = new ThemeReadOverlay();
        $valid = new ThemeReadSnapshot(7, ['site_title' => '미리보기'], [], [], []);
        $this->assertTrue($overlay->replace($valid)->isSuccess());
        $invalid = new ThemeReadSnapshot(7, ['value' => new \stdClass()], [], [], []);
        $this->assertTrue($overlay->replace($invalid)->isFailure());
        $this->assertSame($valid, $overlay->snapshot(7));
    }
}
