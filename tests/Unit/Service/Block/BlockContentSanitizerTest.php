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

    public function testSanitizeColumnDataPreservesJsonStringShape(): void
    {
        $sanitizer = new BlockContentSanitizer();

        $result = $sanitizer->sanitizeColumnData([
            'content_type' => 'html',
            'content_config' => json_encode([
                'html' => '<p onmouseover="alert(1)">safe</p>',
                'css' => '</style>.x{color:red}',
            ]),
        ]);

        $this->assertIsString($result['content_config']);

        $config = json_decode($result['content_config'], true);
        $this->assertIsArray($config);
        $this->assertStringContainsString('safe', $config['html']);
        $this->assertStringNotContainsStringIgnoringCase('onmouseover', $config['html']);
        $this->assertStringNotContainsStringIgnoringCase('</style', $config['css']);
    }

    public function testNonHtmlColumnDataIsUnchanged(): void
    {
        $sanitizer = new BlockContentSanitizer();
        $data = [
            'content_type' => 'board',
            'content_config' => ['html' => '<script>alert(1)</script>'],
        ];

        $this->assertSame($data, $sanitizer->sanitizeColumnData($data));
    }
}
