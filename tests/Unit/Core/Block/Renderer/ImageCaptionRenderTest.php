<?php

namespace Tests\Unit\Core\Block\Renderer;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Entity\Block\BlockRow;
use Mublo\Infrastructure\Cache\CacheInterface;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Service\Block\BlockRenderService;
use PHPUnit\Framework\TestCase;

/**
 * 이미지 아이템의 선택 입력 제목·설명(title·desc) 렌더 계약.
 *
 * 핵심 약속은 "안 넣으면 없던 일" 이다 — 두 값이 비면 캡션 마크업 자체가
 * 생기지 않아야 기존에 만들어 둔 이미지 행의 화면이 그대로 남는다.
 * (바이트 단위 불변은 SingleColumnRenderSnapshotTest 가 따로 지킨다)
 */
class ImageCaptionRenderTest extends TestCase
{
    public function testBasicSkinOmitsCaptionWhenTitleAndDescAreEmpty(): void
    {
        $html = $this->renderImages('basic', [
            ['pc_image' => '/uploads/a.jpg'],
        ]);

        $this->assertStringContainsString('/uploads/a.jpg', $html);
        $this->assertStringNotContainsString('block-image__caption', $html);
    }

    public function testBasicSkinRendersTitleAndDesc(): void
    {
        $html = $this->renderImages('basic', [
            ['pc_image' => '/uploads/a.jpg', 'title' => '갤럭시 기획전', 'desc' => '이번 달 최대 지원금'],
        ]);

        $this->assertStringContainsString('<strong class="block-image__title">갤럭시 기획전</strong>', $html);
        $this->assertStringContainsString('<span class="block-image__desc">이번 달 최대 지원금</span>', $html);
    }

    public function testTitleOnlyDoesNotLeaveAnEmptyDescription(): void
    {
        $html = $this->renderImages('basic', [
            ['pc_image' => '/uploads/a.jpg', 'title' => '제휴카드'],
        ]);

        $this->assertStringContainsString('block-image__title', $html);
        $this->assertStringNotContainsString('block-image__desc', $html);
    }

    public function testWhitespaceOnlyValuesCountAsEmpty(): void
    {
        // 공백만 친 입력이 빈 줄로 남으면 운영자는 "왜 여백이 생기지" 로 헤맨다.
        $html = $this->renderImages('basic', [
            ['pc_image' => '/uploads/a.jpg', 'title' => "  \n ", 'desc' => ''],
        ]);

        $this->assertStringNotContainsString('block-image__caption', $html);
    }

    public function testCaptionIsEscaped(): void
    {
        $html = $this->renderImages('basic', [
            ['pc_image' => '/uploads/a.jpg', 'title' => '<script>alert(1)</script>'],
        ]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testTitleFallsBackToAltWhenAltIsEmpty(): void
    {
        $html = $this->renderImages('basic', [
            ['pc_image' => '/uploads/a.jpg', 'title' => '케통령 가이드'],
        ]);

        $this->assertStringContainsString('alt="케통령 가이드"', $html);
    }

    public function testExplicitAltWinsOverTitle(): void
    {
        $html = $this->renderImages('basic', [
            ['pc_image' => '/uploads/a.jpg', 'title' => '케통령 가이드', 'alt' => 'KT 요금제 안내 배너'],
        ]);

        $this->assertStringContainsString('alt="KT 요금제 안내 배너"', $html);
    }

    public function testIconLabelSkinRendersTitleAndDesc(): void
    {
        $html = $this->renderImages('icon_label', [
            ['pc_image' => '/uploads/a.jpg', 'title' => '5분 기기변경', 'desc' => '방문 없이 신청'],
        ]);

        $this->assertStringContainsString('icon-label__title', $html);
        $this->assertStringContainsString('5분 기기변경', $html);
        $this->assertStringContainsString('방문 없이 신청', $html);
    }

    public function testIconLabelSkinOmitsTextBlockWhenNothingEntered(): void
    {
        $html = $this->renderImages('icon_label', [
            ['pc_image' => '/uploads/a.jpg'],
        ]);

        $this->assertStringContainsString('icon-label__thumb', $html);
        $this->assertStringNotContainsString('icon-label__text', $html);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function renderImages(string $skin, array $items): string
    {
        $column = BlockColumn::fromArray([
            'column_id' => 77,
            'row_id' => 1,
            'domain_id' => 1,
            'column_index' => 0,
            'is_active' => 1,
            'content_type' => 'image',
            'content_kind' => 'CORE',
            'content_skin' => $skin,
            'content_items' => json_encode($items, JSON_UNESCAPED_UNICODE),
        ]);

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
}
