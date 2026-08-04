<?php
declare(strict_types=1);
namespace Mublo\Repository\Block;

use Mublo\Entity\Block\BlockKit;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * BlockKit Repository
 *
 * 보관된 블록 킷 접근 담당
 *
 * 책임:
 * - block_kits 테이블 CRUD
 * - BlockKit Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 *
 * ## kit_json 을 목록에서 읽지 않는다
 *
 * 블록 킷 하나가 2 MiB 까지 간다. 목록이 스무 개를 읽으면 40 MiB 가 PHP 메모리로 들어온다.
 * 그래서 목록 조회는 LIST_COLUMNS 만 SELECT 하고, 본문이 필요한 곳은 findWithJson()
 * 을 쓴다. BaseRepository 의 find()/all()/findBy() 는 `*` 를 읽으므로 여기서 쓰지 않는다.
 *
 * ## 도메인 격리
 *
 * 모든 조회는 domain_id 를 받는다. 블록 킷은 도메인 자산이고, 한 관리자가 다른 도메인의
 * 블록 킷을 목록에서 보거나 적용해서는 안 된다(설계 9.5).
 */
class BlockKitRepository extends BaseRepository
{
    protected string $table = 'block_kits';
    protected string $entityClass = BlockKit::class;
    protected string $primaryKey = 'kit_id';

    /**
     * 목록·상세 화면이 쓰는 컬럼. kit_json 이 빠져 있다.
     *
     * @var string[]
     */
    private const LIST_COLUMNS = [
        'kit_id',
        'domain_id',
        'kit_name',
        'kit_description',
        'kit_version',
        'kit_author',
        'kit_author_url',
        'target_kind',
        'target_position',
        'target_menu_code',
        'target_page_code',
        'export_mode',
        'contains_script',
        'row_count',
        'column_count',
        'screenshot_path',
        'source_type',
        'is_deleted',
        'created_at',
        'updated_at',
    ];

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 도메인의 블록 킷 목록. 최신순.
     *
     * kit_json 을 읽지 않으므로 반환된 엔티티의 getKitJson() 은 null 이다.
     *
     * @param array{keyword?: string, target_kind?: string, contains_script?: string} $filters
     * @return BlockKit[]
     */
    public function findAllByDomain(int $domainId, int $limit = 100, int $offset = 0, array $filters = []): array
    {
        $rows = $this->buildListQuery($domainId, $filters)
            ->select(self::LIST_COLUMNS)
            ->orderBy('kit_id', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * @param array{keyword?: string, target_kind?: string, contains_script?: string} $filters
     */
    public function countByDomain(int $domainId, array $filters = []): int
    {
        return $this->buildListQuery($domainId, $filters)->count();
    }

    /**
     * 목록·카운트가 공유하는 WHERE. 둘이 갈라지면 페이지 수가 실제 목록과 어긋난다.
     *
     * 필터 값 검증은 컨트롤러가 한다. 여기서는 빈 값이면 조건을 걸지 않을 뿐이다.
     *
     * @param array{keyword?: string, target_kind?: string, contains_script?: string} $filters
     */
    private function buildListQuery(int $domainId, array $filters = [])
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_deleted', '=', 0);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('kit_name', 'LIKE', $like)
                    ->orWhere('kit_description', 'LIKE', $like)
                    ->orWhere('kit_author', 'LIKE', $like);
            });
        }

        $targetKind = (string) ($filters['target_kind'] ?? '');
        if ($targetKind !== '') {
            $query->where('target_kind', '=', $targetKind);
        }

        // '1'/'0' 문자열로 받는다 — ''(전체)와 0(없음)을 구분해야 하기 때문이다.
        $containsScript = (string) ($filters['contains_script'] ?? '');
        if ($containsScript !== '') {
            $query->where('contains_script', '=', (int) $containsScript);
        }

        return $query;
    }

    /**
     * 블록 킷 하나 조회. 본문(kit_json)은 읽지 않는다.
     *
     * domain_id 를 함께 받는 이유는 URL 로 넘어온 kit_id 가 다른 도메인의 것일 수 있기
     * 때문이다. WHERE 에 domain_id 가 없으면 남의 블록 킷 상세가 열린다.
     */
    public function findByDomain(int $domainId, int $kitId): ?BlockKit
    {
        return $this->fetchOne($domainId, $kitId, self::LIST_COLUMNS);
    }

    /**
     * 블록 킷 하나를 본문까지 조회한다. 적용·미리보기·다운로드가 쓴다.
     */
    public function findWithJson(int $domainId, int $kitId): ?BlockKit
    {
        return $this->fetchOne($domainId, $kitId, [...self::LIST_COLUMNS, 'kit_json']);
    }

    /**
     * 소프트 삭제. 적용 이력이 FK 로 매달려 있으므로 행을 지우지 않는다.
     *
     * @return int 영향받은 행 수
     */
    public function softDelete(int $domainId, int $kitId): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('kit_id', '=', $kitId)
            ->update(['is_deleted' => 1]);
    }

    /**
     * 썸네일 경로를 기록한다.
     *
     * 파일 이름이 kit_id 로 정해지므로 INSERT 이후에야 구울 수 있다. 그래서 두 단계다.
     * 썸네일 굽기가 실패하면 이 메서드는 호출되지 않고 screenshot_path 는 NULL 로 남는다 —
     * 목록에 "스크린샷 없음" 이 뜰 뿐 블록 킷은 멀쩡하다.
     */
    public function updateScreenshotPath(int $kitId, ?string $path): int
    {
        return $this->db->table($this->table)
            ->where('kit_id', '=', $kitId)
            ->update(['screenshot_path' => $path]);
    }

    /**
     * @param string[] $columns
     */
    private function fetchOne(int $domainId, int $kitId, array $columns): ?BlockKit
    {
        $row = $this->db->table($this->table)
            ->select($columns)
            ->where('kit_id', '=', $kitId)
            ->where('domain_id', '=', $domainId)
            ->where('is_deleted', '=', 0)
            ->first();

        return $row ? BlockKit::fromArray($row) : null;
    }
}
