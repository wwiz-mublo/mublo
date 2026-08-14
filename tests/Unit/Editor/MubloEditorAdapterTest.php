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

    /** 플러그인은 레지스트리 순서로 정리된다 — 뷰어보다 팩이 먼저 실리면 등록이 어긋난다. */
    public function testEnabledPluginsKeepRegistryOrder(): void
    {
        $enabled = \_Mublo_editor_enabled_plugins(['plugins' => ['export', 'sticker', 'layout']]);

        $this->assertSame(['layout', 'sticker', 'export'], $enabled);
    }

    /** 레지스트리에 없는 이름은 스크립트 태그를 만들 수 없으므로 버린다. */
    public function testUnknownPluginNamesAreDropped(): void
    {
        $this->assertSame([], \_Mublo_editor_enabled_plugins(['plugins' => ['../../etc/passwd', 'nope']]));
        $this->assertSame([], \_Mublo_editor_enabled_plugins(['plugins' => 'layout']));
        $this->assertSame([], \_Mublo_editor_enabled_plugins([]));
    }

    /** 등록된 이름은 모두 실제 파일을 가리켜야 한다 — 404 스크립트 태그가 나가면 안 된다. */
    public function testPluginRegistryPointsAtShippedFiles(): void
    {
        $base = dirname(__DIR__, 3) . '/public/assets/lib/editor/mublo-editor';

        foreach (\_Mublo_editor_plugin_registry() as $plugin => $scripts) {
            $this->assertNotEmpty($scripts, "{$plugin} 에 스크립트가 없다");
            foreach ($scripts as $script) {
                $this->assertFileExists($base . $script);
            }
        }
    }
}
