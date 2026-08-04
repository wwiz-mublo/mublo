<?php

namespace Tests\Unit\Core\Block\Renderer;

use Mublo\Core\Block\Renderer\IncludeRenderer;
use Mublo\Entity\Block\BlockColumn;
use PHPUnit\Framework\TestCase;

/**
 * include 블록은 views/Block/include/ 의 PHP 파일을 칸 안에서 실행하는 escape hatch 다.
 *
 * 일반 스킨과 같은 $mublo 계약만 제공하며 컨테이너/DB/세션은 노출하지 않는다.
 */
class IncludeRendererTest extends TestCase
{
    private string $includeDir;
    private array $created = [];

    protected function setUp(): void
    {
        $this->includeDir = MUBLO_ROOT_PATH . '/views/Block/include/';
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            @unlink($file);
        }
        $this->created = [];

    }

    public function testDoesNotExposeContainerServicesToIncludedFile(): void
    {
        $this->writeInclude('_test_inject.php', '<?php echo get_debug_type($db ?? null);');

        $html = $this->render('_test_inject.php');

        $this->assertSame('null', trim($html));
    }

    public function testProvidesCanonicalMubloContract(): void
    {
        $this->writeInclude('_test_contract.php', '<?php echo $mublo["contractVersion"] . "/" . ($mublo["runtime"]["preview"] ? "preview" : "live");');

        $this->assertSame('1/preview', trim($this->render('_test_contract.php')));
    }

    public function testIncludedOutputBecomesBlockHtml(): void
    {
        $this->writeInclude('_test_out.php', '<p>hello</p>');

        $this->assertStringContainsString('<p>hello</p>', $this->render('_test_out.php'));
    }

    public function testContentConfigAndParamsAreAvailable(): void
    {
        $this->writeInclude('_test_params.php', '<?php echo $params["greeting"] . "/" . $contentConfig["file"];');

        $column = BlockColumn::fromArray([
            'column_id' => 1,
            'content_type' => 'include',
            'content_config' => ['file' => '_test_params.php', 'params' => ['greeting' => 'hi']],
        ]);

        $this->assertSame('hi/_test_params.php', trim((new IncludeRenderer())->render($column)));
    }

    public function testExceptionRestoresOnlyBuffersOpenedByIncludedView(): void
    {
        $this->writeInclude(
            '_test_throw.php',
            '<?php ob_start(); echo "nested"; throw new RuntimeException("include failed");'
        );

        $initialLevel = ob_get_level();
        ob_start();
        echo 'outer';

        try {
            $html = $this->render('_test_throw.php');
            $outer = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $initialLevel) {
                ob_end_clean();
            }
        }

        $this->assertSame('<!-- Include error -->', trim($html));
        $this->assertSame('outer', $outer);
        $this->assertSame($initialLevel, ob_get_level());
    }

    public function testTraversalIsRejected(): void
    {
        $html = $this->render('../../../bootstrap.php');

        $this->assertSame('', $html, '방문자 화면에는 include 진단 문구를 노출하지 않는다.');
    }

    public function testNonPhpFileIsRejected(): void
    {
        $this->assertSame('', $this->render('evil.sh'));
    }

    public function testMissingFileConfigIsReported(): void
    {
        $column = BlockColumn::fromArray(['column_id' => 1, 'content_type' => 'include']);

        $this->assertSame('', (new IncludeRenderer())->render($column));
    }

    private function writeInclude(string $name, string $body): void
    {
        $path = $this->includeDir . $name;
        file_put_contents($path, $body);
        $this->created[] = $path;
    }

    private function render(string $file): string
    {
        $column = BlockColumn::fromArray([
            'column_id' => 1,
            'content_type' => 'include',
            'content_config' => ['file' => $file],
        ]);

        return (new IncludeRenderer())->render($column);
    }
}
