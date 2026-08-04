<?php

namespace Tests\Unit\Rendering;

use Mublo\Core\Rendering\FrontViewContract;
use Mublo\Core\Rendering\FrontViewRuntime;
use Mublo\Core\Rendering\ViewContext;
use PHPUnit\Framework\TestCase;

final class FrontViewContractTest extends TestCase
{
    public function testPreviewContractAlwaysHasEveryPublicSection(): void
    {
        $mublo = FrontViewContract::empty();

        $this->assertSame(FrontViewContract::SECTIONS, array_keys($mublo));
        $this->assertSame(FrontViewContract::VERSION, $mublo['contractVersion']);
        $this->assertFalse($mublo['viewer']['available']);
        $this->assertTrue($mublo['runtime']['preview']);
    }

    public function testCacheSafeContractRemovesViewerAndRequestState(): void
    {
        $mublo = FrontViewContract::empty(true);
        $mublo['viewer'] = [
            'available' => true,
            'authenticated' => true,
            'member' => ['memberId' => 11],
            'notificationUnreadCount' => 4,
        ];
        $mublo['request'] = [
            'available' => true,
            'url' => 'https://example.test/private',
            'path' => '/private',
            'query' => ['token' => 'secret'],
        ];

        $runtime = new FrontViewRuntime();
        $runtime->initialize(new ViewContext('front'), $mublo);
        $safe = $runtime->getMublo(false);

        $this->assertFalse($safe['viewer']['available']);
        $this->assertNull($safe['viewer']['member']);
        $this->assertFalse($safe['request']['available']);
        $this->assertSame([], $safe['request']['query']);
        $this->assertFalse($safe['security']['available']);
        $this->assertSame('', $safe['security']['csrfToken']);
        $this->assertFalse($safe['runtime']['viewerAware']);
    }

    public function testAllFrontFragmentsUseSameViewContextAndReservedContract(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mublo-view-contract-');
        file_put_contents($path, '<?php echo $mublo["contractVersion"] . ":" . $page . ":" . get_debug_type($this);');

        try {
            $runtime = new FrontViewRuntime();
            $runtime->initialize(new ViewContext('front'), FrontViewContract::empty(true));

            $this->assertSame(
                '1:home:Mublo\\Core\\Rendering\\ViewContext',
                $runtime->render($path, ['page' => 'home'])
            );
        } finally {
            unlink($path);
        }
    }

    public function testExtensionsCannotOverwriteReservedMubloData(): void
    {
        $runtime = new FrontViewRuntime();
        $runtime->initialize(new ViewContext('front'), FrontViewContract::empty(true));

        $this->expectException(\InvalidArgumentException::class);
        $runtime->render(__FILE__, ['mublo' => []]);
    }

    public function testRuntimeRestoresOutputBufferLevelWhenViewThrows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mublo-view-contract-');
        file_put_contents(
            $path,
            '<?php ob_start(); echo "nested"; throw new RuntimeException("view failed");'
        );

        $runtime = new FrontViewRuntime();
        $runtime->initialize(new ViewContext('front'), FrontViewContract::empty(true));
        $initialLevel = ob_get_level();

        try {
            $runtime->render($path);
            $this->fail('Expected the view exception to be propagated.');
        } catch (\RuntimeException $e) {
            $this->assertSame('view failed', $e->getMessage());
            $this->assertSame($initialLevel, ob_get_level());
        } finally {
            while (ob_get_level() > $initialLevel) {
                ob_end_clean();
            }
            unlink($path);
        }
    }
}
