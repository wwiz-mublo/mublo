<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Helper;

/**
 * 상품 화면의 검색엔진 메타데이터 생성기.
 *
 * 코어 프레임(views/Front/frame/*\/Head.php)이 읽는 변수 계약에 맞춘 배열을 만든다.
 * 컨트롤러는 결과를 그대로 withData() 에 펼쳐 넣기만 하면 된다.
 *
 *   $seoTitle       <title> · og:title
 *   $seoDescription meta description · og:description
 *   $pageOgImage    og:image (없으면 사이트 기본 이미지로 폴백)
 *   $pageOgType     og:type
 *   $pageJsonLd     <script type="application/ld+json">
 *
 * 값이 없으면 키를 아예 넣지 않는다 — 코어의 폴백 체인(사이트 기본값)이 살아야 하므로
 * 빈 문자열을 넘기면 안 된다.
 */
class ProductSeoPresenter
{
    /** meta description 권장 길이. 넘으면 잘라내고 말줄임표를 붙인다. */
    private const DESCRIPTION_LIMIT = 160;

    private string $siteUrl;

    /** @param string $siteUrl 스킴+호스트 (예: https://shop.example.com) */
    public function __construct(string $siteUrl)
    {
        $this->siteUrl = rtrim($siteUrl, '/');
    }

    /**
     * 상품 상세 메타데이터.
     *
     * @param array<string,mixed> $product      ProductPresenter::toView() 결과
     * @param array<int,array{label:string,link:string}> $categoryPath 카테고리 경로(루트→현재)
     * @return array<string,mixed> 코어 프레임 변수 묶음
     */
    public function detail(array $product, array $categoryPath = []): array
    {
        $name = trim((string) ($product['goods_name'] ?? ''));
        if ($name === '') {
            return [];
        }

        $meta = [
            'seoTitle'   => $name,
            'pageOgType' => 'product',
        ];

        $description = $this->buildDescription($product, $categoryPath);
        if ($description !== '') {
            $meta['seoDescription'] = $description;
        }

        $image = $this->absoluteUrl((string) ($product['main_image_url'] ?? ''));
        if ($image !== '') {
            $meta['pageOgImage'] = $image;
        }

        $jsonLd = $this->productJsonLd($product, $categoryPath, $image);
        $breadcrumb = $this->breadcrumbJsonLd($categoryPath, $name);

        // 그래프로 묶어 하나의 스크립트로 낸다 — 코어가 $pageJsonLd 를 통째로 인코딩한다
        $nodes = array_values(array_filter([$jsonLd, $breadcrumb]));
        if ($nodes !== []) {
            $meta['pageJsonLd'] = count($nodes) === 1
                ? $nodes[0]
                : ['@context' => 'https://schema.org', '@graph' => array_map(
                    static function (array $n): array {
                        unset($n['@context']);
                        return $n;
                    },
                    $nodes
                )];
        }

        return $meta;
    }

    /**
     * 상품 목록 메타데이터.
     *
     * 카테고리·검색어에 따라 제목이 달라져야 목록 페이지들이 서로 구분된다.
     *
     * @param string $categoryName 현재 카테고리명 (없으면 빈 문자열)
     * @param string $keyword      검색어 (없으면 빈 문자열)
     * @param int    $totalItems   전체 상품 수
     * @return array<string,mixed>
     */
    public function list(string $categoryName = '', string $keyword = '', int $totalItems = 0): array
    {
        $categoryName = trim($categoryName);
        $keyword = trim($keyword);

        if ($keyword !== '') {
            return [
                'seoTitle'       => sprintf("'%s' 검색 결과", $keyword),
                'seoDescription' => sprintf(
                    "'%s' 검색 결과 %s개의 상품입니다.",
                    $keyword,
                    number_format($totalItems)
                ),
            ];
        }

        if ($categoryName !== '') {
            return [
                'seoTitle'       => $categoryName,
                'seoDescription' => sprintf(
                    '%s 카테고리의 상품 %s개를 모았습니다.',
                    $categoryName,
                    number_format($totalItems)
                ),
            ];
        }

        return [
            'seoTitle'       => '전체 상품',
            'seoDescription' => sprintf('전체 상품 %s개를 모았습니다.', number_format($totalItems)),
        ];
    }

    /**
     * meta description 생성.
     *
     * 상세 설명 HTML 이 있으면 태그를 걷어내 쓰고, 없으면 카테고리·제조사·상품명으로 만든다.
     * 상세 HTML 은 운영자가 조판한 마크업이라 공백·엔티티 정리가 필요하다.
     *
     * @param array<string,mixed> $product
     * @param array<int,array{label:string,link:string}> $categoryPath
     */
    private function buildDescription(array $product, array $categoryPath): string
    {
        $fromDetail = $this->flattenDetailHtml($product['details'] ?? []);
        if ($fromDetail !== '') {
            return $fromDetail;
        }

        // 폴백: "여성의류 > 아우터 | 제조사 | 상품명" 꼴로 최소한의 구분값을 만든다
        $parts = [];
        $trail = array_values(array_filter(array_map(
            static fn(array $c): string => trim((string) ($c['label'] ?? '')),
            $categoryPath
        )));
        if ($trail !== []) {
            $parts[] = implode(' > ', $trail);
        }

        $manufacturer = trim((string) ($product['goods_manufacturer'] ?? ''));
        if ($manufacturer !== '') {
            $parts[] = $manufacturer;
        }

        $parts[] = trim((string) ($product['goods_name'] ?? ''));

        return $this->truncate(implode(' | ', array_filter($parts)));
    }

    /**
     * 상세 설명 HTML 묶음을 평문 한 줄로 만든다.
     *
     * @param mixed $details ProductPresenter 가 만든 detail 배열
     */
    private function flattenDetailHtml(mixed $details): string
    {
        if (!is_array($details)) {
            return '';
        }

        $text = '';
        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            $value = (string) ($detail['detail_value'] ?? '');
            if ($value === '') {
                continue;
            }
            // 블록 경계가 붙어버리지 않게 태그 자리에 공백을 남긴다
            $plain = strip_tags(str_replace(['<', '>'], [' <', '> '], $value));
            $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text .= ' ' . $plain;

            if (mb_strlen($this->normalize($text)) >= self::DESCRIPTION_LIMIT) {
                break; // 이미 충분하다 — 남은 상세를 더 훑지 않는다
            }
        }

        return $this->truncate($this->normalize($text));
    }

    /** 연속 공백·개행을 한 칸으로 접는다. */
    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function truncate(string $text): string
    {
        $text = $this->normalize($text);
        if ($text === '' || mb_strlen($text) <= self::DESCRIPTION_LIMIT) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, self::DESCRIPTION_LIMIT - 1)) . '…';
    }

    /**
     * schema.org Product.
     *
     * 가격·재고·평점은 ProductPresenter 가 이미 계산해 둔 값을 쓴다.
     *
     * @param array<string,mixed> $product
     * @param array<int,array{label:string,link:string}> $categoryPath
     * @return array<string,mixed>|null
     */
    private function productJsonLd(array $product, array $categoryPath, string $image): ?array
    {
        $name = trim((string) ($product['goods_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $node = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => $name,
        ];

        if ($image !== '') {
            $node['image'] = $image;
        }

        $sku = trim((string) ($product['goods_code'] ?? $product['item_code'] ?? ''));
        if ($sku !== '') {
            $node['sku'] = $sku;
        }

        $brand = trim((string) ($product['goods_manufacturer'] ?? ''));
        if ($brand !== '') {
            $node['brand'] = ['@type' => 'Brand', 'name' => $brand];
        }

        $trail = array_values(array_filter(array_map(
            static fn(array $c): string => trim((string) ($c['label'] ?? '')),
            $categoryPath
        )));
        if ($trail !== []) {
            $node['category'] = implode(' > ', $trail);
        }

        $url = $this->absoluteUrl((string) ($product['url'] ?? ''));
        $price = $product['sales_price'] ?? null;
        if (is_numeric($price)) {
            $offer = [
                '@type'         => 'Offer',
                'price'         => (string) (int) $price,
                'priceCurrency' => 'KRW',
                // 품절 판정은 옵션 모드별 재고 합산 결과(ProductPresenter) 를 그대로 따른다
                'availability'  => !empty($product['is_soldout'])
                    ? 'https://schema.org/OutOfStock'
                    : 'https://schema.org/InStock',
            ];
            if ($url !== '') {
                $offer['url'] = $url;
            }
            $node['offers'] = $offer;
        }

        // 후기가 하나도 없으면 aggregateRating 을 넣지 않는다 — 구글이 오류로 처리한다
        $reviewCount = (int) ($product['review_count'] ?? 0);
        $rating = (float) ($product['average_rating'] ?? 0);
        if ($reviewCount > 0 && $rating > 0) {
            $node['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => round($rating, 1),
                'reviewCount' => $reviewCount,
            ];
        }

        return $node;
    }

    /**
     * schema.org BreadcrumbList — 카테고리 경로 + 현재 상품.
     *
     * @param array<int,array{label:string,link:string}> $categoryPath
     * @return array<string,mixed>|null
     */
    private function breadcrumbJsonLd(array $categoryPath, string $productName): ?array
    {
        if ($categoryPath === []) {
            return null;
        }

        $items = [];
        $position = 1;
        foreach ($categoryPath as $crumb) {
            $label = trim((string) ($crumb['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $item = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => $label,
            ];
            $link = $this->absoluteUrl((string) ($crumb['link'] ?? ''));
            if ($link !== '') {
                $item['item'] = $link;
            }
            $items[] = $item;
        }

        if ($items === []) {
            return null;
        }

        $items[] = [
            '@type'    => 'ListItem',
            'position' => $position,
            'name'     => $productName,
        ];

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /** 상대 경로를 절대 URL 로. 이미 절대면 그대로 둔다. */
    private function absoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        return $this->siteUrl . '/' . ltrim($url, '/');
    }
}
