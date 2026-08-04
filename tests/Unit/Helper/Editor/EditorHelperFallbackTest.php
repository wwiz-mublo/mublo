<?php

declare(strict_types=1);

namespace Tests\Unit\Helper\Editor;

use Mublo\Helper\Editor\EditorHelper;
use PHPUnit\Framework\TestCase;

/**
 * EditorHelper textarea 폴백 테스트
 *
 * 에디터를 쓰지 않는(또는 어댑터를 찾지 못한) 경우의 폴백 마크업은
 * 프레임 CSS에 기대지 않고 스스로 폭을 잡아야 한다.
 */
class EditorHelperFallbackTest extends TestCase
{
    private string $previousEditor;

    protected function setUp(): void
    {
        $this->previousEditor = EditorHelper::getEditor();
        EditorHelper::setEditor('textarea');
    }

    protected function tearDown(): void
    {
        EditorHelper::setEditor($this->previousEditor);
    }

    public function testFallbackFillsContainerWidth(): void
    {
        $html = EditorHelper::html('article_content');

        $this->assertStringContainsString('width:100%', $html);
        $this->assertStringContainsString('box-sizing:border-box', $html);
    }

    public function testFallbackAppliesHeightOption(): void
    {
        $html = EditorHelper::html('article_content', '', ['height' => 400]);

        $this->assertStringContainsString('height:400px', $html);
    }

    public function testFallbackKeepsNameAndEscapesContent(): void
    {
        $html = EditorHelper::html('article_content', '<b>x</b>', [
            'name' => 'formData[content]',
            'placeholder' => '내용을 입력하세요',
        ]);

        $this->assertStringContainsString('name="formData[content]"', $html);
        $this->assertStringContainsString('placeholder="내용을 입력하세요"', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function testFallbackCarriesIdentifiableClass(): void
    {
        $html = EditorHelper::html('article_content');

        $this->assertStringContainsString('mublo-editor-fallback', $html);
    }

    public function testFallbackEmitsNoAssets(): void
    {
        $this->assertSame('', EditorHelper::css());
        $this->assertSame('', EditorHelper::js());
    }
}
