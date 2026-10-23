<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Report;

use Mublo\Core\Report\Contract\RowProviderInterface;
use Mublo\Packages\Shop\Enum\OptionMode;
use Mublo\Packages\Shop\Service\ProductService;

/**
 * 상품 목록 내보내기의 행 공급자
 *
 * ProductService::getList(판매·후기·찜 집계 포함)를 페이지 단위로 조회한다.
 * getList 가 한 번에 100건까지만 주므로 그 크기로 끊어 읽는다.
 */
class ProductReportRowProvider implements RowProviderInterface
{
    private const BATCH = 100;

    private ?int $total = null;

    public function __construct(
        private ProductService $productService,
        private int $domainId,
        private array $filters
    ) {
    }

    public function getChunk(int $offset, int $limit): array
    {
        $limit = max(1, min(self::BATCH, $limit));
        $page = (int) floor(max(0, $offset) / $limit) + 1;

        $result = $this->productService->getList($this->domainId, $this->filters, $page, $limit);
        if ($result->isFailure()) {
            return [];
        }

        $this->total = (int) $result->get('totalItems', $this->total ?? 0);

        return array_map(fn(array $product) => $this->mapRow($product), $result->get('items', []));
    }

    public function rows(): iterable
    {
        $offset = 0;
        do {
            $chunk = $this->getChunk($offset, self::BATCH);
            foreach ($chunk as $row) {
                yield $row;
            }
            $offset += self::BATCH;
        } while (count($chunk) === self::BATCH);
    }

    public function totalCount(): ?int
    {
        if ($this->total === null) {
            $result = $this->productService->getList($this->domainId, $this->filters, 1, 1);
            $this->total = $result->isSuccess() ? (int) $result->get('totalItems', 0) : 0;
        }
        return $this->total;
    }

    public function isRewindable(): bool
    {
        return true;
    }

    private function mapRow(array $product): array
    {
        $optionMode = (string) ($product['option_mode'] ?? 'NONE');

        return [
            'item_code'      => (string) ($product['item_code'] ?? ''),
            'goods_code'     => (string) ($product['goods_code'] ?? ''),
            'goods_name'     => (string) ($product['goods_name'] ?? ''),
            'category_code'  => (string) ($product['category_code'] ?? ''),
            'origin_price'   => (int) ($product['origin_price'] ?? 0),
            'display_price'  => (int) ($product['display_price'] ?? 0),
            'stock'          => $this->stockLabel($product, $optionMode),
            'option_mode'    => $optionMode === 'NONE' ? '' : (OptionMode::tryFrom($optionMode)?->label() ?? $optionMode),
            'status'         => !empty($product['is_active']) ? '판매중' : '판매중지',
            'hit'            => (int) ($product['hit'] ?? 0),
            'sold_count'     => (int) ($product['sold_count'] ?? 0),
            'wishlist_count' => (int) ($product['wishlist_count'] ?? 0),
            'review_count'   => (int) ($product['review_count'] ?? 0),
            'rating_avg'     => round((float) ($product['rating_avg'] ?? 0), 1),
            'created_at'     => (string) ($product['created_at'] ?? ''),
        ];
    }

    /** 목록 화면과 같은 표기: 옵션 상품은 '옵션별', 미관리는 '무한' */
    private function stockLabel(array $product, string $optionMode): string
    {
        if ($optionMode !== 'NONE') {
            return '옵션별';
        }

        $stock = $product['stock_quantity'] ?? null;

        return $stock === null ? '무한' : (string) (int) $stock;
    }
}
