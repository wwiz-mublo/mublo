<?php
declare(strict_types=1);

namespace Mublo\Contract\Manual;

/**
 * 매뉴얼 조회 계약 인터페이스
 *
 * Manual Plugin이 구현하고, Core/Package가 소비합니다.
 * ContractRegistry에 1:1 바인딩(bind/resolve)으로 등록됩니다.
 *
 * 반환은 배열이 아니라 읽기 전용 DTO(ManualBook/ManualPageNode/ManualPageDetail)다.
 * 형태가 안정된 도메인 read-model이므로, 배열 키 결합 대신 타입으로 계약을 고정한다.
 */
interface ManualQueryInterface
{
    /**
     * 활성 매뉴얼 책 목록 (sort_order 정렬)
     *
     * @return list<ManualBook>
     */
    public function getActiveBooks(int $domainId): array;

    /**
     * slug로 활성 매뉴얼 책 단건 조회
     */
    public function getBookBySlug(int $domainId, string $slug): ?ManualBook;

    /**
     * 책의 활성 페이지 트리 (parent_id 자기참조, 깊이 무제한)
     *
     * @return list<ManualPageNode>
     */
    public function getPageTree(int $bookId): array;

    /**
     * 책 안에서 slug로 활성 페이지 단건 조회
     */
    public function getPageBySlug(int $bookId, string $slug): ?ManualPageDetail;
}
