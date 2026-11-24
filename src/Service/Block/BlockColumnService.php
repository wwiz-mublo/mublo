<?php
declare(strict_types=1);
namespace Mublo\Service\Block;

use Mublo\Repository\Block\BlockColumnContentRepository;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Entity\Block\BlockColumn;

/**
 * BlockColumn Service
 *
 * 블록 칸(Column) 조회 담당
 *
 * 칸 쓰기는 BlockRowService(행 단위 저장·사이트 변경 revision 기록)가 맡는다. 이 서비스에 있던 칸 단위 쓰기 메서드는
 * 호출처가 없고 revision을 남기지 않아 지웠다(계획서 008 P1 누락 검사).
 *
 * 책임:
 * - 칸·스택 콘텐츠 조회와 편집 화면용 변환
 *
 * 금지:
 * - Request/Response 직접 처리 (Controller 담당)
 * - DB 직접 접근 (Repository 담당)
 */
class BlockColumnService
{
    private BlockColumnRepository $repository;
    private BlockRowRepository $rowRepository;
    private ?BlockColumnContentRepository $contentRepository;

    public function __construct(
        BlockColumnRepository $repository,
        BlockRowRepository $rowRepository,
        ?BlockColumnContentRepository $contentRepository = null
    ) {
        $this->repository = $repository;
        $this->rowRepository = $rowRepository;
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

}
