<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Service;

use Mublo\Packages\Board\Repository\BoardCategoryRepository;
use Mublo\Packages\Board\Repository\BoardCategoryMappingRepository;
use Mublo\Packages\Board\Entity\BoardCategory;
use Mublo\Core\Result\Result;
use Mublo\Helper\Form\FormHelper;

/**
 * BoardCategory Service
 *
 * 게시판 카테고리 비즈니스 로직 담당
 *
 * 책임:
 * - 카테고리 CRUD 비즈니스 로직
 * - 유효성 검증
 * - 정렬 순서 관리
 *
 * 금지:
 * - Request/Response 직접 처리 (Controller 담당)
 * - DB 직접 접근 (Repository 담당)
 */
class BoardCategoryService
{
    private BoardCategoryRepository $repository;
    private BoardCategoryMappingRepository $mappingRepository;

    /**
     * 슬러그 예약어 목록
     */
    private const RESERVED_SLUGS = [
        'all',
        'none',
        'default',
        'notice',
        'admin',
    ];

    public function __construct(
        BoardCategoryRepository $repository,
        BoardCategoryMappingRepository $mappingRepository
    ) {
        $this->repository = $repository;
        $this->mappingRepository = $mappingRepository;
    }

    /**
     * 게시판별 카테고리 목록 조회
     *
     * @param int $boardId 게시판 ID
     * @return array [['category_id' => id, 'category_name' => name, 'category_slug' => slug], ...]
     */
    public function getCategoriesByBoard(int $boardId): array
    {
        $mappings = $this->mappingRepository->findByBoardWithCategory($boardId);

        return array_map(fn($item) => [
            'category_id' => $item['mapping']->getCategoryId(),
            'category_name' => $item['category_name'],
            'category_slug' => $item['category_slug'],
        ], $mappings);
    }

    /**
     * 도메인별 카테고리 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return BoardCategory[]
     */
    public function getCategories(int $domainId): array
    {
        return $this->repository->findByDomain($domainId);
    }

    /**
     * 도메인별 카테고리 목록 조회 (게시판 수 포함, 페이지네이션)
     *
     * @param int $domainId 도메인 ID
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 항목 수
     * @return array ['items' => [...], 'pagination' => [...]]
     */
    public function getCategoriesWithCount(int $domainId, int $page = 1, int $perPage = 20): array
    {
        $totalItems = $this->repository->countByDomain($domainId);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $categories = $this->repository->findByDomain($domainId, $perPage, $offset);
        $items = [];

        foreach ($categories as $category) {
            $data = $category->toArray();
            $data['board_count'] = $this->repository->getBoardCount($category->getCategoryId());
            $items[] = $data;
        }

        return [
            'items' => $items,
            'pagination' => [
                'totalItems' => $totalItems,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => $totalPages,
            ],
        ];
    }

    /**
     * 카테고리에 속한 게시판 수 조회
     *
     * @param int $categoryId 카테고리 ID
     * @return int
     */
    public function getBoardCount(int $categoryId): int
    {
        return $this->repository->getBoardCount($categoryId);
    }

    /**
     * 도메인별 활성 카테고리 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return BoardCategory[]
     */
    public function getActiveCategories(int $domainId): array
    {
        return $this->repository->findActiveByDomain($domainId);
    }

    /**
     * 단일 카테고리 조회
     */
    public function getCategory(int $categoryId): ?BoardCategory
    {
        return $this->repository->find($categoryId);
    }

    /**
     * 슬러그로 카테고리 조회
     */
    public function getCategoryBySlug(int $domainId, string $slug): ?BoardCategory
    {
        return $this->repository->findBySlug($domainId, $slug);
    }

    /**
     * 카테고리 생성
     *
     * @param int $domainId 도메인 ID
     * @param array $data 카테고리 데이터
     * @return Result
     */
    public function createCategory(int $domainId, array $data): Result
    {
        // 필수 필드 검증
        if (empty($data['category_slug']) || empty($data['category_name'])) {
            return Result::failure('필수 필드가 누락되었습니다. (슬러그, 카테고리명)');
        }

        $slug = $data['category_slug'];

        // 슬러그 유효성 검증
        $slugValidation = $this->validateSlug($slug);
        if ($slugValidation->isFailure()) {
            return $slugValidation;
        }

        // 슬러그 중복 검사
        if ($this->repository->existsBySlug($domainId, $slug)) {
            return Result::failure('이미 사용중인 슬러그입니다.');
        }

        // 다음 정렬 순서
        $sortOrder = $this->repository->getNextSortOrder($domainId);

        // 데이터 정규화
        $insertData = $this->normalizeData($data);
        $insertData['domain_id'] = $domainId;
        $insertData['sort_order'] = $sortOrder;

        // 생성
        $categoryId = $this->repository->create($insertData);

        if ($categoryId) {
            return Result::success('카테고리가 생성되었습니다.', ['category_id' => $categoryId]);
        }

        return Result::failure('카테고리 생성에 실패했습니다.');
    }

    /**
     * 카테고리 수정
     *
     * @param int $categoryId 카테고리 ID
     * @param array $data 수정 데이터
     * @return Result
     */
    public function updateCategory(int $categoryId, array $data): Result
    {
        $category = $this->repository->find($categoryId);

        if (!$category) {
            return Result::failure('카테고리를 찾을 수 없습니다.');
        }

        // 슬러그 변경 시 유효성 및 중복 검사
        if (!empty($data['category_slug']) && $data['category_slug'] !== $category->getCategorySlug()) {
            $slugValidation = $this->validateSlug($data['category_slug']);
            if ($slugValidation->isFailure()) {
                return $slugValidation;
            }

            if ($this->repository->existsBySlugExceptSelf($category->getDomainId(), $data['category_slug'], $categoryId)) {
                return Result::failure('이미 사용중인 슬러그입니다.');
            }
        }

        // 데이터 정규화
        $updateData = $this->normalizeData($data);

        // domain_id, sort_order는 수정 불가
        unset($updateData['domain_id'], $updateData['sort_order']);

        // 수정
        $affected = $this->repository->update($categoryId, $updateData);

        if ($affected >= 0) {
            return Result::success('카테고리가 수정되었습니다.');
        }

        return Result::failure('카테고리 수정에 실패했습니다.');
    }

    /**
     * 카테고리 삭제
     *
     * @param int $categoryId 카테고리 ID
     * @return Result
     */
    public function deleteCategory(int $categoryId): Result
    {
        $category = $this->repository->find($categoryId);

        if (!$category) {
            return Result::failure('카테고리를 찾을 수 없습니다.');
        }

        // 카테고리를 사용하는 게시판이 있는지 확인
        $boardCount = $this->repository->getBoardCount($categoryId);
        if ($boardCount > 0) {
            return Result::failure("카테고리를 사용하는 게시판({$boardCount}개)이 있어 삭제할 수 없습니다.");
        }

        // 삭제
        $affected = $this->repository->delete($categoryId);

        if ($affected > 0) {
            return Result::success('카테고리가 삭제되었습니다.');
        }

        return Result::failure('카테고리 삭제에 실패했습니다.');
    }

    /**
     * 일괄 활성화 상태 수정
     *
     * @param array $items [categoryId => isActive, ...]
     * @return Result
     */
    public function batchUpdateIsActive(array $items): Result
    {
        if (empty($items)) {
            return Result::failure('수정할 항목이 없습니다.', ['updated' => 0]);
        }

        $updated = 0;
        foreach ($items as $categoryId => $isActive) {
            $categoryId = (int) $categoryId;
            $category = $this->repository->find($categoryId);

            if ($category) {
                $this->repository->update($categoryId, [
                    'is_active' => (int) (bool) $isActive,
                ]);
                $updated++;
            }
        }

        if ($updated > 0) {
            return Result::success("{$updated}개 항목이 수정되었습니다.", ['updated' => $updated]);
        }

        return Result::failure('수정된 항목이 없습니다.', ['updated' => 0]);
    }

    /**
     * 슬러그 사용 가능 여부 확인
     *
     * @param int $domainId 도메인 ID
     * @param string $slug 슬러그
     * @param int|null $excludeId 제외할 카테고리 ID (수정 시)
     * @return Result
     */
    public function checkSlugAvailability(int $domainId, string $slug, ?int $excludeId = null): Result
    {
        // 슬러그 유효성 검증
        $validation = $this->validateSlug($slug);
        if ($validation->isFailure()) {
            return $validation;
        }

        // 중복 검사
        $exists = $excludeId
            ? $this->repository->existsBySlugExceptSelf($domainId, $slug, $excludeId)
            : $this->repository->existsBySlug($domainId, $slug);

        if ($exists) {
            return Result::failure('이미 사용중인 슬러그입니다.');
        }

        return Result::success('사용 가능한 슬러그입니다.');
    }

    /**
     * 정렬 순서 업데이트
     *
     * @param int $domainId 도메인 ID
     * @param int[] $categoryIds 정렬된 카테고리 ID 배열
     * @return Result
     */
    public function updateOrder(int $domainId, array $categoryIds): Result
    {
        if (empty($categoryIds)) {
            return Result::failure('정렬할 카테고리 목록이 비어있습니다.');
        }

        $result = $this->repository->updateOrder($categoryIds);

        if ($result) {
            return Result::success('정렬 순서가 변경되었습니다.');
        }

        return Result::failure('정렬 순서 변경에 실패했습니다.');
    }

    /**
     * 선택 옵션 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return array [['value' => id, 'label' => name], ...]
     */
    public function getSelectOptions(int $domainId): array
    {
        return $this->repository->getSelectOptions($domainId);
    }

    /**
     * 슬러그 유효성 검증
     *
     * @param string $slug 슬러그
     * @return Result
     */
    public function validateSlug(string $slug): Result
    {
        // 빈 문자열 검사
        if (empty($slug)) {
            return Result::failure('슬러그를 입력해주세요.');
        }

        // 길이 검사 (2~50자)
        if (strlen($slug) < 2 || strlen($slug) > 50) {
            return Result::failure('슬러그는 2~50자 사이로 입력해주세요.');
        }

        // 형식 검사 (영문 소문자, 숫자, 하이픈만 허용)
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return Result::failure('슬러그는 영문 소문자, 숫자, 하이픈(-)만 사용할 수 있습니다.');
        }

        // 시작/끝 하이픈 검사
        if (str_starts_with($slug, '-') || str_ends_with($slug, '-')) {
            return Result::failure('슬러그는 하이픈으로 시작하거나 끝날 수 없습니다.');
        }

        // 예약어 검사
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return Result::failure('예약된 슬러그는 사용할 수 없습니다.');
        }

        return Result::success();
    }

    /**
     * 데이터 정규화
     *
     * FormHelper::normalizeFormData() 활용
     *
     * @param array $data 입력 데이터
     * @return array 정규화된 데이터
     */
    private function normalizeData(array $data): array
    {
        // FormHelper 스키마 정의
        $schema = [
            'numeric' => ['sort_order'],
            'bool' => ['is_active'],
        ];

        // 서비스 계층은 부분 데이터 호출을 허용한다 — 입력에 존재하는 bool 만 강제.
        // "빈 체크박스 = 해제(0)" 채움은 관리자 컨트롤러 경계에서 이미 끝났다.
        $schema['bool'] = array_values(array_intersect($schema['bool'], array_keys($data)));

        $normalized = FormHelper::normalizeFormData($data, $schema);

        // 도메인 특화 후처리: 슬러그 소문자 변환
        if (isset($normalized['category_slug'])) {
            $normalized['category_slug'] = strtolower($normalized['category_slug']);
        }

        return $normalized;
    }
}
