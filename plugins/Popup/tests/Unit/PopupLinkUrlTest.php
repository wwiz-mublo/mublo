<?php

namespace Tests\Popup\Unit;

use Mublo\Plugin\Popup\Service\PopupService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 팝업 전체 링크 정제 — 저장 전에 위험한 스킴을 막고 스킴 없는 주소를 보정한다.
 */
class PopupLinkUrlTest extends TestCase
{
    public static function links(): array
    {
        return [
            'empty'           => ['', null],
            'spaces'          => ['   ', null],
            'null'            => [null, null],
            'root path'       => ['/shop/list', '/shop/list'],
            'anchor'          => ['#event', '#event'],
            'relative'        => ['./event', './event'],
            'https'           => [' https://example.com/a ', 'https://example.com/a'],
            'http upper'      => ['HTTP://example.com', 'HTTP://example.com'],
            'tel'             => ['tel:01012345678', 'tel:01012345678'],
            'mailto'          => ['mailto:a@example.com', 'mailto:a@example.com'],
            'no scheme'       => ['example.com/event', 'https://example.com/event'],
            'javascript'      => ['javascript:alert(1)', false],
            'javascript case' => ['JavaScript:alert(1)', false],
            'data'            => ['data:text/html,x', false],
        ];
    }

    #[DataProvider('links')]
    public function testNormalizeLinkUrl(mixed $input, string|null|false $expected): void
    {
        $service = (new \ReflectionClass(PopupService::class))->newInstanceWithoutConstructor();

        $this->assertSame($expected, $service->normalizeLinkUrl($input));
    }
}
