<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Block;

use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Core\Block\Renderer\SkinRendererTrait;
use Mublo\Contract\Block\BlockColumnView;
use Mublo\Packages\Shop\Repository\ReviewRepository;

/**
 * ReviewAutoRenderer
 *
 * 쇼핑몰 구매후기 자동 진열 블록 콘텐츠 렌더러
 *
 * 관리자가 선택한 "정렬 기준 + 표시 개수 + 필터(포토/베스트)"로
 * 노출 가능한 구매후기를 자동 조회해 출력한다.
 * (content_items를 쓰지 않고 content_config만 사용)
 *
 * content_config 키:
 * - sort_order:    정렬 기준 (newest | rating_high | best)
 * - display_count: 표시 개수 (구버전 호환: 없으면 pc_count/mo_count 중 큰 값)
 * - photo_only:    포토후기만 (bool)
 * - best_only:     베스트 후기만 (bool)
 */
class ReviewAutoRenderer implements RendererInterface
{
    use SkinRendererTrait;

    private ReviewRepository $reviewRepository;

    public function __construct(ReviewRepository $reviewRepository)
    {
        $this->reviewRepository = $reviewRepository;
    }

    protected function getSkinType(): string
    {
        return 'review_auto';
    }

    protected function getSkinBasePath(): string
    {
        return MUBLO_PACKAGE_PATH . '/Shop/views/Block/';
    }

    public function render(BlockColumnView $column): string
    {
        $config = $column->getContentConfig() ?? [];
        $domainId = $column->getDomainId();

        // 표시 개수: display_count 우선, (구버전 호환) 없으면 PC/MO 출력갯수, 그래도 없으면 4
        $count = (int) ($config['display_count'] ?? 0);
        if ($count < 1) {
            $count = max((int) ($config['pc_count'] ?? 0), (int) ($config['mo_count'] ?? 0));
        }
        if ($count < 1) {
            $count = 4;
        }

        $filters = [
            'is_visible' => 1,
            'sort' => $config['sort_order'] ?? 'newest',
        ];
        if (!empty($config['photo_only'])) {
            $filters['has_photo'] = 1;
        }
        if (!empty($config['best_only'])) {
            $filters['is_best'] = 1;
        }

        $result = $this->reviewRepository->getList($domainId, $filters, 1, $count);
        $rows = $result['items'] ?? [];

        if (empty($rows)) {
            return $this->renderEmpty($column, '표시할 후기가 없습니다.');
        }

        $items = array_map([$this, 'formatItem'], $rows);

        $skin = $column->getContentSkin() ?: 'basic';

        return $this->renderSkin($column, $skin, [
            'items' => $items,
            'config' => $config,
        ]);
    }

    /**
     * 빈 상태 — 표시할 후기가 없어도 칸을 유지하고 점선 박스 안내(패키지 소유).
     */
    private function renderEmpty(BlockColumnView $column, string $message): string
    {
        $skin = $column->getContentSkin() ?: 'basic';
        if ($this->assetManager) {
            $this->assetManager->addCss('/serve/package/Shop/views/Block/review_auto/' . $skin . '/style.css');
        }
        $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        return '<div class="block-review-empty">'
             . '<i class="bi bi-inbox block-review-empty__icon"></i>'
             . '<span>' . $msg . '</span></div>';
    }

    /**
     * 후기 row → 스킨용 표시 데이터로 변환
     */
    private function formatItem(array $row): array
    {
        $images = array_values(array_filter([
            $row['image1'] ?? null,
            $row['image2'] ?? null,
            $row['image3'] ?? null,
        ]));

        $goodsId = (int) ($row['goods_id'] ?? 0);
        $slug = (string) ($row['goods_slug'] ?? '');
        $url = $slug !== '' ? "/shop/products/{$goodsId}/{$slug}" : "/shop/products/{$goodsId}";

        return [
            'rating'      => (int) ($row['rating'] ?? 5),
            'review_type' => $row['review_type'] ?? 'TEXT',
            'content'     => $row['content'] ?? '',
            'author'      => $this->authorName($row),
            'date'        => substr((string) ($row['created_at'] ?? ''), 0, 10),
            'images'      => $images,
            'is_best'     => (int) ($row['is_best'] ?? 0) === 1,
            'goods_id'    => $goodsId,
            'goods_name'  => $row['goods_name'] ?? '',
            'url'         => $url,
            'thumbnail'   => $row['product_thumbnail'] ?? null,
        ];
    }

    /**
     * 작성자 표시명 (닉네임 원본 > 아이디, 마스킹 안 함). 비회원(닉네임 없음)은 빈값.
     */
    private function authorName(array $row): string
    {
        $name = trim((string) ($row['public_display_name'] ?? ''));
        return $name;
    }
}
