<?php
namespace Tests\Unit\Service\AI;

use Mublo\Service\AI\ScopedCssSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScopedCssSanitizerTest extends TestCase
{
    public function testContainerRelativeTypographyUnitsArePreserved(): void
    {
        $css = (new ScopedCssSanitizer())->sanitize(
            '.display { font-size: clamp(2rem, 6cqi, 4.75rem); padding: clamp(1rem, 4cqi, 4rem); }',
            'mublo-block-test'
        );

        $this->assertStringContainsString('font-size: clamp(2rem, 6cqi, 4.75rem)', $css);
        $this->assertStringContainsString('padding: clamp(1rem, 4cqi, 4rem)', $css);
    }

    public function testPrefixesEverySelectorWithBlockScope(): void
    {
        $css = (new ScopedCssSanitizer())->sanitize('.card, .title:hover { color: red; }', 'mublo-block-test');
        $this->assertStringContainsString('.mublo-block-test .card, .mublo-block-test .title:hover {', $css);
    }

    #[DataProvider('dangerousCss')]
    public function testRejectsDangerousOrGlobalCss(string $css): void
    {
        $this->assertSame('', (new ScopedCssSanitizer())->sanitize($css, 'scope'));
    }

    public function testKeepsSafeDeclarationsAndReportsDroppedProperties(): void
    {
        $result = (new ScopedCssSanitizer())->sanitizeWithReport(
            '.card { color:red; position:fixed; padding:10px; } unexpected',
            'scope'
        );
        $this->assertStringContainsString('color: red', $result['css']);
        $this->assertStringContainsString('padding: 10px', $result['css']);
        $this->assertStringNotContainsString('position', $result['css']);
        $this->assertNotEmpty($result['warnings']);
    }

    public static function dangerousCss(): array
    {
        return [
            ['body { display:none; }'],
            [':root { --x:red; }'],
            ['.x { background:url(https://example.com/a.png); }'],
            ['@import "https://example.com/a.css";'],
            ['.x { width:expression(alert(1)); }'],
            ['h2 { color:red; }'],
            ['.x::before { content:"x"; }'],
            ['.x { position:fixed; }'],
            ['.x { color:red !important; }'],
            ['.x { color:red; '],
        ];
    }
}
