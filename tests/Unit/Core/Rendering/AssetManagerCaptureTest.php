<?php

namespace Tests\Unit\Core\Rendering;

use Mublo\Core\Rendering\AssetManager;
use PHPUnit\Framework\TestCase;

/**
 * AssetManager 캡처 창(beginCapture/endCapture) — 블록 행 캐시의 CSS 유실 방지.
 *
 * 회귀: 여러 행이 같은 스킨 CSS를 공유할 때, 먼저 렌더된 행이 CSS를 "선점"하고
 * 나중 행은 addCss dedup에 걸려 캐시 CSS가 비게 되던 버그(공유 board-basic 스킨만
 * 배치된 페이지에서 빈 상태 박스 스타일 유실). 캡처 창은 dedup과 무관하게
 * "이 렌더가 필요로 한" 경로를 기록해 모든 소유 행에 온전히 저장한다.
 */
class AssetManagerCaptureTest extends TestCase
{
    public function testCaptureRecordsSharedCssEvenWhenDeduped(): void
    {
        $assets = new AssetManager();
        $shared = '/serve/package/Board/views/Block/board/basic/style.css';

        // 첫 행: 공유 CSS를 등록·소유
        $assets->beginCapture();
        $assets->addCss($shared);
        $first = $assets->endCapture();

        // 나중 행: 같은 CSS — 전역 버킷에선 dedup 되지만 캡처는 기록해야 한다
        $assets->beginCapture();
        $assets->addCss($shared);
        $second = $assets->endCapture();

        $this->assertSame([$shared], $first['css']);
        $this->assertSame([$shared], $second['css'], '공유 CSS가 나중 행 캡처에서 유실되면 안 된다');
    }

    public function testCaptureDedupesWithinSingleWindow(): void
    {
        $assets = new AssetManager();

        $assets->beginCapture();
        $assets->addCss('/a.css');
        $assets->addCss('/a.css');
        $assets->addCss('/b.css');
        $captured = $assets->endCapture();

        $this->assertSame(['/a.css', '/b.css'], $captured['css']);
    }

    public function testNestedCaptureRecordsInnerAddsInOuterFrame(): void
    {
        $assets = new AssetManager();

        $assets->beginCapture();          // 바깥
        $assets->addCss('/outer.css');
        $assets->beginCapture();          // 안쪽(중첩 렌더)
        $assets->addCss('/inner.css');
        $inner = $assets->endCapture();
        $outer = $assets->endCapture();

        $this->assertSame(['/inner.css'], $inner['css']);
        $this->assertSame(['/outer.css', '/inner.css'], $outer['css'], '안쪽에서 등록한 CSS도 바깥 프레임이 필요로 한다');
    }

    public function testCaptureTracksJsToo(): void
    {
        $assets = new AssetManager();

        $assets->beginCapture();
        $assets->addJs('/app.js');
        $captured = $assets->endCapture();

        $this->assertSame(['/app.js'], $captured['js']);
    }

    public function testAddCssWithoutCaptureDoesNotError(): void
    {
        $assets = new AssetManager();
        $assets->addCss('/no-window.css');

        $this->assertSame(['/no-window.css'], $assets->getCssPaths());
    }
}
