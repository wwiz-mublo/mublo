<?php
namespace Mublo\Service\Block;

use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockColumnContentRepository;
use Mublo\Repository\Block\BlockPageRepository;
use Mublo\Repository\Block\BlockRowRevisionRepository;
use Mublo\Entity\Block\BlockRow;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Enum\Block\BlockPosition;
use Mublo\Infrastructure\Database\Database;
use Mublo\Helper\Form\FormHelper;
use Mublo\Core\Result\Result;

/**
 * BlockRow Service
 *
 * 블록 행(Row) 비즈니스 로직 담당
 *
 * 책임:
 * - 행 CRUD 비즈니스 로직
 * - 칸(Column) 동기화
 * - 정렬 순서 관리
 *
 * 금지:
 * - Request/Response 직접 처리 (Controller 담당)
 * - DB 직접 접근 (Repository 담당)
 */
class BlockRowService
{
    private BlockRowRepository $repository;
    private BlockColumnRepository $columnRepository;
    private BlockPageRepository $pageRepository;
    private Database $db;
    private BlockRenderService $renderService;
    private BlockColumnPayloadNormalizer $columnNormalizer;
    private ?BlockImageProcessor $imageProcessor;
    private ?BlockRowRevisionRepository $revisionRepository;
    private ?BlockColumnContentService $columnWriter;
    private ?BlockColumnContentRepository $contentRepository;

    /**
     * 유효한 위치 목록
     */
    public const VALID_POSITIONS = ['topbar', 'index', 'left', 'right', 'subhead', 'subfoot', 'contenthead', 'contentfoot'];

    /**
     * "메인화면 전용" 특별 위치 값 (Repository 상수 재노출)
     *
     * Controller 가 Repository 를 직접 참조하지 않고 이 값을 쓰도록 Service 에서 노출한다.
     */
    public const POSITION_MENU_MAIN_ONLY = BlockRowRepository::POSITION_MENU_MAIN_ONLY;

    public function __construct(
        BlockRowRepository $repository,
        BlockColumnRepository $columnRepository,
        BlockPageRepository $pageRepository,
        Database $db,
        BlockRenderService $renderService,
        BlockColumnPayloadNormalizer $columnNormalizer,
        ?BlockImageProcessor $imageProcessor = null,
        ?BlockRowRevisionRepository $revisionRepository = null,
        ?BlockColumnContentService $columnWriter = null,
        ?BlockColumnContentRepository $contentRepository = null
    ) {
        $this->repository = $repository;
        $this->columnRepository = $columnRepository;
        $this->pageRepository = $pageRepository;
        $this->db = $db;
        $this->renderService = $renderService;
        $this->columnNormalizer = $columnNormalizer;
        $this->imageProcessor = $imageProcessor;
        $this->revisionRepository = $revisionRepository;
        $this->columnWriter = $columnWriter;
        $this->contentRepository = $contentRepository;
    }

    /**
     * 인터랙티브 칸 저장 — 스택 인지 경로 (계획 6.2.0).
     * columnWriter 미주입 환경(레거시 테스트 등)은 기존 replaceByRow 폴백.
     */
    private function saveColumns(int $rowId, int $domainId, array $columnsData): void
    {
        if ($this->columnWriter !== null) {
            $this->columnWriter->saveColumns($rowId, $domainId, $columnsData);
            return;
        }

        $this->columnRepository->replaceByRow($rowId, $domainId, $columnsData);
    }

    /**
     * 스택 칸의 하위 콘텐츠 조회 (비활성 포함 — snapshot·복사·이미지 참조는
     * 관리자 소비처, 계획 6.2.2).
     *
     * @return \Mublo\Entity\Block\BlockColumnContent[]
     */
    private function stackContentsFor(BlockColumn $column): array
    {
        if ($this->contentRepository === null || !$column->isStack()) {
            return [];
        }

        try {
            return $this->contentRepository->findByColumnForDomain(
                $column->getColumnId(),
                $column->getDomainId(),
                true
            );
        } catch (\Throwable) {
            return []; // 콘텐츠 테이블 미설치 환경
        }
    }

    /**
     * 스냅샷·복사용 칸 배열 — 스택 칸에 contents 를 포함한다 (계획 9.2).
     *
     * @param BlockColumn[] $columns
     * @return array<int, array<string, mixed>>
     */
    private function columnsToPortableArrays(array $columns): array
    {
        $result = [];
        foreach ($columns as $column) {
            $data = $column->toArray();
            if ($column->isStack()) {
                $data['contents'] = array_map(
                    static fn ($content) => $content->toArray(),
                    $this->stackContentsFor($column)
                );
            }
            $result[] = $data;
        }

        return $result;
    }

    /**
     * 복구·복사 payload 정리 — 원본 column_id·content_id 를 재사용하지 않는다
     * (계획 9.2: 새 ID 발급, 순서·설정만 복원).
     *
     * @param array<int, array<string, mixed>> $columnsData
     * @return array<int, array<string, mixed>>
     */
    private function stripPortableIds(array $columnsData): array
    {
        foreach ($columnsData as $i => $columnData) {
            unset($columnData['column_id']);
            if (isset($columnData['contents']) && is_array($columnData['contents'])) {
                foreach ($columnData['contents'] as $j => $contentData) {
                    unset($contentData['content_id'], $contentData['column_id'], $contentData['domain_id']);
                    $columnData['contents'][$j] = $contentData;
                }
            }
            $columnsData[$i] = $columnData;
        }

        return $columnsData;
    }

    /**
     * 캐시 무효화 (행 변경 후 호출)
     */
    private function invalidateCache(BlockRow $row): void
    {
        $this->renderService->invalidateRowRelatedCache($row);
    }

    /**
     * 도메인 소유권 검증
     *
     * @param BlockRow $row 검증 대상 행
     * @param int|null $domainId 요청 도메인 ID (null이면 검증 생략)
     * @return Result|null 검증 실패 시 Result, 통과 시 null
     */
    private function verifyDomain(BlockRow $row, ?int $domainId): ?Result
    {
        if ($domainId !== null && $row->getDomainId() !== $domainId) {
            return Result::failure('해당 행에 대한 권한이 없습니다.');
        }
        return null;
    }

    /**
     * 도메인별 행 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return BlockRow[]
     */
    public function getRows(int $domainId): array
    {
        return $this->repository->findByDomain($domainId);
    }

    /**
     * 위치별 행 목록 조회 (관리자용)
     *
     * @param int $domainId 도메인 ID
     * @param string|null $position 위치 필터
     * @param string|null $menuCode 메뉴 필터 (전역 + 해당 메뉴로 좁힘)
     * @return BlockRow[]
     */
    public function getRowsByPosition(int $domainId, ?string $position = null, ?string $menuCode = null): array
    {
        return $this->repository->findAllByPosition($domainId, $position, $menuCode);
    }

    /**
     * 페이지별 행 목록 조회 (관리자용)
     *
     * @param int $pageId 페이지 ID
     * @return BlockRow[]
     */
    public function getRowsByPage(int $pageId): array
    {
        return $this->repository->findAllByPage($pageId);
    }

    /**
     * 위치별 활성 행 목록 조회 (프론트용)
     *
     * @param int $domainId 도메인 ID
     * @param string $position 위치
     * @param string|null $menuCode 메뉴 코드
     * @return BlockRow[]
     */
    public function getActiveRowsByPosition(int $domainId, string $position, ?string $menuCode = null): array
    {
        return $this->repository->findByPosition($domainId, $position, $menuCode);
    }

    /**
     * 페이지별 활성 행 목록 조회 (프론트용)
     *
     * @param int $pageId 페이지 ID
     * @return BlockRow[]
     */
    public function getActiveRowsByPage(int $pageId): array
    {
        return $this->repository->findByPage($pageId);
    }

    /**
     * 단일 행 조회
     */
    public function getRow(int $rowId): ?BlockRow
    {
        return $this->repository->find($rowId);
    }

    /**
     * 행 생성 (칸 포함)
     *
     * @param int $domainId 도메인 ID
     * @param array $data 행 데이터
     * @param array $columnsData 칸 데이터 배열
     * @return Result
     */
    public function createRow(
        int $domainId,
        array $data,
        array $columnsData = [],
        ?BlockColumnWriteContext $columnContext = null
    ): Result
    {
        // 위치 또는 페이지 중 정확히 하나 필수 (상호배타)
        $hasPosition = !empty($data['position']);
        $hasPage = !empty($data['page_id']);

        if (!$hasPosition && !$hasPage) {
            return Result::failure('출력 위치 또는 페이지를 선택해주세요.');
        }
        if ($hasPosition && $hasPage) {
            return Result::failure('출력 위치와 페이지를 동시에 지정할 수 없습니다.');
        }

        // 위치 유효성 검사
        if ($hasPosition && !in_array($data['position'], self::VALID_POSITIONS, true)) {
            return Result::failure('유효하지 않은 위치입니다.');
        }

        // 페이지 존재 확인
        if ($hasPage) {
            $page = $this->pageRepository->find((int) $data['page_id']);
            if (!$page || $page->getDomainId() !== $domainId) {
                return Result::failure('선택한 페이지를 찾을 수 없습니다.');
            }
        }

        $normalizedColumns = $this->columnNormalizer->normalizeMany(
            $columnsData,
            $columnContext ?? BlockColumnWriteContext::interactive($domainId)
        );
        if (!$normalizedColumns->isOk()) {
            return Result::failure(
                $normalizedColumns->getFirstErrorMessage(),
                ['errors' => $normalizedColumns->getErrors()]
            );
        }
        $columnsData = $normalizedColumns->getNormalizedColumns();

        $this->db->beginTransaction();

        try {
            // 데이터 정규화
            $insertData = $this->normalizeData($data);
            $insertData['domain_id'] = $domainId;

            // 다음 정렬 순서
            if (!empty($data['page_id'])) {
                $insertData['sort_order'] = $this->repository->getNextSortOrderByPage((int) $data['page_id']);
            } else {
                $insertData['sort_order'] = $this->repository->getNextSortOrderByPosition($domainId, $data['position']);
            }

            // 행 생성
            $rowId = $this->repository->create($insertData);

            if (!$rowId) {
                throw new \Exception('행 생성 실패');
            }

            // 칸 생성
            if (!empty($columnsData)) {
                $this->saveColumns($rowId, $domainId, $columnsData);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return Result::failure('행 생성에 실패했습니다: ' . $e->getMessage());
        }

        $this->runPostCommit('created row cache invalidation', function () use ($rowId): void {
            $newRow = $this->repository->find($rowId);
            if ($newRow) {
                $this->invalidateCache($newRow);
            }
        });

        return Result::success('행이 생성되었습니다.', ['row_id' => $rowId]);
    }

    /**
     * 행 수정 (칸 동기화 포함)
     *
     * @param int $rowId 행 ID
     * @param array $data 수정 데이터
     * @param array $columnsData 칸 데이터 배열
     * @return Result
     */
    public function updateRow(
        int $rowId,
        array $data,
        array $columnsData = [],
        ?int $domainId = null,
        ?BlockColumnWriteContext $columnContext = null,
        ?int $expectedRevision = null,
        ?int $actorId = null
    ): Result
    {
        $row = $this->repository->find($rowId);

        if (!$row) {
            return Result::failure('행을 찾을 수 없습니다.');
        }

        // 도메인 소유권 검증
        $deny = $this->verifyDomain($row, $domainId);
        if ($deny) {
            return $deny;
        }

        // 위치 유효성 검사
        if (!empty($data['position']) && !in_array($data['position'], self::VALID_POSITIONS, true)) {
            return Result::failure('유효하지 않은 위치입니다.');
        }

        // 요청에 page_id가 없으면 기존 참조도 다시 검증한다. 저장된 FK를 신뢰하지 않는다.
        $effectivePageId = array_key_exists('page_id', $data)
            ? (int) ($data['page_id'] ?? 0)
            : (int) ($row->getPageId() ?? 0);
        if ($effectivePageId > 0) {
            $page = $this->pageRepository->find($effectivePageId);
            if (!$page || $page->getDomainId() !== $row->getDomainId()) {
                return Result::failure('선택한 페이지를 찾을 수 없습니다.');
            }
        }

        $shouldReplaceColumns = !empty($columnsData) || array_key_exists('columns', $data);
        if ($shouldReplaceColumns) {
            $normalizedColumns = $this->columnNormalizer->normalizeMany(
                $columnsData,
                $columnContext ?? BlockColumnWriteContext::interactive($row->getDomainId())
            );
            if (!$normalizedColumns->isOk()) {
                return Result::failure(
                    $normalizedColumns->getFirstErrorMessage(),
                    ['errors' => $normalizedColumns->getErrors()]
                );
            }
            $columnsData = $normalizedColumns->getNormalizedColumns();
        }

        // 수정 전 위치 정보 보존 (position 변경 시 이전 캐시 무효화용)
        $oldRow = clone $row;
        $oldImages = $this->imageProcessor !== null
            ? $this->collectRowImages(
                $oldRow,
                $this->columnRepository->findAllByRowForDomain($rowId, $row->getDomainId())
            )
            : [];
        $expectedRevision ??= $row->getRevisionNo();

        $this->db->beginTransaction();

        try {
            $this->recordRevision($row, 'interactive', $actorId);

            // 데이터 정규화
            $updateData = $this->normalizeData($data);

            // domain_id, sort_order는 수정 불가
            unset($updateData['domain_id'], $updateData['sort_order']);

            // 행 수정
            $affected = $this->repository->updateIfRevision($rowId, $expectedRevision, $updateData);
            if ($affected !== 1) {
                $this->db->rollBack();
                $current = $this->repository->find($rowId);
                return Result::failure(
                    '다른 운영자가 먼저 이 행을 수정했습니다. 화면을 새로고침한 뒤 다시 시도해 주세요.',
                    [
                        'conflict' => true,
                        'current_revision' => $current?->getRevisionNo(),
                    ]
                );
            }

            // 칸 동기화 (배열이 전달된 경우에만) — 스택 인지 경로
            if ($shouldReplaceColumns) {
                $this->saveColumns($rowId, $row->getDomainId(), $columnsData);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return Result::failure('행 수정에 실패했습니다: ' . $e->getMessage());
        }

        // 이 아래는 이미 커밋된 데이터의 후처리다. 캐시/파일 정리 장애를
        // 저장 실패로 오해시켜 운영자가 같은 수정을 다시 제출하게 하지 않는다.
        $this->runPostCommit('old row cache invalidation', fn () => $this->invalidateCache($oldRow));
        $this->runPostCommit('updated row cache invalidation', function () use ($rowId): void {
            $updatedRow = $this->repository->find($rowId);
            if ($updatedRow) {
                $this->invalidateCache($updatedRow);
            }
        });
        $prunedSnapshots = [];
        $this->runPostCommit(
            'block revision pruning',
            function () use ($row, $rowId, &$prunedSnapshots): void {
                $prunedSnapshots = $this->revisionRepository?->prune($row->getDomainId(), $rowId) ?? [];
            }
        );
        $this->runPostCommit(
            'block image cleanup',
            function () use ($oldImages, $prunedSnapshots): void {
                $prunedImages = $this->imageProcessor?->collectManagedImages($prunedSnapshots) ?? [];
                $this->imageProcessor?->cleanupUnreferenced(array_merge($oldImages, $prunedImages));
            }
        );

        return Result::success('행이 수정되었습니다.');
    }

    /**
     * 사용 여부만 변경 (부분 업데이트)
     *
     * updateRow()는 normalizeData()가 NOT NULL 필드 기본값을 무조건 주입하므로
     * is_active만 넘기면 width_type/column_count/column_margin 등이 기본값으로
     * 덮어써진다. 토글은 is_active 한 필드만 갱신해야 하므로 정규화를 우회한다.
     *
     * @param int $rowId 행 ID
     * @param bool $active 사용 여부
     * @param int|null $domainId 요청 도메인 ID (소유권 검증용)
     * @return Result
     */
    public function setActive(int $rowId, bool $active, ?int $domainId = null): Result
    {
        $row = $this->repository->find($rowId);

        if (!$row) {
            return Result::failure('행을 찾을 수 없습니다.');
        }

        // 도메인 소유권 검증
        $deny = $this->verifyDomain($row, $domainId);
        if ($deny) {
            return $deny;
        }

        if ($this->repository->updateIfRevision(
            $rowId,
            $row->getRevisionNo(),
            ['is_active' => $active ? 1 : 0]
        ) !== 1) {
            return Result::failure('다른 운영자가 먼저 이 행을 수정했습니다. 목록을 새로고침해 주세요.');
        }

        $this->runPostCommit('active row cache invalidation', function () use ($rowId): void {
            $updatedRow = $this->repository->find($rowId);
            if ($updatedRow) {
                $this->invalidateCache($updatedRow);
            }
        });

        return Result::success(
            $active ? '사용으로 변경되었습니다.' : '미사용으로 변경되었습니다.'
        );
    }

    /**
     * 행 캐시 갱신 (수동)
     *
     * 렌더 캐시(행 HTML + 위치/페이지 목록)를 무효화해 다음 요청에서 새로 렌더되게 한다.
     *
     * @param int $rowId 행 ID
     * @param int|null $domainId 요청 도메인 ID (소유권 검증용)
     * @return Result
     */
    public function refreshCache(int $rowId, ?int $domainId = null): Result
    {
        $row = $this->repository->find($rowId);

        if (!$row) {
            return Result::failure('행을 찾을 수 없습니다.');
        }

        $deny = $this->verifyDomain($row, $domainId);
        if ($deny) {
            return $deny;
        }

        $this->invalidateCache($row);

        return Result::success('캐시를 갱신했습니다.');
    }

    /**
     * 행 삭제 (연결된 칸도 함께 삭제)
     *
     * @param int $rowId 행 ID
     * @return Result
     */
    public function deleteRow(int $rowId, ?int $domainId = null, ?int $actorId = null): Result
    {
        $row = $this->repository->find($rowId);

        if (!$row) {
            return Result::failure('행을 찾을 수 없습니다.');
        }

        // 도메인 소유권 검증
        $deny = $this->verifyDomain($row, $domainId);
        if ($deny) {
            return $deny;
        }

        // 삭제 전에 캐시 무효화를 위한 정보 저장
        $rowForCache = clone $row;
        $oldImages = $this->imageProcessor !== null
            ? $this->collectRowImages(
                $row,
                $this->columnRepository->findAllByRowForDomain($rowId, $row->getDomainId())
            )
            : [];

        $this->db->beginTransaction();

        try {
            $this->recordRevision($row, 'delete', $actorId);

            // 연결된 칸 삭제
            $this->columnRepository->deleteByRow($rowId);

            // 행 삭제
            $this->repository->delete($rowId);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return Result::failure('행 삭제에 실패했습니다: ' . $e->getMessage());
        }

        $this->runPostCommit('deleted row cache invalidation', fn () => $this->invalidateCache($rowForCache));
        $this->runPostCommit(
            'deleted block image cleanup',
            fn () => $this->imageProcessor?->cleanupUnreferenced($oldImages)
        );

        return Result::success('행이 삭제되었습니다.');
    }

    /**
     * 정렬 순서 업데이트
     *
     * @param int[] $rowIds 정렬된 행 ID 배열
     * @return Result
     */
    public function updateOrder(array $rowIds, ?int $domainId = null): Result
    {
        if (empty($rowIds)) {
            return Result::failure('정렬할 행 목록이 비어있습니다.');
        }

        // 도메인 소유권 배치 검증
        if ($domainId !== null) {
            if (!$this->repository->verifyAllBelongToDomain($rowIds, $domainId)) {
                return Result::failure('권한이 없는 행이 포함되어 있습니다.');
            }
        }

        $result = $this->repository->updateOrder($rowIds);

        if ($result) {
            $this->runPostCommit('ordered row cache invalidation', function () use ($rowIds): void {
                // 첫 번째 행 기준으로 위치/페이지 목록 캐시를 무효화한다.
                $firstRow = $this->repository->find((int) $rowIds[0]);
                if ($firstRow) {
                    $this->invalidateCache($firstRow);
                }
            });
            return Result::success('정렬 순서가 변경되었습니다.');
        }

        return Result::failure('정렬 순서 변경에 실패했습니다.');
    }

    /**
     * 정렬 순서 직접 설정
     *
     * @param array $orders [row_id => sort_order, ...]
     * @return Result
     */
    public function setOrder(array $orders, ?int $domainId = null): Result
    {
        if (empty($orders)) {
            return Result::failure('순서 정보가 비어있습니다.');
        }

        // 도메인 소유권 배치 검증
        if ($domainId !== null) {
            $rowIds = array_keys($orders);
            if (!$this->repository->verifyAllBelongToDomain($rowIds, $domainId)) {
                return Result::failure('권한이 없는 행이 포함되어 있습니다.');
            }
        }

        $updated = 0;
        $rowsToInvalidate = [];

        foreach ($orders as $rowId => $sortOrder) {
            $rowId = (int) $rowId;
            $sortOrder = (int) $sortOrder;

            $row = $this->repository->find($rowId);
            if ($row) {
                if ($this->repository->updateIfRevision(
                    $rowId,
                    $row->getRevisionNo(),
                    ['sort_order' => $sortOrder]
                ) === 1) {
                    $rowsToInvalidate[] = $row;
                    $updated++;
                }
            }
        }

        foreach ($rowsToInvalidate as $row) {
            $this->runPostCommit('direct-order row cache invalidation', fn () => $this->invalidateCache($row));
        }

        if ($updated > 0) {
            return Result::success("{$updated}개 항목의 순서가 변경되었습니다.");
        }

        return Result::failure('변경된 항목이 없습니다.');
    }

    /**
     * @param array<int, \Mublo\Entity\Block\BlockColumn> $columns
     * @return string[]
     */
    private function collectRowImages(BlockRow $row, array $columns): array
    {
        if ($this->imageProcessor === null) {
            return [];
        }

        $values = [$row->getBackgroundConfig()];
        foreach ($columns as $column) {
            $values[] = $column->getBackgroundConfig();
            $values[] = $column->getTitleConfig();
            $values[] = $column->getContentConfig();
            $values[] = $column->getContentItems();

            // 스택 하위 콘텐츠의 이미지도 참조 집합에 포함 — 한 콘텐츠에서
            // 빠져도 다른 콘텐츠·revision 이 참조하면 삭제되지 않는다 (계획 10장)
            foreach ($this->stackContentsFor($column) as $content) {
                $values[] = $content->getTitleConfig();
                $values[] = $content->getContentConfig();
                $values[] = $content->getContentItems();
            }
        }

        return $this->imageProcessor->collectManagedImages(...$values);
    }

    /** @return array<int, array<string, mixed>> */
    public function getRevisions(int $rowId, int $domainId, int $limit = 20): array
    {
        $row = $this->repository->find($rowId);
        if (!$row || $row->getDomainId() !== $domainId || $this->revisionRepository === null) {
            return [];
        }

        return $this->revisionRepository->findByRow($domainId, $rowId, $limit);
    }

    /** @return array<int, array<string, mixed>> */
    public function getDeletedRevisions(int $domainId, int $limit = 50): array
    {
        return $this->revisionRepository?->findRestorableDeleted($domainId, $limit) ?? [];
    }

    /** 현재 행과 모든 칸을 변경 전 이력으로 저장한다. 호출자는 트랜잭션 안에서 사용한다. */
    public function recordRevision(BlockRow $row, string $source = 'interactive', ?int $actorId = null): ?int
    {
        if ($this->revisionRepository === null) {
            return null;
        }

        $columns = $this->columnRepository->findAllByRowForDomain($row->getRowId(), $row->getDomainId());
        $snapshot = [
            'row' => $row->toArray(),
            // 스택 칸은 contents 포함 (계획 9.2 — 기존 snapshot 에 contents 가
            // 없으면 복구 시 single 형식으로 처리된다)
            'columns' => $this->columnsToPortableArrays($columns),
        ];

        return $this->revisionRepository->create(
            $row->getDomainId(),
            $row->getRowId(),
            $row->getRevisionNo(),
            $snapshot,
            $source,
            $actorId
        );
    }

    public function restoreRevision(
        int $domainId,
        int $revisionId,
        ?int $expectedRevision = null,
        ?int $actorId = null,
        bool $allowInclude = false
    ): Result {
        if ($this->revisionRepository === null) {
            return Result::failure('블록 행 이력 기능을 사용할 수 없습니다.');
        }

        $revision = $this->revisionRepository->findScoped($domainId, $revisionId);
        $snapshot = $revision['snapshot'] ?? null;
        $rowData = is_array($snapshot) ? ($snapshot['row'] ?? null) : null;
        $columnsData = is_array($snapshot) ? ($snapshot['columns'] ?? null) : null;
        if (!is_array($rowData) || !is_array($columnsData)) {
            return Result::failure('복구할 이력 데이터가 올바르지 않습니다.');
        }

        $originalRowId = (int) ($revision['row_id'] ?? 0);
        $current = $this->repository->find($originalRowId);
        if ($current && $current->getDomainId() !== $domainId) {
            return Result::failure('복구할 행을 찾을 수 없습니다.');
        }

        $pageId = (int) ($rowData['page_id'] ?? 0);
        if ($pageId > 0) {
            $page = $this->pageRepository->find($pageId);
            if (!$page || $page->getDomainId() !== $domainId) {
                return Result::failure('복구 대상 페이지가 존재하지 않습니다.');
            }
        }

        // 복구는 새 구조 생성이다 — 원본 column_id·content_id 를 재사용하지
        // 않고(계획 9.2) 전량 새로 발급한다
        $columnsData = $this->stripPortableIds($columnsData);

        $normalized = $this->columnNormalizer->normalizeMany(
            $columnsData,
            BlockColumnWriteContext::revisionRestore($domainId, $allowInclude)
        );
        if (!$normalized->isOk()) {
            return Result::failure($normalized->getFirstErrorMessage());
        }

        $oldImages = $current && $this->imageProcessor !== null
            ? $this->collectRowImages(
                $current,
                $this->columnRepository->findAllByRowForDomain($current->getRowId(), $domainId)
            )
            : [];

        unset(
            $rowData['row_id'],
            $rowData['created_at'],
            $rowData['updated_at'],
            $rowData['revision_no']
        );
        $rowData['domain_id'] = $domainId;
        if (isset($rowData['background_config']) && is_array($rowData['background_config'])) {
            $rowData['background_config'] = json_encode($rowData['background_config'], JSON_UNESCAPED_UNICODE);
        }

        $this->db->beginTransaction();
        try {
            if ($current) {
                $this->recordRevision($current, 'restore', $actorId);
                $expectedRevision ??= $current->getRevisionNo();
                unset($rowData['domain_id']);
                if ($this->repository->updateIfRevision($current->getRowId(), $expectedRevision, $rowData) !== 1) {
                    $this->db->rollBack();
                    return Result::failure(
                        '다른 운영자가 먼저 이 행을 수정했습니다. 이력을 다시 불러와 주세요.',
                        ['conflict' => true]
                    );
                }
                $rowId = $current->getRowId();
            } else {
                if (!$this->revisionRepository->claimRestore($domainId, $originalRowId)) {
                    $this->db->rollBack();
                    return Result::failure(
                        '이 삭제 이력은 이미 복구됐습니다. 삭제 이력을 다시 불러와 주세요.',
                        ['conflict' => true]
                    );
                }
                $rowData['revision_no'] = 1;
                $rowId = (int) $this->repository->create($rowData);
                if ($rowId <= 0) {
                    throw new \RuntimeException('삭제된 행을 다시 만들지 못했습니다.');
                }
                $this->revisionRepository->markRestored($domainId, $originalRowId, $rowId);
            }

            // 복구는 전체 교체 의미 — 기존 칸(스택 콘텐츠 cascade 포함)을 비운 뒤
            // 스냅샷 구조를 새로 만든다. 빈 행에 대한 stable sync 는 전량 INSERT 라
            // 구형 payload 방어와 충돌하지 않는다.
            $this->columnRepository->deleteByRow($rowId);
            $this->saveColumns($rowId, $domainId, $normalized->getNormalizedColumns());
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return Result::failure('블록 행을 복구하지 못했습니다: ' . $e->getMessage());
        }

        if ($current) {
            $this->runPostCommit('restored old row cache invalidation', fn () => $this->invalidateCache($current));
        }
        $this->runPostCommit('restored row cache invalidation', function () use ($rowId): void {
            $restored = $this->repository->find($rowId);
            if ($restored) {
                $this->invalidateCache($restored);
            }
        });
        $prunedSnapshots = [];
        $this->runPostCommit(
            'restored revision pruning',
            function () use ($domainId, $originalRowId, &$prunedSnapshots): void {
                $prunedSnapshots = $this->revisionRepository->prune($domainId, $originalRowId);
            }
        );
        $this->runPostCommit(
            'restored block image cleanup',
            function () use ($oldImages, $prunedSnapshots): void {
                $prunedImages = $this->imageProcessor?->collectManagedImages($prunedSnapshots) ?? [];
                $this->imageProcessor?->cleanupUnreferenced(array_merge($oldImages, $prunedImages));
            }
        );

        return Result::success('선택한 이력으로 복구했습니다.', ['row_id' => $rowId]);
    }

    private function runPostCommit(string $operation, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            error_log('[BlockRowService] post_commit_failed operation=' . $operation
                . ' error=' . $e->getMessage());
        }
    }

    /**
     * 행 복사 (칸 포함)
     *
     * @param int $rowId 원본 행 ID
     * @param string|null $targetPosition 대상 위치 (null이면 page_id 필수)
     * @param int|null $targetPageId 대상 페이지 ID (null이면 position 필수)
     * @param int|null $targetDomainId 대상 도메인 ID (null이면 같은 도메인)
     * @return Result
     */
    public function copyRow(
        int $rowId,
        ?string $targetPosition = null,
        ?int $targetPageId = null,
        ?int $targetDomainId = null
    ): Result {
        // 원본 행 조회
        $sourceRow = $this->repository->find($rowId);
        if (!$sourceRow) {
            return Result::failure('복사할 행을 찾을 수 없습니다.');
        }

        // 대상 위치/페이지 결정
        $position = $targetPosition ?? $sourceRow->getPosition();
        $pageId = $targetPageId ?? $sourceRow->getPageId();
        $domainId = $targetDomainId ?? $sourceRow->getDomainId();

        // 교차 도메인 복사 방지: 원본과 대상 도메인이 다르면 거부
        if ($domainId !== $sourceRow->getDomainId()) {
            return Result::failure('다른 도메인으로의 복사는 허용되지 않습니다.');
        }

        // 대상 페이지 도메인 교차 검증
        if ($pageId) {
            $page = $this->pageRepository->find((int) $pageId);
            if (!$page || $page->getDomainId() !== $domainId) {
                return Result::failure('대상 페이지를 찾을 수 없습니다.');
            }
        }

        // 위치 또는 페이지 중 하나 필수
        if (empty($position) && empty($pageId)) {
            return Result::failure('대상 위치 또는 페이지를 지정해주세요.');
        }

        // 위치 유효성 검사
        if (!empty($position) && !in_array($position, self::VALID_POSITIONS, true)) {
            return Result::failure('유효하지 않은 위치입니다.');
        }

        // row_id만으로 연결된 다른 도메인의 칸은 복사하지 않는다.
        $sourceColumns = $this->columnRepository->findAllByRowForDomain($rowId, $domainId);

        $this->db->beginTransaction();

        try {
            // 행 데이터 복사
            $newRowData = [
                'domain_id' => $domainId,
                'page_id' => $pageId ?: null,
                'position' => $pageId ? null : $position,
                'position_menu' => $sourceRow->getPositionMenu(),
                // section_id 는 <section id="..."> 로 렌더되는 DOM 앵커라 유일해야 한다.
                // 원본 값을 그대로 복사하면 원본/사본이 같은 id 를 갖게 되므로(중복 id),
                // saveRow 노멀라이저(라인 835-837)와 동일 규칙으로 새 유일 id 를 발급한다.
                'section_id' => 'section-' . bin2hex(random_bytes(4)),
                'admin_title' => $sourceRow->getAdminTitle() ? $sourceRow->getAdminTitle() . ' (복사)' : null,
                'width_type' => $sourceRow->getWidthType(),
                'column_count' => $sourceRow->getColumnCount(),
                'column_margin' => $sourceRow->getColumnMargin(),
                'column_width_unit' => $sourceRow->getColumnWidthUnit(),
                'pc_height' => $sourceRow->getPcHeight(),
                'mobile_height' => $sourceRow->getMobileHeight(),
                'pc_margin' => $sourceRow->getPcMargin(),
                'mobile_margin' => $sourceRow->getMobileMargin(),
                'pc_padding' => $sourceRow->getPcPadding(),
                'mobile_padding' => $sourceRow->getMobilePadding(),
                'background_config' => $sourceRow->getBackgroundConfig()
                    ? json_encode($sourceRow->getBackgroundConfig(), JSON_UNESCAPED_UNICODE)
                    : null,
                'is_active' => $sourceRow->isActive() ? 1 : 0,
            ];

            // 다음 정렬 순서
            if ($pageId) {
                $newRowData['sort_order'] = $this->repository->getNextSortOrderByPage($pageId);
            } else {
                $newRowData['sort_order'] = $this->repository->getNextSortOrderByPosition($domainId, $position);
            }

            // 새 행 생성
            $newRowId = $this->repository->create($newRowData);
            if (!$newRowId) {
                throw new \Exception('행 복사 실패');
            }

            // 칸 복사 — 스택 칸은 contents 포함, 원본 ID 는 재사용하지 않는다 (계획 9.2)
            $columnsData = $this->stripPortableIds($this->columnsToPortableArrays($sourceColumns));

            if (!empty($columnsData)) {
                $normalizedColumns = $this->columnNormalizer->normalizeMany(
                    $columnsData,
                    BlockColumnWriteContext::internalSeed($domainId)
                );
                if (!$normalizedColumns->isOk()) {
                    throw new \RuntimeException($normalizedColumns->getFirstErrorMessage());
                }
                $columnsData = $normalizedColumns->getNormalizedColumns();
                $this->saveColumns($newRowId, $domainId, $columnsData);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return Result::failure('행 복사에 실패했습니다: ' . $e->getMessage());
        }

        $this->runPostCommit('copied row cache invalidation', function () use ($newRowId): void {
            $newRow = $this->repository->find($newRowId);
            if ($newRow) {
                $this->invalidateCache($newRow);
            }
        });

        return Result::success('행이 복사되었습니다.', ['row_id' => $newRowId]);
    }

    /**
     * 행 이동
     *
     * @param int $rowId 행 ID
     * @param string|null $targetPosition 대상 위치 (null이면 page_id 필수)
     * @param int|null $targetPageId 대상 페이지 ID (null이면 position 필수)
     * @return Result
     */
    public function moveRow(int $rowId, ?string $targetPosition = null, ?int $targetPageId = null, ?int $domainId = null): Result
    {
        $row = $this->repository->find($rowId);
        if (!$row) {
            return Result::failure('이동할 행을 찾을 수 없습니다.');
        }

        // 도메인 소유권 검증
        $deny = $this->verifyDomain($row, $domainId);
        if ($deny) {
            return $deny;
        }

        // 위치 또는 페이지 중 하나 필수
        if (empty($targetPosition) && empty($targetPageId)) {
            return Result::failure('대상 위치 또는 페이지를 지정해주세요.');
        }

        // 위치 유효성 검사
        if (!empty($targetPosition) && !in_array($targetPosition, self::VALID_POSITIONS, true)) {
            return Result::failure('유효하지 않은 위치입니다.');
        }

        // 페이지 존재 확인 + 도메인 교차 검증
        if ($targetPageId) {
            $page = $this->pageRepository->find($targetPageId);
            if (!$page) {
                return Result::failure('대상 페이지를 찾을 수 없습니다.');
            }
            if ($page->getDomainId() !== $row->getDomainId()) {
                return Result::failure('대상 페이지를 찾을 수 없습니다.');
            }
        }

        $this->db->beginTransaction();

        try {
            $updateData = [];

            if ($targetPageId) {
                // 페이지 기반으로 이동
                $updateData['page_id'] = $targetPageId;
                $updateData['position'] = null;
                $updateData['sort_order'] = $this->repository->getNextSortOrderByPage($targetPageId);
            } else {
                // 위치 기반으로 이동
                $updateData['page_id'] = null;
                $updateData['position'] = $targetPosition;
                $updateData['sort_order'] = $this->repository->getNextSortOrderByPosition(
                    $row->getDomainId(),
                    $targetPosition
                );
            }

            // 이동 전 위치 캐시 무효화를 위한 정보 저장
            $oldRow = clone $row;

            $this->recordRevision($row, 'move');
            if ($this->repository->updateIfRevision($rowId, $row->getRevisionNo(), $updateData) !== 1) {
                throw new \RuntimeException('다른 운영자가 먼저 이 행을 수정했습니다.');
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return Result::failure('행 이동에 실패했습니다: ' . $e->getMessage());
        }

        $this->runPostCommit('moved old row cache invalidation', fn () => $this->invalidateCache($oldRow));
        $this->runPostCommit('moved row cache invalidation', function () use ($rowId): void {
            $updatedRow = $this->repository->find($rowId);
            if ($updatedRow) {
                $this->invalidateCache($updatedRow);
            }
        });

        return Result::success('행이 이동되었습니다.');
    }

    /**
     * 페이지네이션 (위치 기반)
     *
     * @param int $domainId 도메인 ID
     * @param string|null $position 위치 필터
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @return array
     */
    public function paginateByPosition(int $domainId, ?string $position = null, int $page = 1, int $perPage = 15): array
    {
        return $this->repository->paginateByPosition($domainId, $position, $page, $perPage);
    }

    /**
     * 유효한 위치 목록 반환
     */
    public function getValidPositions(): array
    {
        return BlockPosition::options();
    }

    /**
     * 데이터 정규화
     *
     * FormHelper::normalizeFormData() 활용 + 도메인 특화 후처리
     */
    private function normalizeData(array $data): array
    {
        // FormHelper 스키마 정의
        $schema = [
            'numeric' => ['page_id', 'width_type', 'column_count', 'column_margin', 'column_width_unit', 'sort_order', 'dismiss_hours'],
            'bool' => ['is_active', 'dismissible'],
        ];

        $normalized = FormHelper::normalizeFormData($data, $schema);

        // 도메인 특화 후처리

        // 메뉴 제한 없음은 DB에서 NULL로 통일한다.
        if (array_key_exists('position_menu', $normalized)) {
            $positionMenu = trim((string) ($normalized['position_menu'] ?? ''));
            $normalized['position_menu'] = $positionMenu === '' ? null : $positionMenu;
        }

        // 정수 필드 중 0 허용 필드 처리
        $zeroAllowedFields = ['width_type', 'column_margin', 'sort_order'];
        $positiveOnlyFields = ['page_id', 'column_count', 'column_width_unit'];

        foreach ($positiveOnlyFields as $field) {
            if (isset($normalized[$field]) && $normalized[$field] <= 0) {
                $normalized[$field] = null;
            }
        }

        // JSON 필드: background_config (배열 → JSON 문자열 변환)
        if (isset($data['background_config'])) {
            if (is_array($data['background_config'])) {
                $normalized['background_config'] = json_encode($data['background_config'], JSON_UNESCAPED_UNICODE);
            } elseif (is_string($data['background_config']) && !empty($data['background_config'])) {
                $normalized['background_config'] = $data['background_config'];
            } else {
                $normalized['background_config'] = null;
            }
        }

        // NOT NULL 필드 기본값 보장
        $defaults = [
            'column_margin' => 0,
            'column_count' => 1,
            'column_width_unit' => 1,
            'width_type' => 0,
            'is_active' => 1,
        ];
        foreach ($defaults as $field => $default) {
            if (!isset($normalized[$field]) || $normalized[$field] === null) {
                $normalized[$field] = $default;
            }
        }

        // 칸 수 제한 (1~4)
        if (isset($normalized['column_count'])) {
            $normalized['column_count'] = max(1, min(4, $normalized['column_count']));
        }

        // section_id 자동 생성 (빈 값이면)
        if (empty($normalized['section_id'])) {
            $normalized['section_id'] = 'section-' . bin2hex(random_bytes(4));
        }

        // 컨테이너 내부 위치는 width_type을 최대넓이(1)로 강제
        $wideAllowedPositions = [BlockPosition::TOPBAR->value, BlockPosition::INDEX->value, BlockPosition::SUBHEAD->value, BlockPosition::SUBFOOT->value];
        $position = $normalized['position'] ?? '';
        if ($position === BlockPosition::INDEX->value) {
            $normalized['position_menu'] = null;
        }
        if ($position !== '' && !in_array($position, $wideAllowedPositions, true)) {
            $normalized['width_type'] = 1;
        }

        // "보지 않기"는 topbar 위치에서만 유효 — 그 외에는 강제 해제
        if ($position !== BlockPosition::TOPBAR->value) {
            $normalized['dismissible'] = 0;
        }
        // 숨김 기간 기본/최소 보정 (1시간 미만은 1일로)
        if (!isset($normalized['dismiss_hours']) || (int) $normalized['dismiss_hours'] < 1) {
            $normalized['dismiss_hours'] = 24;
        }

        return $normalized;
    }
}
