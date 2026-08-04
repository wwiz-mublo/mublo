<?php

namespace Tests\Board\Unit\Controller;

use Mublo\Packages\Board\Controller\Front\BoardController;
use Tests\Board\TestCase;

/**
 * 조회수 중복 방지 쿠키(article_viewed)의 상한 검증.
 *
 * 이 쿠키는 path=/ 라 사이트 전체 요청에 실려 나가는데 상한이 없었다. 하루 동안
 * 본 글 수만큼 계속 자라고, 브라우저 한도(보통 4KB)를 넘으면 Set-Cookie 가 조용히
 * 무시돼 중복 방지가 통째로 풀린다 — 그때부터 조회수가 부풀기 시작한다.
 *
 * 컨트롤러 전체를 세우지 않고 순수 목록 연산만 리플렉션으로 확인한다.
 */
class ViewedCookieTest extends TestCase
{
    private \ReflectionMethod $parse;
    private \ReflectionMethod $append;
    private int $max;

    protected function setUp(): void
    {
        parent::setUp();

        $refl = new \ReflectionClass(BoardController::class);

        $this->parse = $refl->getMethod('parseViewedIds');
        $this->parse->setAccessible(true);

        $this->append = $refl->getMethod('appendViewedId');
        $this->append->setAccessible(true);

        $this->max = $refl->getConstant('VIEWED_COOKIE_MAX');
    }

    private function controller(): BoardController
    {
        return (new \ReflectionClass(BoardController::class))->newInstanceWithoutConstructor();
    }

    public function testAppendingBeyondTheCapDropsOldestFirst(): void
    {
        $ids = array_map('strval', range(1, $this->max));

        $result = $this->append->invoke($this->controller(), $ids, 999999);

        $this->assertCount($this->max, $result, '상한을 넘겨 자라면 안 된다');
        $this->assertSame('999999', end($result), '방금 본 글은 남아 있어야 한다');
        $this->assertNotContains('1', $result, '가장 오래된 항목이 밀려나야 한다');
    }

    public function testCappedCookieStaysWellUnderTheBrowserLimit(): void
    {
        // 7자리 ID 로 최악을 가정해도 4KB 한도에 여유가 있어야 한다.
        $ids = array_map('strval', range(1000000, 1000000 + $this->max - 1));

        $this->assertLessThan(4096, strlen(implode('.', $ids)));
    }

    public function testOversizedIncomingCookieIsTrimmedOnRead(): void
    {
        // 이미 커진 쿠키를 들고 오는 방문자도 다음 조회에서 정리돼야 한다.
        $cookie = implode('.', array_map('strval', range(1, $this->max * 3)));

        $result = $this->parse->invoke($this->controller(), $cookie);

        $this->assertCount($this->max, $result);
        $this->assertSame((string) ($this->max * 3), end($result), '최근 것을 남긴다');
    }

    public function testNonNumericEntriesAreDropped(): void
    {
        $result = $this->parse->invoke($this->controller(), '10..20.abc.-3.30');

        $this->assertSame(['10', '20', '30'], $result);
    }

    public function testEmptyCookieYieldsEmptyList(): void
    {
        $this->assertSame([], $this->parse->invoke($this->controller(), ''));
    }

    public function testAppendKeepsExistingEntriesWhenUnderTheCap(): void
    {
        $result = $this->append->invoke($this->controller(), ['10', '20'], 30);

        $this->assertSame(['10', '20', '30'], $result);
    }
}
