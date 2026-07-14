<?php
namespace Mublo\Repository;

/**
 * RepositoryInterface
 *
 * 모든 Repository가 구현해야 하는 표준 인터페이스
 *
 * 책임:
 * - 표준화된 CRUD 메서드 정의
 * - 일관된 데이터 접근 패턴
 */
interface RepositoryInterface
{
    /**
     * ID로 단일 엔티티 조회
     *
     * @param int|string $id
     * @return object|null
     */
    public function find(int|string $id): ?object;

    /**
     * 모든 엔티티 조회
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function all(int $limit = 100, int $offset = 0): array;

    /**
     * 조건으로 검색
     *
     * @param array $conditions 조건 배열 ['field' => 'value']
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function findBy(array $conditions, int $limit = 100, int $offset = 0): array;

    /**
     * 조건으로 단일 엔티티 조회
     *
     * @param array $conditions
     * @return object|null
     */
    public function findOneBy(array $conditions): ?object;

    /**
     * 조건으로 엔티티 존재 여부 확인
     *
     * @param array $conditions
     * @return bool
     */
    public function existsBy(array $conditions): bool;

    /**
     * 조건에 맞는 엔티티 개수 조회
     *
     * @param array $conditions
     * @return int
     */
    public function countBy(array $conditions = []): int;

    /**
     * 엔티티 생성
     *
     * @param array $data
     * @return int|null 생성된 ID
     */
    public function create(array $data): int|null;

    /**
     * 엔티티 수정
     *
     * @param int|string $id
     * @param array $data
     * @return int 영향받은 행 수
     */
    public function update(int|string $id, array $data): int;

    /**
     * 엔티티 삭제
     *
     * @param int|string $id
     * @return int 삭제된 행 수
     */
    public function delete(int|string $id): int;

    /**
     * 페이지네이션
     *
     * @param int $page 페이지 번호 (1부터 시작)
     * @param int $perPage 페이지당 항목 수
     * @return array ['data' => [...], 'total' => int, 'page' => int, 'per_page' => int]
     */
    public function paginate(int $page = 1, int $perPage = 15): array;
}
