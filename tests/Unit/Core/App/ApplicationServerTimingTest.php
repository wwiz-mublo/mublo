<?php

namespace Tests\Unit\Core\App;

use Mublo\Core\App\Application;
use PHPUnit\Framework\TestCase;

final class ApplicationServerTimingTest extends TestCase
{
    public function testFormatsMeasuredApplicationStages(): void
    {
        $application = new class extends Application {
            public function formatTimings(array $timings): string
            {
                return $this->formatServerTiming($timings);
            }

            public function measuredPhpTime(int $startedAtNs): float
            {
                $this->setPhpStartedAtNs($startedAtNs);
                $this->recordPhpTiming();

                return $this->serverTimings['php'];
            }
        };

        self::assertSame(
            'boot;dur=12.3, context;dur=2045.7, extensions;dur=0.0',
            $application->formatTimings([
                'boot' => 12.34,
                'context' => 2045.67,
                'extensions' => 0.0,
            ])
        );

        $phpTime = $application->measuredPhpTime(hrtime(true) - 1_000_000_000);
        self::assertGreaterThanOrEqual(990.0, $phpTime);
        self::assertLessThan(1100.0, $phpTime);
    }
}
