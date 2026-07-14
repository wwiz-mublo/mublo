<?php

namespace Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;

class MubloEditorAdapterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/public/assets/lib/editor/mublo-editor/editor.lib.php';
    }

    public function testSyncJsEncodesEditorIdAsJavascriptString(): void
    {
        $script = \Mublo_editor_sync_js('editor";alert(1);//');

        $this->assertStringContainsString('MubloEditor.get("editor\\u0022;alert(1);\/\/")?.sync()', $script);
        $this->assertStringNotContainsString('MubloEditor.get("editor";alert(1);//")', $script);
    }
}
