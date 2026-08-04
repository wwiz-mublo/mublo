<?php

namespace Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;

class AdminExtensionsViewTest extends TestCase
{
    public function testNestedPluginsRenderOneCardSummaryAndAllSettingsInModal(): void
    {
        $pageTitle = '확장 기능';
        $isSuper = true;
        $packages = [
            'Board' => [
                'label' => 'Board',
                'description' => '게시판',
                'version' => '1.0.0',
                'enabled' => true,
            ],
        ];
        $plugins = [
            'Board/BoardReport' => $this->plugin('게시글 신고', true),
            'Board/BoardBookmark' => $this->plugin('게시글 북마크', true),
            'Board/BoardPoll' => $this->plugin('게시글 투표', false),
            'Board/BoardShare' => $this->plugin('게시글 공유', false),
            'Board/HiddenTool' => $this->plugin('숨김 도구', false, true),
        ];

        ob_start();
        include MUBLO_ROOT_PATH . '/views/Admin/Extensions/Index.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('2/4 사용', $html);
        $this->assertStringContainsString('외 3개', $html);
        $this->assertStringContainsString('전체보기', $html);
        $this->assertStringContainsString('row g-1 align-items-stretch nested-summary-actions', $html);
        $this->assertStringContainsString('height: 28px;', $html);
        $this->assertStringContainsString('modal-dialog modal-lg modal-dialog-scrollable', $html);
        $this->assertSame(1, substr_count($html, 'nested-summary-check"'));
        $this->assertSame(4, substr_count($html, 'class="form-check-input mt-1 nested-check"'));
        $this->assertSame(4, substr_count($html, 'name="formData[plugins][]"'));
        $this->assertStringNotContainsString('숨김 도구', $html);
    }

    /** @return array<string, mixed> */
    private function plugin(string $label, bool $enabled, bool $hidden = false): array
    {
        return [
            'parent' => 'Board',
            'label' => $label,
            'description' => $label . ' 설명',
            'version' => '1.0.0',
            'icon' => 'bi-puzzle',
            'enabled' => $enabled,
            'hidden' => $hidden,
        ];
    }
}
