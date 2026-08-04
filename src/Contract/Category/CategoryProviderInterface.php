<?php
declare(strict_types=1);

namespace Mublo\Contract\Category;

/**
 * 카테고리 트리 제공자 계약
 *
 * Package(Shop, Rental 등)가 이 인터페이스를 구현하여
 * CategoryProviderRegistry에 등록하면, 스킨에서 $this->category('shop') 형태로
 * 카테고리 트리를 조회할 수 있다.
 *
 * 반환 규격 (모든 Provider 공통):
 * ```php
 * [
 *     [
 *         'icon'     => '',                    // 아이콘 (CSS 클래스 또는 이미지 경로)
 *         'code'     => 'cat_01',              // 고유 식별자
 *         'label'    => '의류',                 // 표시 이름
 *         'link'     => '/shop/category/1',    // URL
 *         'children' => [ ... ],               // 하위 카테고리 (동일 구조, 재귀)
 *     ],
 * ]
 * ```
 */
interface CategoryProviderInterface
{
    /**
     * 카테고리 트리 반환
     *
     * @param int $domainId 도메인 ID
     * @param int|null $depth 최대 depth (null = 전체, 1 = 루트만, 2 = 2단계까지)
     * @return list<array{icon: string, code: string, label: string, link: string, children: array}>
     */
    public function getTree(int $domainId, ?int $depth = null): array;
}
