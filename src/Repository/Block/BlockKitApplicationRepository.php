<?php
declare(strict_types=1);
namespace Mublo\Repository\Block;

use Mublo\Entity\Block\BlockKitApplication;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * BlockKitApplication Repository
 *
 * 책임:
 * - block_kit_applications 테이블 CRUD
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 *
 * 모든 조회는 domain_id 로 격리한다. 이력에는 site_config 스냅샷이 들어 있어,
 * 남의 도메인 이력이 열리면 그 사이트의 레이아웃 설정이 새어 나간다(설계 9.5).
 */
class BlockKitApplicationRepository extends BaseRepository
{
    protected string $table = 'block_kit_applications';
    protected string $entityClass = BlockKitApplication::class;
    protected string $primaryKey = 'application_id';

    /**
     * 스냅샷도 함께 읽는다.
     *
     * block_kits.kit_json 과 달리 이 컬럼은 크지 않다 — 담기는 것은
     * BlockKitApplier::SITE_CONFIG_ALLOWED_KEYS 여덟 개뿐이다. 그리고 목록이
     * "되돌릴 수 있는가" 를 표시하려면 스냅샷의 존재 여부를 알아야 한다.
     * 읽지 않으면 canRollback() 이 언제나 false 가 된다.
     *
     * @var string[]
     */
    private const COLUMNS = [
        'application_id',
        'kit_id',
        'domain_id',
        'apply_mode',
        'target_kind',
        'target_position',
        'target_menu_code',
        'target_page_code',
        'created_row_count',
        'site_config_snapshot',
        'applied_by',
        'applied_at',
    ];

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 블록 킷의 적용 이력. 최신순.
     *
     * @return BlockKitApplication[]
     */
    public function findAllByKit(int $domainId, int $kitId, int $limit = 50): array
    {
        $rows = $this->db->table($this->table)
            ->select(self::COLUMNS)
            ->where('domain_id', '=', $domainId)
            ->where('kit_id', '=', $kitId)
            ->orderBy('application_id', 'DESC')
            ->limit($limit)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 되돌리기가 쓴다. domain_id 로 격리한다 — 남의 도메인 이력을 되돌릴 수 없다.
     */
    public function findWithSnapshot(int $domainId, int $applicationId): ?BlockKitApplication
    {
        $row = $this->db->table($this->table)
            ->select(self::COLUMNS)
            ->where('application_id', '=', $applicationId)
            ->where('domain_id', '=', $domainId)
            ->first();

        return $row ? BlockKitApplication::fromArray($row) : null;
    }

    /**
     * 되돌릴 이력의 스냅샷을 조건부로 선점하고 비운다.
     *
     * 두 번 되돌리면 두 번째는 첫 번째가 복원한 설정이 아니라 원래 스냅샷을 다시 쓴다.
     * 그 사이에 운영자가 레이아웃을 바꿨다면 그 변경이 조용히 지워진다.
     * 그래서 되돌리기는 한 번만 허용한다. domain_id와 NOT NULL 조건을 UPDATE에
     * 함께 걸어 동시 요청 중 정확히 하나만 1을 돌려받게 한다.
     */
    public function claimSnapshotForRollback(int $domainId, int $applicationId): int
    {
        return $this->db->table($this->table)
            ->where('application_id', '=', $applicationId)
            ->where('domain_id', '=', $domainId)
            ->whereNotNull('site_config_snapshot')
            ->update(['site_config_snapshot' => null]);
    }
}
