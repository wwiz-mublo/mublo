<?php

namespace Tests\Unit\Service\AI;

use Mublo\Service\AI\HtmlBlockVisibleCopyFilter;
use PHPUnit\Framework\TestCase;

class HtmlBlockVisibleCopyFilterTest extends TestCase
{
    public function testRemovesReferenceMaterialNarrationButKeepsVisitorCopy(): void
    {
        $result = (new HtmlBlockVisibleCopyFilter())->filter(
            '<section><h2>서비스 장점</h2>'
            . '<p>참고자료의 방향에 맞춰 서비스의 강점을 세 가지 카드로 간결하게 정리했습니다.</p>'
            . '<article><h3>빠른 실행</h3><p>필요한 기능을 빠르게 적용합니다.</p></article></section>'
        );

        $this->assertStringNotContainsString('참고자료의 방향에 맞춰', $result['html']);
        $this->assertStringContainsString('<h2>서비스 장점</h2>', $result['html']);
        $this->assertStringContainsString('필요한 기능을 빠르게 적용합니다.', $result['html']);
        $this->assertCount(1, $result['removed']);
    }

    public function testRemovesNestedMetaNarrationAsOneVisibleParagraph(): void
    {
        $result = (new HtmlBlockVisibleCopyFilter())->filter(
            '<div><p><strong>요청하신 방향에 따라</strong> 핵심 가치를 세 개의 카드로 구성했습니다.</p>'
            . '<p>운영 속도를 높입니다.</p></div>'
        );

        $this->assertStringNotContainsString('<strong>', $result['html']);
        $this->assertStringContainsString('<p>운영 속도를 높입니다.</p>', $result['html']);
        $this->assertCount(1, $result['removed']);
    }

    public function testKeepsLegitimateReferenceMaterialCopy(): void
    {
        $result = (new HtmlBlockVisibleCopyFilter())->filter(
            '<section><h2>참고자료</h2><p>제품 사양과 이용 방법을 확인하세요.</p></section>'
        );

        $this->assertStringContainsString('<h2>참고자료</h2>', $result['html']);
        $this->assertSame([], $result['removed']);
    }
}
