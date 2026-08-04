<?php

namespace Tests\Unit\Service\Block;

use Mublo\Service\Block\BlockContentSanitizer;
use PHPUnit\Framework\TestCase;

class BlockContentSanitizerTest extends TestCase
{
    public function testSanitizeHtmlConfigRemovesActiveHtmlButKeepsJsChannel(): void
    {
        $sanitizer = new BlockContentSanitizer();

        $result = $sanitizer->sanitizeHtmlConfig([
            'html' => '<p onclick="alert(1)">safe</p><script>alert(1)</script>',
            'slides' => [
                ['html' => '<img src="/x.png" onerror="alert(1)">'],
            ],
            'css' => '.x{color:red}</style><script>alert(1)</script>',
            'js' => 'console.log("allowed channel");',
        ]);

        $this->assertStringContainsString('safe', $result['html']);
        $this->assertStringNotContainsStringIgnoringCase('<script', $result['html']);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $result['html']);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $result['slides'][0]['html']);
        $this->assertStringNotContainsStringIgnoringCase('</style', $result['css']);
        $this->assertSame('console.log("allowed channel");', $result['js']);
    }

    public function testSanitizeHtmlConfigRemovesReassembledStyleBreakout(): void
    {
        $sanitizer = new BlockContentSanitizer();

        $result = $sanitizer->sanitizeHtmlConfig([
            'css' => '.x{color:red}</st</styleyle><script>alert(1)</script>',
        ]);

        $this->assertStringNotContainsStringIgnoringCase('</style', $result['css']);
    }

    public function testColumnContainsScriptReadsJsonStringConfig(): void
    {
        $sanitizer = new BlockContentSanitizer();

        $this->assertTrue($sanitizer->columnContainsScript([
            'content_config' => json_encode(['js' => 'console.log("x");']),
        ]));
    }

    public function testColumnContainsScriptTraversesAuthoritativeStackContents(): void
    {
        $sanitizer = new BlockContentSanitizer();

        $this->assertTrue($sanitizer->columnContainsScript([
            'content_mode' => 'stack',
            'content_config' => ['js' => 'mirror is ignored'],
            'contents' => [
                ['content_type' => 'html', 'content_config' => ['html' => '<p>first</p>']],
                ['content_type' => 'html', 'content_config' => ['js' => 'console.log("child")']],
            ],
        ]));

        $this->assertFalse($sanitizer->columnContainsScript([
            'content_mode' => 'stack',
            'content_config' => ['js' => 'stale scalar mirror'],
            'contents' => [
                ['content_type' => 'html', 'content_config' => ['html' => '<p>safe</p>']],
            ],
        ]));
    }

    // 칸 저장 payload 정화는 실제 저장 경로인 BlockColumnPayloadNormalizer 에서 검증한다
    // (JSON 문자열 형태 보존·on* 제거·</style 무력화, 그리고 비-HTML 타입 미변경).
}
