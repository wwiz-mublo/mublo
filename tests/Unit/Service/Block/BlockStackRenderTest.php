<?php

namespace Tests\Unit\Service\Block;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Entity\Block\BlockColumnContent;
use Mublo\Entity\Block\BlockRow;
use Mublo\Infrastructure\Cache\CacheInterface;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Service\Block\BlockRenderService;
use PHPUnit\Framework\TestCase;

/** 렌더 실패 격리 검증용 — 항상 던지는 렌더러 */
class ThrowingStackRenderer implements RendererInterface
{
    public function render(BlockColumn $column): string
    {
        throw new \RuntimeException('boom');
    }
}

/**
 * 스택 칸 렌더링 (계획 13.3).
 *
 * single 칸 불변은 SingleColumnRenderSnapshotTest 가 fixture 로 보증하고,
 * 여기서는 stack 칸의 래퍼 구조·render key·격리·gap/패딩·no-cache 를 검증한다.
 */
class BlockStackRenderTest extends TestCase
{
    protected function setUp(): void
    {
        // 렌더 출력에 영향을 주는 전역 상태를 공개 화면 기본값으로 고정
        unset($_GET['_editor'], $_SESSION['auth_user']);
        $_ENV['APP_DEBUG'] = 'false';
        BlockRegistry::reset();
        BlockRegistry::hasContentType('html');
    }

    protected function tearDown(): void
    {
        BlockRegistry::reset();
    }

    private function service(): BlockRenderService
    {
        return new BlockRenderService(
            $this->createMock(BlockRowRepository::class),
            $this->createMock(BlockColumnRepository::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(DependencyContainer::class)
        );
    }

    private function row(): BlockRow
    {
        return BlockRow::fromArray([
            'row_id' => 1,
            'domain_id' => 1,
            'width_type' => 1,
            'column_count' => 1,
            'column_margin' => 0,
            'is_active' => 1,
        ]);
    }

    private function stackColumn(array $contents, array $columnOverrides = []): BlockColumn
    {
        $column = BlockColumn::fromArray($columnOverrides + [
            'column_id' => 12,
            'row_id' => 1,
            'domain_id' => 1,
            'column_index' => 0,
            'is_active' => 1,
            'content_mode' => 'stack',
        ]);

        $entities = [];
        foreach ($contents as $content) {
            $entities[] = BlockColumnContent::fromArray($content + [
                'column_id' => 12,
                'domain_id' => 1,
                'is_active' => 1,
            ]);
        }
        $column->setContents($entities);

        return $column;
    }

    private function htmlContent(int $contentId, string $html, array $overrides = []): array
    {
        return $overrides + [
            'content_id' => $contentId,
            'content_type' => 'html',
            'content_kind' => 'CORE',
            'content_skin' => 'basic',
            'content_config' => ['html' => $html],
        ];
    }

    public function testStackRendersContentsInOrderWithUniqueRenderKeys(): void
    {
        $column = $this->stackColumn([
            $this->htmlContent(31, '<p>first</p>', ['content_config' => ['html' => '<p>first</p>', 'css' => '.a{color:red}']]),
            $this->htmlContent(32, '<p>second</p>', ['content_config' => ['html' => '<p>second</p>', 'css' => '.a{color:blue}']]),
        ]);

        $html = $this->service()->renderRowFromEntities($this->row(), [$column]);

        // 래퍼 구조 (계획 7.3)
        $this->assertStringContainsString('block-column--stack', $html);
        $this->assertStringContainsString('class="block-content-stack" data-column-id="12"', $html);

        // 같은 타입 반복 배치 — 항목 DOM ID 고유 (계획 7.2)
        $this->assertStringContainsString('id="bc-12-c-31"', $html);
        $this->assertStringContainsString('id="bc-12-c-32"', $html);

        // 순서 보장
        $this->assertLessThan(strpos($html, '<p>second</p>'), strpos($html, '<p>first</p>'));

        // CSS 스코핑이 콘텐츠 항목 단위로 격리 (계획 7.2.1)
        $this->assertStringContainsString('#bc-12-c-31 .a {color:red}', $html);
        $this->assertStringContainsString('#bc-12-c-32 .a {color:blue}', $html);
        $this->assertStringNotContainsString('#bc-12 .a ', $html);
    }

    public function testInactiveContentIsExcludedFromPublicRender(): void
    {
        $column = $this->stackColumn([
            $this->htmlContent(31, '<p>visible</p>'),
            $this->htmlContent(32, '<p>hidden</p>', ['is_active' => 0]),
        ]);

        $html = $this->service()->renderRowFromEntities($this->row(), [$column]);

        $this->assertStringContainsString('<p>visible</p>', $html);
        $this->assertStringNotContainsString('<p>hidden</p>', $html);
    }

    public function testOneFailingContentDoesNotBreakSiblings(): void
    {
        BlockRegistry::registerContentType(
            type: 'boom',
            kind: 'CORE',
            title: '터지는 타입',
            rendererClass: ThrowingStackRenderer::class,
            configFormClass: null
        );

        $column = $this->stackColumn([
            ['content_id' => 31, 'content_type' => 'boom', 'content_kind' => 'CORE'],
            $this->htmlContent(32, '<p>survivor</p>'),
        ]);

        $html = $this->service()->renderRowFromEntities($this->row(), [$column]);

        // 실패 콘텐츠는 항목 공간만 유지하고 형제는 계속 렌더 (계획 5.5·7.3)
        $this->assertStringContainsString('<p>survivor</p>', $html);
        $this->assertStringContainsString('id="bc-12-c-31"', $html);
    }

    public function testUninstalledTypeKeepsItemSlot(): void
    {
        $column = $this->stackColumn([
            ['content_id' => 31, 'content_type' => 'ghost_ext', 'content_kind' => 'PLUGIN'],
            $this->htmlContent(32, '<p>alive</p>'),
        ]);

        $html = $this->service()->renderRowFromEntities($this->row(), [$column]);

        $this->assertStringContainsString('id="bc-12-c-31"', $html);
        $this->assertStringContainsString('<p>alive</p>', $html);
    }

    public function testStackPaddingAppliedOnceOnStackWrapperNotChildBodies(): void
    {
        $column = $this->stackColumn(
            [$this->htmlContent(31, '<p>a</p>')],
            ['pc_padding' => '20px', 'mobile_padding' => '10px']
        );

        $html = $this->service()->renderRowFromEntities($this->row(), [$column]);

        // 패딩은 스택 외곽 1회 (계획 4.3)
        $this->assertStringContainsString('#bc-12 > .block-content-stack{padding:20px}', $html);
        $this->assertStringContainsString('@media(max-width:767px){#bc-12 > .block-content-stack{padding:10px}}', $html);
        $this->assertStringNotContainsString('#bc-12 .block-body{padding', $html);
    }

    public function testStackGapAppliedResponsively(): void
    {
        $column = $this->stackColumn(
            [$this->htmlContent(31, '<p>a</p>')],
            ['pc_content_gap' => 16, 'mobile_content_gap' => 8]
        );

        $html = $this->service()->renderRowFromEntities($this->row(), [$column]);

        $this->assertStringContainsString('#bc-12 > .block-content-stack{display:flex;flex-direction:column;gap:16px}', $html);
        $this->assertStringContainsString('@media(max-width:767px){#bc-12 > .block-content-stack{gap:8px}}', $html);
    }

    public function testAosAppliedPerContentItemNotOnColumnWrapper(): void
    {
        $column = $this->stackColumn(
            [
                $this->htmlContent(31, '<p>a</p>', [
                    'content_config' => ['html' => '<p>a</p>', 'aos' => 'fade-up'],
                ]),
                $this->htmlContent(32, '<p>b</p>'),
            ],
            // 레거시 미러가 첫 활성 콘텐츠 config 를 갖고 있는 상황 재현
            ['content_config' => json_encode(['html' => '<p>a</p>', 'aos' => 'fade-up'])]
        );

        $html = $this->service()->renderRowFromEntities($this->row(), [$column]);

        // 항목에는 AOS, 칸 wrapper(id="bc-12")에는 없음 (계획 7.3)
        $this->assertStringContainsString('id="bc-12-c-31" data-content-id="31" data-aos="fade-up"', $html);
        $this->assertStringNotContainsString('id="bc-12" style=""  data-aos', $html);
        $this->assertDoesNotMatchRegularExpression('/id="bc-12"[^>]*data-aos/', $html);
    }

    public function testSingleColumnRenderKeyIsPlainColumnId(): void
    {
        $column = BlockColumn::fromArray([
            'column_id' => 12,
            'row_id' => 1,
            'domain_id' => 1,
        ]);

        // 렌더 키 규칙 (계획 7.2): single "12" / 스택 콘텐츠 view "12-c-31"
        $this->assertSame('12', $column->getRenderKey());

        $view = $column->withContentView(BlockColumnContent::fromArray([
            'content_id' => 31,
            'column_id' => 12,
            'domain_id' => 1,
            'content_type' => 'html',
        ]));
        $this->assertSame('12-c-31', $view->getRenderKey());
        $this->assertSame('html', $view->getContentTypeString());
        $this->assertSame('12', $column->getRenderKey()); // 원본 불변
    }
}
