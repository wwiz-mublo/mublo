<?php
declare(strict_types=1);
namespace Mublo\Repository\Block;

use Mublo\Entity\Block\BlockColumn;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * BlockColumn Repository
 *
 * 블록 칸(Column) 데이터베이스 접근 담당
 *
 * 책임:
 * - block_columns 테이블 CRUD
 * - BlockColumn Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class BlockColumnRepository extends BaseRepository
{
    protected string $table = 'block_columns';
    protected string $entityClass = BlockColumn::class;
    protected string $primaryKey = 'column_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 행별 칸 목록 조회
     *
     * @param int $rowId 행 ID
     * @return BlockColumn[]
     */
    public function findByRow(int $rowId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('row_id', '=', $rowId)
            ->where('is_active', '=', 1)
            ->orderBy('column_index', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /** @return BlockColumn[] 현재 도메인 행의 활성 칸만 조회 */
    public function findByRowForDomain(int $rowId, int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('row_id', '=', $rowId)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->orderBy('column_index', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 여러 행의 활성 칸을 IN 절 한 번으로 조회 (콜드 캐시 렌더의 행당 1쿼리 N+1 방지)
     *
     * @param int[] $rowIds
     * @return array<int, BlockColumn[]> row_id => 칸 목록 (column_index 순)
     */
    public function findByRowsForDomain(array $rowIds, int $domainId): array
    {
        if (empty($rowIds)) {
            return [];
        }

        $rows = $this->getDb()->table($this->table)
            ->whereIn('row_id', array_map('intval', $rowIds))
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->orderBy('row_id', 'ASC')
            ->orderBy('column_index', 'ASC')
            ->get();

        $grouped = [];
        foreach ($this->toEntities($rows) as $column) {
            $grouped[$column->getRowId()][] = $column;
        }

        return $grouped;
    }

    /**
     * 행별 모든 칸 조회 (관리자용, is_active 무관)
     *
     * @param int $rowId 행 ID
     * @return BlockColumn[]
     */
    public function findAllByRow(int $rowId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('row_id', '=', $rowId)
            ->orderBy('column_index', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /** @return BlockColumn[] 현재 도메인 행의 모든 칸 조회 */
    public function findAllByRowForDomain(int $rowId, int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('row_id', '=', $rowId)
            ->where('domain_id', '=', $domainId)
            ->orderBy('column_index', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 도메인별 칸 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $limit 조회 개수
     * @param int $offset 시작 위치
     * @return BlockColumn[]
     */
    public function findByDomain(int $domainId, int $limit = 100, int $offset = 0): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('row_id', 'ASC')
            ->orderBy('column_index', 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 행+인덱스로 칸 조회
     */
    public function findByRowAndIndex(int $rowId, int $columnIndex): ?BlockColumn
    {
        return $this->findOneBy([
            'row_id' => $rowId,
            'column_index' => $columnIndex,
        ]);
    }

    /**
     * 행별 칸 수 조회
     */
    public function countByRow(int $rowId): int
    {
        return $this->countBy(['row_id' => $rowId]);
    }

    public function countByRowForDomain(int $rowId, int $domainId): int
    {
        return $this->countBy(['row_id' => $rowId, 'domain_id' => $domainId]);
    }

    /**
     * 도메인별 칸 수 조회
     */
    public function countByDomain(int $domainId): int
    {
        return $this->countBy(['domain_id' => $domainId]);
    }

    /**
     * 콘텐츠 타입별 칸 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param string $contentType 콘텐츠 타입
     * @return BlockColumn[]
     */
    public function findByContentType(int $domainId, string $contentType): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('content_type', '=', $contentType)
            ->where('is_active', '=', 1)
            ->get();

        return $this->mergeStackMatches(
            $this->toEntities($rows),
            $domainId,
            fn(BlockColumnContentRepository $contents) => $contents->findColumnIdsByContentType($domainId, $contentType)
        );
    }

    /**
     * 콘텐츠 종류별 칸 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param string $contentKind 콘텐츠 종류 (CORE, PLUGIN, PACKAGE)
     * @return BlockColumn[]
     */
    public function findByContentKind(int $domainId, string $contentKind): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('content_kind', '=', $contentKind)
            ->where('is_active', '=', 1)
            ->get();

        return $this->mergeStackMatches(
            $this->toEntities($rows),
            $domainId,
            fn(BlockColumnContentRepository $contents) => $contents->findColumnIdsByContentKind($domainId, $contentKind)
        );
    }

    /**
     * 역참조 합집합 (계획 6.2) — single 레거시 필드 검색 결과에 stack 하위
     * 콘텐츠 검색 결과를 합쳐 column_id 로 중복 제거한다.
     *
     * 활성 조건(계획 6.2.2 고정 문장): stack 칸은 부모 칸 is_active=1 과
     * 자식 콘텐츠 is_active=1 을 모두 요구한다 — 자식 조건은
     * findColumnIdsBy...() 가, 부모 조건은 여기서 적용한다.
     *
     * 반환 객체 계약(계획 6.2 계약 축소): 스택 칸이 검색에 걸린 경우 반환
     * BlockColumn 에서 신뢰할 수 있는 것은 row_id·column_id 뿐이다. 콘텐츠
     * 필드는 레거시 미러(첫 활성 콘텐츠) 참고값이다.
     *
     * @param BlockColumn[] $legacyMatches
     * @param callable(BlockColumnContentRepository): int[] $stackLookup
     * @return BlockColumn[]
     */
    private function mergeStackMatches(array $legacyMatches, int $domainId, callable $stackLookup): array
    {
        try {
            $stackColumnIds = $stackLookup(new BlockColumnContentRepository($this->getDb()));
        } catch (\Throwable) {
            // 콘텐츠 테이블 미설치(마이그레이션 이전) — 레거시 결과만 반환
            return $legacyMatches;
        }

        if ($stackColumnIds === []) {
            return $legacyMatches;
        }

        $byId = [];
        foreach ($legacyMatches as $column) {
            $byId[$column->getColumnId()] = $column;
        }

        $missingIds = array_values(array_diff($stackColumnIds, array_keys($byId)));
        if ($missingIds !== []) {
            $rows = $this->getDb()->table($this->table)
                ->whereIn('column_id', $missingIds)
                ->where('domain_id', '=', $domainId)
                ->where('is_active', '=', 1) // 부모 칸 활성 조건
                ->get();

            foreach ($this->toEntities($rows) as $column) {
                $byId[$column->getColumnId()] = $column;
            }
        }

        return array_values($byId);
    }

    /**
     * 행 삭제 시 관련 칸 삭제
     */
    public function deleteByRow(int $rowId): int
    {
        return $this->getDb()->table($this->table)
            ->where('row_id', '=', $rowId)
            ->delete();
    }

    /**
     * 칸 단건 UPDATE — stable-ID 동기화용 (계획 6.2.0).
     * row·domain 스코프를 WHERE 에 강제해 소유권을 재검증한다.
     *
     * @param array $columnData JSON 배열 필드는 여기서 인코딩한다
     */
    public function updateColumnForDomain(int $columnId, int $rowId, int $domainId, array $columnData): bool
    {
        $columnData['updated_at'] = date('Y-m-d H:i:s');
        unset($columnData['column_id'], $columnData['row_id'], $columnData['domain_id'], $columnData['created_at']);

        $affected = $this->getDb()->table($this->table)
            ->where('column_id', '=', $columnId)
            ->where('row_id', '=', $rowId)
            ->where('domain_id', '=', $domainId)
            ->update($this->encodeJsonFields($columnData));

        return $affected > 0;
    }

    /**
     * 칸 단건 INSERT — stable-ID 동기화용.
     *
     * @return int 새 column_id
     */
    public function insertColumn(int $rowId, int $domainId, array $columnData): int
    {
        unset($columnData['column_id']);
        $columnData['row_id'] = $rowId;
        $columnData['domain_id'] = $domainId;
        $columnData['created_at'] = date('Y-m-d H:i:s');
        $columnData['updated_at'] = date('Y-m-d H:i:s');

        return (int) $this->getDb()->table($this->table)->insert($this->encodeJsonFields($columnData));
    }

    /**
     * payload 에서 빠진 칸만 삭제 — stable-ID 동기화의 "누락 DELETE".
     * 자식 콘텐츠는 복합 FK cascade 로 함께 삭제된다.
     *
     * @param int[] $keepColumnIds
     */
    public function deleteByRowExcept(int $rowId, int $domainId, array $keepColumnIds): void
    {
        $query = $this->getDb()->table($this->table)
            ->where('row_id', '=', $rowId)
            ->where('domain_id', '=', $domainId);

        $keepColumnIds = array_values(array_filter(array_map('intval', $keepColumnIds)));
        if ($keepColumnIds !== []) {
            $query->whereNotIn('column_id', $keepColumnIds);
        }

        $query->delete();
    }

    /** JSON 배열 필드 인코딩 (저장 형식 변환) */
    private function encodeJsonFields(array $columnData): array
    {
        $jsonFields = ['background_config', 'border_config', 'title_config', 'content_config', 'content_items'];
        foreach ($jsonFields as $field) {
            if (isset($columnData[$field]) && is_array($columnData[$field])) {
                $columnData[$field] = json_encode($columnData[$field], JSON_UNESCAPED_UNICODE);
            }
        }

        return $columnData;
    }

    /**
     * 행의 칸 일괄 저장 (기존 삭제 후 새로 생성)
     *
     * single 전용 행만 사용한다 — stack 칸이 포함된 행은 column_id 가
     * 재발급되면 안 되므로 BlockColumnContentService 의 stable 동기화
     * 경로를 탄다 (계획 6.2.0).
     *
     * @param int $rowId 행 ID
     * @param int $domainId 도메인 ID
     * @param array $columnsData BlockColumnPayloadNormalizer를 통과한 칸 데이터 배열.
     *        이 메서드는 저장 형식 변환만 하며 HTTP payload 검증을 담당하지 않는다.
     * @return bool
     */
    public function replaceByRow(int $rowId, int $domainId, array $columnsData): bool
    {
        // 기존 칸 삭제
        $this->deleteByRow($rowId);

        // 새 칸 저장
        foreach (array_values($columnsData) as $index => $columnData) {
            $columnData['row_id'] = $rowId;
            $columnData['domain_id'] = $domainId;

            // 칸의 순서는 column_index 하나가 결정한다(모든 조회가 이것으로 정렬한다).
            // sort_order 는 읽는 곳이 없는 사본이지만, 생성 경로마다 값이 달라지지 않도록
            // 여기서 함께 맞춘다. 호출자가 넘긴 값은 무시한다.
            $columnData['column_index'] = $index;
            $columnData['sort_order'] = $index;

            $columnData['created_at'] = date('Y-m-d H:i:s');
            $columnData['updated_at'] = date('Y-m-d H:i:s');

            // JSON 필드 처리
            $jsonFields = ['background_config', 'border_config', 'title_config', 'content_config', 'content_items'];
            foreach ($jsonFields as $field) {
                if (isset($columnData[$field]) && is_array($columnData[$field])) {
                    $columnData[$field] = json_encode($columnData[$field], JSON_UNESCAPED_UNICODE);
                }
            }

            $this->getDb()->table($this->table)->insert($columnData);
        }

        return true;
    }

    /**
     * JSON 필드 업데이트
     *
     * @param int $columnId 칸 ID
     * @param string $field 필드명
     * @param array|null $value 값
     * @return bool
     */
    public function updateJsonField(int $columnId, string $field, ?array $value): bool
    {
        $allowedFields = ['background_config', 'border_config', 'title_config', 'content_config', 'content_items'];

        if (!in_array($field, $allowedFields, true)) {
            return false;
        }

        $jsonValue = $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);

        $affected = $this->getDb()->table($this->table)
            ->where('column_id', '=', $columnId)
            ->update([$field => $jsonValue]);

        return $affected >= 0;
    }

    /**
     * 콘텐츠 설정 업데이트
     *
     * @param int $columnId 칸 ID
     * @param string|null $type 콘텐츠 타입
     * @param string $kind 콘텐츠 종류
     * @param string|null $skin 스킨
     * @param array|null $config 추가 설정 (pc_count, mo_count, aos, pc_style 등 포함)
     * @param array|null $items 선택된 아이템 (게시판 ID 목록 또는 이미지 배열)
     * @return bool
     */
    public function updateContent(
        int $columnId,
        ?string $type,
        string $kind = 'CORE',
        ?string $skin = null,
        ?array $config = null,
        ?array $items = null
    ): bool {
        $data = [
            'content_type' => $type,
            'content_kind' => $kind,
            'content_skin' => $skin,
            'content_config' => $config ? json_encode($config, JSON_UNESCAPED_UNICODE) : null,
            'content_items' => $items ? json_encode($items, JSON_UNESCAPED_UNICODE) : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $affected = $this->getDb()->table($this->table)
            ->where('column_id', '=', $columnId)
            ->update($data);

        return $affected >= 0;
    }

    /**
     * 제목 설정 업데이트
     *
     * @param int $columnId 칸 ID
     * @param array|null $titleConfig 제목 설정
     * @return bool
     */
    public function updateTitleConfig(int $columnId, ?array $titleConfig): bool
    {
        return $this->updateJsonField($columnId, 'title_config', $titleConfig);
    }

    /**
     * 스타일 설정 업데이트 (배경 + 테두리)
     *
     * @param int $columnId 칸 ID
     * @param array|null $backgroundConfig 배경 설정
     * @param array|null $borderConfig 테두리 설정
     * @return bool
     */
    public function updateStyleConfig(int $columnId, ?array $backgroundConfig, ?array $borderConfig): bool
    {
        $data = [
            'background_config' => $backgroundConfig ? json_encode($backgroundConfig, JSON_UNESCAPED_UNICODE) : null,
            'border_config' => $borderConfig ? json_encode($borderConfig, JSON_UNESCAPED_UNICODE) : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $affected = $this->getDb()->table($this->table)
            ->where('column_id', '=', $columnId)
            ->update($data);

        return $affected >= 0;
    }

    /**
     * 특정 콘텐츠 아이템을 사용하는 칸 조회
     * (예: 특정 게시판 ID가 content_items에 포함된 칸)
     *
     * @param int $domainId 도메인 ID
     * @param string $contentType 콘텐츠 타입
     * @param mixed $itemId 아이템 ID
     * @return BlockColumn[]
     */
    public function findByContentItem(int $domainId, string $contentType, $itemId): array
    {
        // content_items 저장형태가 두 가지라 모두 매칭한다:
        //  - 스칼라 배열   : ["free"], ["22"]            (board 슬러그, 레거시 ID)
        //  - 객체 배열의 id: [{"id":"22", ...}]          (수동 진열 product, id는 문자열)
        $idStr = (string) $itemId;

        $conds = [];
        $params = [];

        // 스칼라 문자열 배열
        $conds[] = 'JSON_CONTAINS(content_items, ?)';
        $params[] = json_encode($idStr);

        // 객체 배열의 .id (문자열로 저장된 경우)
        $conds[] = "JSON_SEARCH(content_items, 'one', ?, NULL, '$[*].id') IS NOT NULL";
        $params[] = $idStr;

        // 레거시 스칼라 숫자 배열 [22] — 숫자일 때만(잘못된 JSON 인자 방지)
        if (is_numeric($idStr)) {
            $conds[] = 'JSON_CONTAINS(content_items, ?)';
            $params[] = (string) (int) $idStr;
        }

        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('content_type', '=', $contentType)
            // 활성 조건 통일 (계획 6.2.2) — Type/Kind 검색과 달리 빠져 있었다.
            // 추가로 달라지는 것은 비활성 칸의 불필요한 캐시 무효화가 줄어드는 정도.
            ->where('is_active', '=', 1)
            ->whereRaw('(' . implode(' OR ', $conds) . ')', $params)
            ->get();

        return $this->mergeStackMatches(
            $this->toEntities($rows),
            $domainId,
            fn(BlockColumnContentRepository $contents) => $contents->findColumnIdsByContentItem($domainId, $contentType, $itemId)
        );
    }
}
