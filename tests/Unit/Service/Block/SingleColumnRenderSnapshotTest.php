<?php

namespace Tests\Unit\Service\Block;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Entity\Block\BlockRow;
use Mublo\Infrastructure\Cache\CacheInterface;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Service\Block\BlockRenderService;
use PHPUnit\Framework\TestCase;

/**
 * 단일(single) 칸 렌더 HTML snapshot — 콘텐츠 스택 도입의 기준선 (계획 단계 0).
 *
 * 스택 기능이 들어가도 single 칸의 공개 HTML 은 바이트 단위로 불변이어야
 * 한다(승인 기준 1·17). 이 테스트는 대표 single 칸들의 렌더 결과를
 * fixture 파일로 고정한다.
 *
 * fixture 갱신: 의도된 출력 변경일 때만 MUBLO_UPDATE_SNAPSHOT=1 로 실행해
 * 재생성하고, diff 를 리뷰에서 확인한다.
 */
class SingleColumnRenderSnapshotTest extends TestCase
{
    private mixed $savedEditorFlag = null;
    private mixed $savedAuthUser = null;
    private mixed $savedAppDebug = null;

    protected function setUp(): void
    {
        // 렌더 출력에 영향을 주는 전역 상태를 공개 화면 기본값으로 고정 —
        // 앞선 테스트가 남긴 에디터 미리보기·디버그 상태에 스냅샷이 오염되지 않게 한다
        $this->savedEditorFlag = $_GET['_editor'] ?? null;
        $this->savedAuthUser = $_SESSION['auth_user'] ?? null;
        $this->savedAppDebug = $_ENV['APP_DEBUG'] ?? null;
        unset($_GET['_editor'], $_SESSION['auth_user']);
        $_ENV['APP_DEBUG'] = 'false';
    }

    protected function tearDown(): void
    {
        if ($this->savedEditorFlag !== null) {
            $_GET['_editor'] = $this->savedEditorFlag;
        }
        if ($this->savedAuthUser !== null) {
            $_SESSION['auth_user'] = $this->savedAuthUser;
        }
        if ($this->savedAppDebug === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $this->savedAppDebug;
        }
    }

    public function testHtmlColumnWithCssJsAndTitleSnapshot(): void
    {
        // HTML 타입 — bc-{id} CSS 스코핑·JS block 래핑·제목까지 포함하는 대표 케이스
        $column = BlockColumn::fromArray([
            'column_id' => 12,
            'row_id' => 1,
            'domain_id' => 1,
            'column_index' => 0,
            'is_active' => 1,
            'content_type' => 'html',
            'content_kind' => 'CORE',
            'content_skin' => 'basic',
            'title_config' => json_encode([
                'show' => true,
                'text' => '공지사항',
                'position' => 'left',
            ], JSON_UNESCAPED_UNICODE),
            'content_config' => json_encode([
                'html' => '<p class="intro">환영합니다</p>',
                'css' => '.intro { color: #333; }',
                'js' => "console.log('hi');",
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->assertSnapshot('single-html-column', $this->render($column));
    }

    public function testImageColumnSnapshot(): void
    {
        $column = BlockColumn::fromArray([
            'column_id' => 21,
            'row_id' => 1,
            'domain_id' => 1,
            'column_index' => 0,
            'is_active' => 1,
            'content_type' => 'image',
            'content_kind' => 'CORE',
            'content_skin' => 'basic',
            'content_items' => json_encode([
                'images' => [
                    ['pc_image' => '/uploads/a.jpg', 'mo_image' => '/uploads/a-m.jpg', 'link_url' => '/promo', 'link_win' => 0],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->assertSnapshot('single-image-column', $this->render($column));
    }

    public function testEmptyColumnSnapshot(): void
    {
        // 빈 칸 backstop — 칸 슬롯 유지 정책의 기준선
        $column = BlockColumn::fromArray([
            'column_id' => 31,
            'row_id' => 1,
            'domain_id' => 1,
            'column_index' => 0,
            'is_active' => 1,
        ]);

        $this->assertSnapshot('single-empty-column', $this->render($column));
    }

    private function render(BlockColumn $column): string
    {
        $service = new BlockRenderService(
            $this->createMock(BlockRowRepository::class),
            $this->createMock(BlockColumnRepository::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(DependencyContainer::class)
        );

        $row = BlockRow::fromArray([
            'row_id' => 1,
            'domain_id' => 1,
            'width_type' => 1,
            'column_count' => 1,
            'column_margin' => 0,
            'is_active' => 1,
        ]);

        return $service->renderRowFromEntities($row, [$column]);
    }

    private function assertSnapshot(string $name, string $html): void
    {
        $dir = dirname(__DIR__, 3) . '/Fixtures/BlockSnapshot';
        $path = $dir . '/' . $name . '.html';

        if (!is_file($path) || getenv('MUBLO_UPDATE_SNAPSHOT') === '1') {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($path, $html);
            fwrite(STDERR, "\n[snapshot] fixture 생성/갱신: {$name}.html — 커밋 전 diff 확인 필요\n");
        }

        $this->assertSame(
            file_get_contents($path),
            $html,
            "single 칸 렌더 HTML 이 기준선({$name})과 다르다 — 의도된 변경이면 MUBLO_UPDATE_SNAPSHOT=1 로 재생성"
        );
    }
}
