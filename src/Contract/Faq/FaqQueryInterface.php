<?php

namespace Mublo\Contract\Faq;

/**
 * FAQ 조회 계약 인터페이스
 *
 * FAQ Plugin이 구현하고, Package(Shop/Mshop 등)가 소비합니다.
 * ContractRegistry에 1:1 바인딩(bind/resolve)으로 등록됩니다.
 *
 * 반환은 DTO가 아니라 배열이다. 이 계약의 출력은 (1) JSON API(GET /faq/api/list)로
 * 그대로 직렬화되고 (2) 블록·프론트 스킨이라는 커스터마이즈 표면으로 흐르므로,
 * snake_case 키 자체가 외부 계약이다. 대신 아래 array{} 셰이프 주석으로 형태를 고정한다.
 * (원칙: docs/dev-guide/contract-system.md > "계약 반환 타입" 참고)
 */
interface FaqQueryInterface
{
    /**
     * 활성 카테고리 목록 (sort_order 정렬)
     *
     * @return list<array{category_id: int, category_name: string, category_slug: string, item_count: int}>
     */
    public function getCategories(int $domainId): array;

    /**
     * 카테고리 slug 배열로 FAQ 항목 조회
     *
     * @param list<string> $slugs
     * @return array<string, list<array{faq_id: int, question: string, answer: string}>>
     */
    public function getByCategorySlugs(int $domainId, array $slugs): array;

    /**
     * 전체 FAQ (카테고리별 그룹핑)
     *
     * @return list<array{category_id: int, category_name: string, category_slug: string, items: list<array{faq_id: int, question: string, answer: string}>}>
     */
    public function getGroupedAll(int $domainId): array;

    /**
     * 페이지 처리된 FAQ 목록 (카테고리별 그룹핑)
     *
     * @return array{
     *   groups: list<array{category_name: string, category_slug: string, items: list<array{faq_id: int, question: string, answer: string}>}>,
     *   totalItems: int,
     *   perPage: int,
     *   currentPage: int,
     *   totalPages: int
     * }
     */
    public function getGroupedPaginated(int $domainId, int $page, int $perPage): array;
}
