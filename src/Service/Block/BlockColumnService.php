<?php
namespace Mublo\Service\Block;

use Mublo\Repository\Block\BlockColumnContentRepository;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Core\Result\Result;

/**
 * BlockColumn Service
 *
 * 블록 칸(Column) 비즈니스 로직 담당
 *
 * 책임:
 * - 칸 CRUD 비즈니스 로직
 * - 콘텐츠 설정 관리
 * - 입력 검증 및 보안 필터링
 *
 * 금지:
 * - Request/Response 직접 처리 (Controller 담당)
 * - DB 직접 접근 (Repository 담당)
 */
class BlockColumnService
{
    private BlockColumnRepository $repository;
    private BlockRowRepository $rowRepository;
    private BlockColumnPayloadNormalizer $columnNormalizer;
    private ?BlockRenderService $renderService;
    private ?BlockColumnContentRepository $contentRepository;

    public function __construct(
        BlockColumnRepository $repository,
        BlockRowRepository $rowRepository,
        BlockColumnPayloadNormalizer $columnNormalizer,
        ?BlockRenderService $renderService = null,
        ?BlockColumnContentRepository $contentRepository = null
    ) {
        $this->repository = $repository;
        $this->rowRepository = $rowRepository;
        $this->columnNormalizer = $columnNormalizer;
        $this->renderService = $renderService;
        $this->contentRepository = $contentRepository;
    }

    /**
     * 스택 칸의 하위 콘텐츠 (비활성 포함 — 관리자 소비처, 계획 6.2.2).
     *
     * @return \Mublo\Entity\Block\BlockColumnContent[]
     */
    public function getStackContents(BlockColumn $column): array
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
     * 편집용 칸 배열 변환 — 스택 칸에 하위 콘텐츠를 포함한다.
     *
     * 관리자 편집은 비활성 콘텐츠도 불러와야 재활성화·수정이 가능하다 (계획 6.2.2).
     *
     * @param \Mublo\Entity\Block\BlockColumn[] $columns
     * @return array<int, array<string, mixed>>
     */
    public function toEditableArrays(array $columns, int $domainId): array
    {
        $stackColumnIds = [];
        foreach ($columns as $column) {
            if ($column->isStack()) {
                $stackColumnIds[] = $column->getColumnId();
            }
        }

        $grouped = [];
        if ($stackColumnIds !== [] && $this->contentRepository !== null) {
            try {
                $grouped = $this->contentRepository->findByColumnsForDomain($stackColumnIds, $domainId, true);
            } catch (\Throwable) {
                // 콘텐츠 테이블 미설치 — contents 없이 반환
            }
        }

        $result = [];
        foreach ($columns as $column) {
            $data = $column->toArray();
            if ($column->isStack()) {
                $data['contents'] = array_map(
                    static fn($content) => $content->toArray(),
                    $grouped[$column->getColumnId()] ?? []
                );
            }
            $result[] = $data;
        }

        return $result;
    }

    /**
     * 행별 칸 목록 조회
     *
     * @param int $rowId 행 ID
     * @return BlockColumn[]
     */
    public function getColumnsByRow(int $rowId): array
    {
        return $this->repository->findAllByRow($rowId);
    }

    /**
     * 현재 도메인이 소유한 행의 칸 목록만 조회한다.
     *
     * 행이 없거나 다른 도메인 소유이면 null을 반환해 두 경우를 구분하지 않는다.
     * 비정상적으로 다른 도메인에 연결된 칸도 응답에서 제외한다.
     *
     * @return BlockColumn[]|null
     */
    public function getColumnsByRowForDomain(int $rowId, int $expectedDomainId): ?array
    {
        $row = $this->rowRepository->find($rowId);
        if (!$row || $row->getDomainId() !== $expectedDomainId) {
            return null;
        }

        return $this->repository->findAllByRowForDomain($rowId, $expectedDomainId);
    }

    /**
     * 행별 활성 칸 목록 조회 (프론트용)
     *
     * @param int $rowId 행 ID
     * @return BlockColumn[]
     */
    public function getActiveColumnsByRow(int $rowId): array
    {
        return $this->repository->findByRow($rowId);
    }

    /**
     * 단일 칸 조회
     */
    public function getColumn(int $columnId): ?BlockColumn
    {
        return $this->repository->find($columnId);
    }

    public function getColumnForDomain(int $columnId, int $expectedDomainId): ?BlockColumn
    {
        $column = $this->repository->find($columnId);

        return $column && $column->getDomainId() === $expectedDomainId ? $column : null;
    }

    /**
     * 칸 생성
     *
     * @param int $domainId 도메인 ID
     * @param int $rowId 행 ID
     * @param array $data 칸 데이터
     * @return Result
     */
    public function createColumn(
        int $domainId,
        int $rowId,
        array $data,
        ?BlockColumnWriteContext $context = null
    ): Result
    {
        // 행 존재 확인
        $row = $this->rowRepository->find($rowId);
        if (!$row || $row->getDomainId() !== $domainId) {
            return Result::failure('행을 찾을 수 없습니다.');
        }

        // 현재 칸 수 확인
        $currentCount = $this->repository->countByRowForDomain($rowId, $domainId);
        if ($currentCount >= 4) {
            return Result::failure('한 행에 최대 4개의 칸만 생성할 수 있습니다.');
        }

        $normalization = $this->columnNormalizer->normalize(
            $data,
            $context ?? BlockColumnWriteContext::interactive($domainId)
        );
        if (!$normalization->isOk()) {
            return Result::failure(
                $normalization->getFirstErrorMessage(),
                ['errors' => $normalization->getErrors()]
            );
        }

        $insertData = $this->encodeJsonFields($normalization->getNormalizedColumns()[0]);
        $insertData['domain_id'] = $domainId;
        $insertData['row_id'] = $rowId;
        $insertData['column_index'] = $currentCount;

        // 생성
        $columnId = $this->repository->create($insertData);

        if ($columnId) {
            return Result::success('칸이 생성되었습니다.', ['column_id' => $columnId]);
        }

        return Result::failure('칸 생성에 실패했습니다.');
    }

    /**
     * 칸 수정
     *
     * @param int $columnId 칸 ID
     * @param int $expectedDomainId 요청 도메인 ID
     * @param array $data 수정 데이터
     * @return Result
     */
    public function updateColumn(
        int $columnId,
        int $expectedDomainId,
        array $data,
        ?BlockColumnWriteContext $context = null
    ): Result
    {
        $column = $this->getColumnForDomain($columnId, $expectedDomainId);

        if (!$column) {
            return Result::failure('칸을 찾을 수 없습니다.');
        }

        if ($context !== null && $context->domainId !== $expectedDomainId) {
            return Result::failure('칸을 찾을 수 없습니다.');
        }

        $normalization = $this->columnNormalizer->normalize(
            $data,
            $context ?? BlockColumnWriteContext::interactive($column->getDomainId())
        );
        if (!$normalization->isOk()) {
            return Result::failure(
                $normalization->getFirstErrorMessage(),
                ['errors' => $normalization->getErrors()]
            );
        }

        $updateData = $this->encodeJsonFields($normalization->getNormalizedColumns()[0]);

        // row_id, domain_id, column_index는 수정 불가
        unset($updateData['row_id'], $updateData['domain_id'], $updateData['column_index']);

        // 수정
        $affected = $this->repository->update($columnId, $updateData);

        if ($affected >= 0) {
            $this->invalidateRowCache($column->getRowId());
            return Result::success('칸이 수정되었습니다.');
        }

        return Result::failure('칸 수정에 실패했습니다.');
    }

    /**
     * 칸 삭제
     *
     * @param int $columnId 칸 ID
     * @param int $expectedDomainId 요청 도메인 ID
     * @return Result
     */
    public function deleteColumn(int $columnId, int $expectedDomainId): Result
    {
        $column = $this->getColumnForDomain($columnId, $expectedDomainId);

        if (!$column) {
            return Result::failure('칸을 찾을 수 없습니다.');
        }

        $rowId = $column->getRowId();

        // 트랜잭션으로 삭제 + 재정렬 원자적 처리
        try {
            $this->repository->getDb()->transaction(function () use ($columnId, $rowId, $expectedDomainId) {
                // 삭제
                $this->repository->delete($columnId);
                // 남은 칸들의 인덱스 재정렬
                $this->reindexColumns($rowId, $expectedDomainId);
            });

            $this->invalidateRowCache($rowId);

            return Result::success('칸이 삭제되었습니다.');
        } catch (\Throwable $e) {
            return Result::failure('칸 삭제에 실패했습니다.');
        }
    }

    /**
     * 콘텐츠 설정 업데이트
     *
     * count/style은 content_config에 통합됨 (별도 파라미터 아님)
     *
     * @param int $columnId 칸 ID
     * @param int $expectedDomainId 요청 도메인 ID
     * @param string|null $type 콘텐츠 타입
     * @param string $kind 콘텐츠 종류 (CORE, PLUGIN, PACKAGE)
     * @param string|null $skin 스킨
     * @param array|null $config 추가 설정 (pc_count, mo_count, pc_style 등 포함)
     * @param array|null $items 선택된 아이템
     * @return array ['success' => bool, 'message' => string]
     */
    public function updateContent(
        int $columnId,
        int $expectedDomainId,
        ?string $type,
        string $kind = 'CORE',
        ?string $skin = null,
        ?array $config = null,
        ?array $items = null,
        ?BlockColumnWriteContext $context = null
    ): array {
        $column = $this->getColumnForDomain($columnId, $expectedDomainId);

        if (!$column) {
            return [
                'success' => false,
                'message' => '칸을 찾을 수 없습니다.',
            ];
        }

        if ($context !== null && $context->domainId !== $expectedDomainId) {
            return [
                'success' => false,
                'message' => '칸을 찾을 수 없습니다.',
            ];
        }

        $normalization = $this->columnNormalizer->normalize([
            'content_type' => $type,
            'content_kind' => $kind,
            'content_skin' => $skin,
            'content_config' => $config,
            'content_items' => $items,
        ], $context ?? BlockColumnWriteContext::interactive($column->getDomainId()));
        if (!$normalization->isOk()) {
            return [
                'success' => false,
                'message' => $normalization->getFirstErrorMessage(),
                'errors' => $normalization->getErrors(),
            ];
        }

        $normalized = $normalization->getNormalizedColumns()[0];

        $result = $this->repository->updateContent(
            $columnId,
            $normalized['content_type'] ?? null,
            $normalized['content_kind'] ?? 'CORE',
            $normalized['content_skin'] ?? null,
            $normalized['content_config'] ?? null,
            $normalized['content_items'] ?? null
        );

        if ($result) {
            $this->invalidateRowCache($column->getRowId());
            return [
                'success' => true,
                'message' => '콘텐츠 설정이 저장되었습니다.',
            ];
        }

        return [
            'success' => false,
            'message' => '콘텐츠 설정 저장에 실패했습니다.',
        ];
    }

    /**
     * 제목 설정 업데이트
     *
     * @param int $columnId 칸 ID
     * @param int $expectedDomainId 요청 도메인 ID
     * @param array|null $titleConfig 제목 설정
     * @return Result
     */
    public function updateTitleConfig(int $columnId, int $expectedDomainId, ?array $titleConfig): Result
    {
        $column = $this->getColumnForDomain($columnId, $expectedDomainId);

        if (!$column) {
            return Result::failure('칸을 찾을 수 없습니다.');
        }

        $result = $this->repository->updateTitleConfig($columnId, $titleConfig);

        if ($result) {
            $this->invalidateRowCache($column->getRowId());
            return Result::success('제목 설정이 저장되었습니다.');
        }

        return Result::failure('제목 설정 저장에 실패했습니다.');
    }

    /**
     * 스타일 설정 업데이트
     *
     * @param int $columnId 칸 ID
     * @param int $expectedDomainId 요청 도메인 ID
     * @param array|null $backgroundConfig 배경 설정
     * @param array|null $borderConfig 테두리 설정
     * @return Result
     */
    public function updateStyleConfig(
        int $columnId,
        int $expectedDomainId,
        ?array $backgroundConfig,
        ?array $borderConfig
    ): Result
    {
        $column = $this->getColumnForDomain($columnId, $expectedDomainId);

        if (!$column) {
            return Result::failure('칸을 찾을 수 없습니다.');
        }

        $result = $this->repository->updateStyleConfig($columnId, $backgroundConfig, $borderConfig);

        if ($result) {
            $this->invalidateRowCache($column->getRowId());
            return Result::success('스타일 설정이 저장되었습니다.');
        }

        return Result::failure('스타일 설정 저장에 실패했습니다.');
    }

    /**
     * 행 캐시 무효화
     *
     * 칸 변경 시 해당 행의 렌더링 캐시를 삭제합니다.
     */
    private function invalidateRowCache(int $rowId): void
    {
        $this->renderService?->invalidateRowContentCache($rowId);
    }

    /**
     * 칸 인덱스 재정렬
     *
     * @param int $rowId 행 ID
     */
    private function reindexColumns(int $rowId, int $domainId): void
    {
        $columns = $this->repository->findAllByRowForDomain($rowId, $domainId);

        foreach ($columns as $index => $column) {
            if ($column->getColumnIndex() !== $index) {
                $this->repository->update($column->getColumnId(), ['column_index' => $index]);
            }
        }
    }

    private function encodeJsonFields(array $data): array
    {
        foreach (['background_config', 'border_config', 'title_config', 'content_config', 'content_items'] as $field) {
            if (array_key_exists($field, $data) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field], JSON_UNESCAPED_UNICODE);
            }
        }

        return $data;
    }

}
