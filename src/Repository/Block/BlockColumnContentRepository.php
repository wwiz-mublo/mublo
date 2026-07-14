<?php
namespace Mublo\Repository\Block;

use Mublo\Entity\Block\BlockColumnContent;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * BlockColumnContent Repository — 스택 칸 하위 콘텐츠 데이터 접근.
 *
 * 책임:
 * - block_column_contents 테이블 CRUD
 * - 안정 ID 동기화(syncForColumn): 기존 content_id UPDATE / 신규 INSERT /
 *   누락 DELETE — 정렬·설정 변경이 content_id 를 재발급하지 않는다 (계획 6.2.0)
 * - 역참조: 캐시 무효화용 칸 식별자 검색 (계획 6.2.1, int[] 반환)
 *
 * 조회 결정성: sort_order 에 UNIQUE 가 없으므로 항상
 * ORDER BY sort_order, content_id 로 정렬한다 (계획 4.2).
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class BlockColumnContentRepository extends BaseRepository
{
    protected string $table = 'block_column_contents';
    protected string $entityClass = BlockColumnContent::class;
    protected string $primaryKey = 'content_id';

    /** DB 에 쓸 수 있는 콘텐츠 필드 (식별자·시각 제외) */
    private const WRITABLE_FIELDS = [
        'title_config', 'content_type', 'content_kind', 'content_skin',
        'content_config', 'content_items', 'is_active',
    ];

    private const JSON_FIELDS = ['title_config', 'content_config', 'content_items'];

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 칸의 하위 콘텐츠 조회.
     *
     * 활성/비활성 계약(계획 6.2.2): 공개 렌더링은 활성만, 관리자 편집·행
     * 복사·Revision·블록킷·저장 동기화는 비활성 포함으로 읽는다.
     *
     * @return BlockColumnContent[]
     */
    public function findByColumnForDomain(int $columnId, int $domainId, bool $includeInactive = false): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('column_id', '=', $columnId)
            ->where('domain_id', '=', $domainId);

        if (!$includeInactive) {
            $query->where('is_active', '=', 1);
        }

        $rows = $query
            ->orderBy('sort_order', 'ASC')
            ->orderBy('content_id', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 여러 칸의 하위 콘텐츠 배치 조회 — 칸별 개별 조회(N+1)를 대체한다 (계획 7.4).
     *
     * @param int[] $columnIds
     * @return array<int, BlockColumnContent[]> column_id => 콘텐츠 목록 (sort_order, content_id 순)
     */
    public function findByColumnsForDomain(array $columnIds, int $domainId, bool $includeInactive = false): array
    {
        $columnIds = array_values(array_unique(array_map('intval', $columnIds)));
        if ($columnIds === []) {
            return [];
        }

        $query = $this->getDb()->table($this->table)
            ->whereIn('column_id', $columnIds)
            ->where('domain_id', '=', $domainId);

        if (!$includeInactive) {
            $query->where('is_active', '=', 1);
        }

        $rows = $query
            ->orderBy('sort_order', 'ASC')
            ->orderBy('content_id', 'ASC')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $entity = BlockColumnContent::fromArray((array) $row);
            $grouped[$entity->getColumnId()][] = $entity;
        }

        return $grouped;
    }

    /**
     * 안정 ID 동기화 (계획 6.2.0의 콘텐츠 동기화).
     *
     * $contentsData 는 normalizer 를 통과한 콘텐츠 배열이다. 각 항목의
     * content_id 가 이 칸·도메인 소유의 기존 레코드면 UPDATE, 0/누락이면
     * INSERT, payload 에 없는 기존 레코드는 DELETE 한다. 배열 순서가
     * sort_order 의 진실이다 — 호출자가 넘긴 sort_order 는 무시한다.
     *
     * 소유권 검증은 호출 전에 Service/Normalizer 가 수행한다는 전제이지만,
     * 방어적으로 이 칸 소유가 아닌 content_id 는 신규 INSERT 로 처리하지
     * 않고 예외를 던진다.
     *
     * @param array<int, array<string, mixed>> $contentsData
     * @return int[] 저장 후 이 칸의 content_id 목록 (배열 순서대로)
     */
    public function syncForColumn(int $columnId, int $domainId, array $contentsData): array
    {
        $existing = [];
        foreach ($this->findByColumnForDomain($columnId, $domainId, true) as $content) {
            $existing[$content->getContentId()] = $content;
        }

        $now = date('Y-m-d H:i:s');
        $keptIds = [];
        $resultIds = [];

        foreach (array_values($contentsData) as $index => $contentData) {
            $contentId = (int) ($contentData['content_id'] ?? 0);

            $record = ['sort_order' => $index, 'updated_at' => $now];
            foreach (self::WRITABLE_FIELDS as $field) {
                if (array_key_exists($field, $contentData)) {
                    $record[$field] = $contentData[$field];
                }
            }
            foreach (self::JSON_FIELDS as $field) {
                if (isset($record[$field]) && is_array($record[$field])) {
                    $record[$field] = json_encode($record[$field], JSON_UNESCAPED_UNICODE);
                }
            }

            if ($contentId > 0) {
                if (!isset($existing[$contentId])) {
                    throw new \InvalidArgumentException(
                        "content_id {$contentId} 는 칸 {$columnId} (도메인 {$domainId}) 소유가 아니다."
                    );
                }

                $this->getDb()->table($this->table)
                    ->where('content_id', '=', $contentId)
                    ->where('column_id', '=', $columnId)
                    ->where('domain_id', '=', $domainId)
                    ->update($record);

                $keptIds[$contentId] = true;
                $resultIds[] = $contentId;
                continue;
            }

            $record['column_id'] = $columnId;
            $record['domain_id'] = $domainId;
            $record['created_at'] = $now;

            $newId = (int) $this->getDb()->table($this->table)->insert($record);
            $keptIds[$newId] = true;
            $resultIds[] = $newId;
        }

        // payload 에서 빠진 기존 콘텐츠만 삭제
        foreach ($existing as $contentId => $content) {
            if (!isset($keptIds[$contentId])) {
                $this->getDb()->table($this->table)
                    ->where('content_id', '=', $contentId)
                    ->where('column_id', '=', $columnId)
                    ->where('domain_id', '=', $domainId)
                    ->delete();
            }
        }

        return $resultIds;
    }

    public function deleteByColumnForDomain(int $columnId, int $domainId): bool
    {
        $this->getDb()->table($this->table)
            ->where('column_id', '=', $columnId)
            ->where('domain_id', '=', $domainId)
            ->delete();

        return true;
    }

    /**
     * 역참조 — 이 타입의 활성 콘텐츠를 가진 칸 ID 목록 (계획 6.2.1).
     *
     * 활성 조건(계획 6.2.2 고정 문장): 자식 콘텐츠의 is_active=1 만 여기서
     * 적용한다. 부모 칸의 is_active=1 은 합집합을 만드는
     * BlockColumnRepository 쪽에서 적용된다.
     *
     * @return int[] column_id 목록
     */
    public function findColumnIdsByContentType(int $domainId, string $contentType): array
    {
        $rows = $this->getDb()->table($this->table)
            ->select(['column_id'])
            ->where('domain_id', '=', $domainId)
            ->where('content_type', '=', $contentType)
            ->where('is_active', '=', 1)
            ->get();

        return $this->extractColumnIds($rows);
    }

    /** @return int[] column_id 목록 */
    public function findColumnIdsByContentKind(int $domainId, string $contentKind): array
    {
        $rows = $this->getDb()->table($this->table)
            ->select(['column_id'])
            ->where('domain_id', '=', $domainId)
            ->where('content_kind', '=', $contentKind)
            ->where('is_active', '=', 1)
            ->get();

        return $this->extractColumnIds($rows);
    }

    /**
     * 역참조 — content_items 에 특정 항목 ID 를 포함한 활성 콘텐츠의 칸 ID 목록.
     *
     * content_items 는 JSON 이므로 타입으로 후보를 좁힌 뒤 PHP 에서 항목을
     * 검사한다 (BlockColumnRepository::findByContentItem 과 동일 접근).
     *
     * @param int|string $itemId
     * @return int[] column_id 목록
     */
    public function findColumnIdsByContentItem(int $domainId, string $contentType, $itemId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->select(['column_id', 'content_items'])
            ->where('domain_id', '=', $domainId)
            ->where('content_type', '=', $contentType)
            ->where('is_active', '=', 1)
            ->get();

        $columnIds = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $items = json_decode((string) ($row['content_items'] ?? ''), true);
            if (!is_array($items)) {
                continue;
            }
            if ($this->itemsContain($items, $itemId)) {
                $columnIds[(int) $row['column_id']] = true;
            }
        }

        return array_keys($columnIds);
    }

    /**
     * content_items 구조 안에서 항목 ID 포함 여부 검사.
     * 최상위 스칼라 목록(게시판 ID[])과 중첩 배열(항목 객체 목록)을 모두 지원한다.
     *
     * @param int|string $itemId
     */
    private function itemsContain(array $items, $itemId): bool
    {
        foreach ($items as $value) {
            if (is_scalar($value) && (string) $value === (string) $itemId) {
                return true;
            }
            if (is_array($value) && $this->itemsContain($value, $itemId)) {
                return true;
            }
        }

        return false;
    }

    /** @return int[] */
    private function extractColumnIds(array $rows): array
    {
        $columnIds = [];
        foreach ($rows as $row) {
            $columnIds[(int) ((array) $row)['column_id']] = true;
        }

        return array_keys($columnIds);
    }
}
