<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Report;

use Mublo\Core\Report\Contract\MenuScopedReportInterface;
use Mublo\Core\Report\Document\ColumnDefinition;
use Mublo\Core\Report\Document\ReportDocument;
use Mublo\Core\Report\Document\Section\TableSection;
use Mublo\Packages\Shop\Service\ProductService;

/**
 * 관리자 상품 목록 엑셀/CSV 내보내기 정의 (코어 Report 프레임워크)
 *
 * 목록 화면과 같은 검색·카테고리·상태·정렬 조건으로 내보낸다.
 */
class ShopProductReportDefinition implements MenuScopedReportInterface
{
    private const COLUMNS = [
        'item_code'      => ['상품코드', 'string', 'left'],
        'goods_code'     => ['자체코드', 'string', 'left'],
        'goods_name'     => ['상품명', 'string', 'left'],
        'category_code'  => ['카테고리', 'string', 'left'],
        'origin_price'   => ['정가', 'number', 'right'],
        'display_price'  => ['판매가', 'number', 'right'],
        'stock'          => ['재고', 'string', 'right'],
        'option_mode'    => ['옵션', 'string', 'center'],
        'status'         => ['상태', 'string', 'center'],
        'hit'            => ['조회', 'number', 'right'],
        'sold_count'     => ['판매', 'number', 'right'],
        'wishlist_count' => ['찜', 'number', 'right'],
        'review_count'   => ['후기', 'number', 'right'],
        'rating_avg'     => ['평점', 'number', 'right'],
        'created_at'     => ['등록일', 'string', 'left'],
    ];

    public function __construct(
        private ProductService $productService
    ) {
    }

    public function name(): string
    {
        return 'shop_products';
    }

    public function menuCode(): string
    {
        return 'K_Shop_004';
    }

    public function build(array $filters): ReportDocument
    {
        $columns = [];
        foreach (self::COLUMNS as $key => [$label, $type, $align]) {
            $columns[] = new ColumnDefinition($key, $label, $type, $align);
        }

        $provider = new ProductReportRowProvider(
            $this->productService,
            (int) ($filters['domain_id'] ?? 0),
            [
                'keyword'       => (string) ($filters['keyword'] ?? ''),
                'search_field'  => (string) ($filters['search_field'] ?? ''),
                'category_code' => (string) ($filters['category_code'] ?? ''),
                'is_active'     => (string) ($filters['is_active'] ?? ''),
                'option_mode'   => (string) ($filters['option_mode'] ?? ''),
                'sort'          => (string) ($filters['sort'] ?? ''),
            ]
        );

        return ReportDocument::create('상품 목록')
            ->addSection(new TableSection($columns, $provider));
    }
}
