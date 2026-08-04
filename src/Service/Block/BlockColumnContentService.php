<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Repository\Block\BlockColumnContentRepository;
use Mublo\Repository\Block\BlockColumnRepository;

/**
 * 칸 저장 오케스트레이터 — 콘텐츠 스택의 쓰기 경로 선택과 상태 전이 (계획 6.2.0, 6.3).
 *
 * 경로 선택은 제출 payload 만 보지 않고 **DB 의 현재 상태를 함께** 본다:
 * - payload 와 DB 모두 stack 이 없는 행 → 기존 replaceByRow() 유지
 * - 어느 쪽이든 stack 이 있는 행 → stable-ID 동기화 (기존 칸 UPDATE /
 *   신규 INSERT / 누락 DELETE) — 일반 설정 저장이 column_id·content_id 를
 *   재발급하지 않는다
 *
 * DB 가 stack 인데 제출값만 보고 single 로 판단해 replaceByRow 로 보내면
 * FK cascade 로 스택 전체가 증발한다 — 이 클래스가 그 사고를 구조적으로 막는다.
 *
 * 레거시 미러(계획 5.4): stack 칸의 기존 콘텐츠 필드에는 정렬상 첫 번째
 * 활성 콘텐츠를 복사한다(재정규화 없음). 전 자식 비활성이면 비운다.
 *
 * 트랜잭션은 호출자(BlockRowService)가 소유한다.
 */
final class BlockColumnContentService
{
    /** stack 칸이 legacy scalar 로 미러하는 콘텐츠 필드 */
    private const MIRROR_FIELDS = [
        'title_config', 'content_type', 'content_kind', 'content_skin', 'content_config', 'content_items',
    ];

    public function __construct(
        private BlockColumnRepository $columns,
        private BlockColumnContentRepository $contents
    ) {
    }

    /**
     * revision·복사·Kit 교체 백업용 portable 배열을 만든다.
     * 스택 하위 콘텐츠는 비활성 항목까지 한 번의 배치 조회로 포함한다.
     *
     * @param BlockColumn[] $columns
     * @return array<int, array<string, mixed>>
     */
    public function columnsToPortableArrays(array $columns): array
    {
        $stackColumnIdsByDomain = [];
        foreach ($columns as $column) {
            if ($column->isStack()) {
                $stackColumnIdsByDomain[$column->getDomainId()][] = $column->getColumnId();
            }
        }

        $contentsByColumn = [];
        foreach ($stackColumnIdsByDomain as $domainId => $columnIds) {
            $contentsByColumn += $this->contents->findByColumnsForDomain($columnIds, $domainId, true);
        }

        $portable = [];
        foreach ($columns as $column) {
            $data = $column->toArray();
            if ($column->isStack()) {
                $data['contents'] = array_map(
                    static fn ($content): array => $content->toArray(),
                    $contentsByColumn[$column->getColumnId()] ?? []
                );
            }
            $portable[] = $data;
        }

        return $portable;
    }

    /**
     * 행의 칸 일괄 저장 — replaceByRow() 호출 지점의 대체 진입점.
     *
     * @param array<int, array<string, mixed>> $columnsData BlockColumnPayloadNormalizer 통과본
     */
    public function saveColumns(int $rowId, int $domainId, array $columnsData): void
    {
        $dbColumns = $this->columns->findAllByRowForDomain($rowId, $domainId);

        $dbHasStack = false;
        foreach ($dbColumns as $dbColumn) {
            if ($dbColumn->isStack()) {
                $dbHasStack = true;
                break;
            }
        }

        $payloadHasStack = false;
        foreach ($columnsData as $columnData) {
            if (($columnData['content_mode'] ?? null) === 'stack') {
                $payloadHasStack = true;
                break;
            }
        }

        if (!$dbHasStack && !$payloadHasStack) {
            // single 전용 행 — 기존 저장 경로 유지 (계획 원칙 3)
            $this->columns->replaceByRow($rowId, $domainId, $this->stripSyncKeys($columnsData));
            return;
        }

        $this->syncRow($rowId, $domainId, $columnsData, $dbColumns, $dbHasStack);
    }

    /**
     * stable-ID 행 동기화 (계획 6.2.0).
     *
     * @param BlockColumn[] $dbColumns
     */
    private function syncRow(int $rowId, int $domainId, array $columnsData, array $dbColumns, bool $dbHasStack): void
    {
        $dbById = [];
        foreach ($dbColumns as $dbColumn) {
            $dbById[$dbColumn->getColumnId()] = $dbColumn;
        }

        // 구형 payload 방어 (계획 6.3): DB 에 stack 칸이 있는데 제출 payload 가
        // 기존 칸 식별자를 하나도 싣지 않았다면, 위치(순서) 추측으로 갱신하지
        // 않고 저장을 거부한다 — 재로딩 후 다시 저장해야 한다.
        if ($dbHasStack && $dbById !== []) {
            $hasAnyColumnId = false;
            foreach ($columnsData as $columnData) {
                if ((int) ($columnData['column_id'] ?? 0) > 0) {
                    $hasAnyColumnId = true;
                    break;
                }
            }
            if (!$hasAnyColumnId) {
                throw new \RuntimeException(
                    '이 행에는 여러 콘텐츠를 배치한 칸이 있어 이전 형식의 저장을 적용할 수 없습니다. 화면을 새로고침한 뒤 다시 저장해 주세요.'
                );
            }
        }

        $keptColumnIds = [];

        foreach (array_values($columnsData) as $index => $columnData) {
            $columnId = (int) ($columnData['column_id'] ?? 0);
            unset($columnData['column_id']);

            $contentsPayload = $columnData['contents'] ?? null;
            unset($columnData['contents']);

            $mode = $columnData['content_mode'] ?? null;
            $dbColumn = $columnId > 0 ? ($dbById[$columnId] ?? null) : null;
            // 레거시 호환 경로의 병합본은 DB 값 + 정규화기 통과 필드라 타입 변조
            // 가드 대상이 아니다 (content_id 없는 미등록 타입은 정규화기가 거부)
            $contentsFromPayload = is_array($contentsPayload);

            if ($columnId > 0 && $dbColumn === null) {
                throw new \RuntimeException(
                    "column_id {$columnId} 는 이 행(도메인 {$domainId}) 소유가 아닙니다. 화면을 새로고침한 뒤 다시 저장해 주세요."
                );
            }

            // 순서는 배열 순서가 진실
            $columnData['column_index'] = $index;
            $columnData['sort_order'] = $index;

            if ($mode === 'stack') {
                // 미러: 정규화 완료된 첫 활성 콘텐츠 복사 (재정규화 없음, 계획 5.4)
                $columnData = $this->applyStackMirror($columnData, $contentsPayload ?? []);
            } elseif ($mode === null && $dbColumn !== null && $dbColumn->isStack()) {
                // 검증된 호환 경로 (계획 5.4): 레거시 단일 payload 로 스택 칸 수정 —
                // 정렬상 첫 번째 활성 콘텐츠에 반영하고 나머지는 보존한다
                [$columnData, $contentsPayload] = $this->applyLegacyEditToStack($columnId, $domainId, $columnData);
                $mode = 'stack';
                $columnData['content_mode'] = 'stack';
            } elseif ($mode === 'single' && $dbColumn !== null && $dbColumn->isStack()) {
                // 명시적 축소 (계획 5.3): 유지할 콘텐츠는 payload 의 레거시 필드로
                // 이미 들어와 있다 — 하위 콘텐츠는 아래에서 삭제
                $contentsPayload = null;
            }

            if ($dbColumn !== null) {
                $this->columns->updateColumnForDomain($columnId, $rowId, $domainId, $columnData);
            } else {
                $columnId = $this->columns->insertColumn($rowId, $domainId, $columnData);
            }

            $keptColumnIds[] = $columnId;

            if ($mode === 'stack' && is_array($contentsPayload)) {
                if ($contentsFromPayload) {
                    $this->assertUnregisteredTypesPreserved($columnId, $domainId, $contentsPayload);
                }
                $this->contents->syncForColumn($columnId, $domainId, $contentsPayload);
            } elseif ($mode !== 'stack') {
                // single(전환 포함) — 하위 콘텐츠가 남아 있으면 정리 (계획 5.3)
                if ($dbColumn !== null && $dbColumn->isStack()) {
                    $this->contents->deleteByColumnForDomain($columnId, $domainId);
                }
            }
        }

        // 누락 DELETE — 자식 콘텐츠는 cascade
        $this->columns->deleteByRowExcept($rowId, $domainId, $keptColumnIds);
    }

    /**
     * stack 칸의 레거시 미러 생성 (계획 5.4).
     *
     * @param array<int, array<string, mixed>> $contentsPayload 정규화 완료된 contents
     */
    private function applyStackMirror(array $columnData, array $contentsPayload): array
    {
        $firstActive = null;
        foreach ($contentsPayload as $content) {
            if ((int) ($content['is_active'] ?? 1) === 1) {
                $firstActive = $content;
                break;
            }
        }

        if ($firstActive === null) {
            // 전 자식 비활성 — 레거시 필드를 비워 구버전이 비활성 콘텐츠를
            // 출력하지 않게 한다 (계획 5.4)
            foreach (self::MIRROR_FIELDS as $field) {
                $columnData[$field] = null;
            }
            $columnData['content_kind'] = 'CORE';

            return $columnData;
        }

        foreach (self::MIRROR_FIELDS as $field) {
            $columnData[$field] = $firstActive[$field] ?? null;
        }
        $columnData['content_kind'] = $firstActive['content_kind'] ?? 'CORE';

        return $columnData;
    }

    /**
     * 검증된 호환 경로 (계획 5.4): content_mode 없는 레거시 payload 가
     * 기존 stack 칸(column_id 매칭)에 들어온 경우 — 제출된 레거시 콘텐츠
     * 필드를 정렬상 첫 번째 활성 콘텐츠에 반영하고 나머지는 보존한다.
     *
     * @return array{array<string, mixed>, array<int, array<string, mixed>>}
     *         [미러 반영된 칸 데이터, 보존·갱신된 contents payload]
     */
    private function applyLegacyEditToStack(int $columnId, int $domainId, array $columnData): array
    {
        $existing = $this->contents->findByColumnForDomain($columnId, $domainId, true);

        $contentsPayload = [];
        $firstActiveIndex = null;
        foreach ($existing as $index => $content) {
            $contentsPayload[$index] = $content->toArray();
            if ($firstActiveIndex === null && $content->isActive()) {
                $firstActiveIndex = $index;
            }
        }

        if ($firstActiveIndex === null) {
            // 갱신 대상(첫 활성)이 없다 — 레거시 갱신을 거부한다 (계획 5.4)
            throw new \RuntimeException(
                '이 칸의 콘텐츠가 모두 비활성 상태라 이전 형식의 저장을 적용할 수 없습니다. 블록 편집기에서 수정해 주세요.'
            );
        }

        foreach (self::MIRROR_FIELDS as $field) {
            if (array_key_exists($field, $columnData)) {
                $contentsPayload[$firstActiveIndex][$field] = $columnData[$field];
            }
        }

        return [$columnData, array_values($contentsPayload)];
    }

    /**
     * 미등록 타입 원형 보존의 범위 방어 (계획 6.4 후속).
     *
     * 정규화기는 content_id 를 가진 기존 콘텐츠의 미등록 타입을 보존 허용하는데,
     * 그 허용이 "기존 content_id 로 임의의 미등록 타입을 심는" 통로가 되면 안 된다.
     * 미등록 타입 제출은 DB 의 기존 content_type 과 정확히 같을 때만 통과시킨다 —
     * 타입 변경은 등록된 타입으로만 가능하다. (소유권 자체는 syncForColumn 이 거부)
     */
    private function assertUnregisteredTypesPreserved(int $columnId, int $domainId, array $contentsPayload): void
    {
        $unregistered = [];
        foreach ($contentsPayload as $content) {
            if (!is_array($content)) {
                continue;
            }
            $type = $content['content_type'] ?? null;
            $contentId = (int) ($content['content_id'] ?? 0);
            if (is_string($type) && $type !== '' && $contentId > 0
                && BlockRegistry::getContentType($type) === null
            ) {
                $unregistered[$contentId] = $type;
            }
        }

        if ($unregistered === []) {
            return;
        }

        $existingById = [];
        foreach ($this->contents->findByColumnForDomain($columnId, $domainId, true) as $content) {
            $existingById[$content->getContentId()] = $content;
        }

        foreach ($unregistered as $contentId => $type) {
            $existing = $existingById[$contentId] ?? null;
            if ($existing !== null && $existing->getContentTypeString() !== $type) {
                throw new \RuntimeException(
                    "설치되지 않은 블록 타입({$type})으로는 변경할 수 없습니다. 기존 콘텐츠는 원래 타입 그대로만 보존됩니다."
                );
            }
        }
    }

    /**
     * replaceByRow 경로 방어 — stable 동기화 전용 키가 INSERT 에 섞이지 않게 한다.
     */
    private function stripSyncKeys(array $columnsData): array
    {
        foreach ($columnsData as $index => $columnData) {
            unset($columnData['column_id'], $columnData['contents']);
            $columnsData[$index] = $columnData;
        }

        return $columnsData;
    }
}
