<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Repository;

use Mublo\Contract\Board\BoardDescriptor;
use Mublo\Contract\Board\BoardReadOverlayInterface;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\BaseRepository;

/**
 * BoardConfig Repository
 *
 * 게시판 설정 데이터베이스 접근 담당
 *
 * 책임:
 * - board_configs 테이블 CRUD
 * - BoardConfig Entity 반환
 * - 요청 단위 read overlay가 있으면 프론트 조회(find·findBySlug·findAccessibleById·findActiveByDomain)를
 *   overlay의 최종 설정으로 응답. 글·댓글·파일·커뮤니티·블록 렌더러가 모두 이 조회를 거치므로
 *   미리보기 게시판 설정이 한 요청 안에서 어긋나지 않는다.
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class BoardConfigRepository extends BaseRepository
{
    protected string $table = 'board_configs';
    protected string $entityClass = BoardConfig::class;
    protected string $primaryKey = 'board_id';

    private ?BoardReadOverlayInterface $readOverlay;

    public function __construct(Database $db, ?BoardReadOverlayInterface $readOverlay = null)
    {
        parent::__construct($db);
        $this->readOverlay = $readOverlay;
    }

    /**
     * ID로 게시판 조회. overlay에 있는 게시판(음수 임시 ID 포함)은 overlay 설정을 돌려준다.
     */
    public function find(int|string $id): ?object
    {
        $overlay = $this->readOverlay?->findById((int) $id);
        return $overlay !== null ? $this->fromDescriptor($overlay) : parent::find($id);
    }

    private function fromDescriptor(BoardDescriptor $board): BoardConfig
    {
        return BoardConfig::fromArray($board->values + [
            'board_id' => $board->boardId,
            'board_slug' => $board->slug,
            'board_name' => $board->name,
            'group_id' => $board->groupId,
            'is_active' => $board->active ? 1 : 0,
        ]);
    }

    /**
     * @param list<BoardDescriptor> $boards
     * @return BoardConfig[]
     */
    private function fromDescriptors(array $boards): array
    {
        return array_map(fn(BoardDescriptor $board): BoardConfig => $this->fromDescriptor($board), $boards);
    }

    /**
     * 도메인별 게시판 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $limit 조회 개수
     * @param int $offset 시작 위치
     * @return BoardConfig[]
     */
    public function findByDomain(int $domainId, int $limit = 100, int $offset = 0): array
    {
        $rows = $this->getDb()->table($this->table)
            ->whereRaw('(domain_id = ? OR is_global = 1)', [$domainId])
            ->orderBy('sort_order', 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 그룹별 게시판 목록 조회
     *
     * @param int $groupId 그룹 ID
     * @return BoardConfig[]
     */
    public function findByGroup(int $groupId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('group_id', '=', $groupId)
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 이 도메인의 그룹별 게시판 목록. overlay가 있으면 overlay의 최종 게시판(그룹 이동·새 게시판·음수 임시 그룹 포함)을 쓴다.
     *
     * @return BoardConfig[]
     */
    public function findByGroupForDomain(int $domainId, int $groupId): array
    {
        $overlay = $this->readOverlay?->boards($domainId);
        if ($overlay !== null) {
            $boards = array_values(array_filter($overlay, static fn($board): bool => $board->groupId === $groupId));
            usort($boards, static fn($a, $b): int =>
                (int) ($a->values['sort_order'] ?? 0) <=> (int) ($b->values['sort_order'] ?? 0));
            return $this->fromDescriptors($boards);
        }
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('group_id', '=', $groupId)
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 도메인+슬러그로 게시판 조회
     */
    public function findBySlug(int $domainId, string $slug): ?BoardConfig
    {
        // overlay가 있는 도메인은 overlay 목록이 최종 상태다. 없는 슬러그는 미리보기에서 없는 게시판이다.
        if ($this->readOverlay?->boards($domainId) !== null) {
            $overlay = $this->readOverlay->findBySlug($domainId, $slug);
            return $overlay !== null ? $this->fromDescriptor($overlay) : null;
        }
        $row = $this->getDb()->table($this->table)
            ->whereRaw('(domain_id = ? OR is_global = 1)', [$domainId])
            ->where('board_slug', '=', $slug)
            ->orderBy('is_global', 'ASC')
            ->first();

        return $row ? BoardConfig::fromArray($row) : null;
    }

    /**
     * 블록/프론트에서 접근 가능한 게시판을 ID로 조회한다.
     *
     * 게시판 패키지의 명시적 예외 정책은 현재 도메인 소유 또는 전역(is_global=1)이다.
     */
    public function findAccessibleById(int $domainId, int $boardId): ?BoardConfig
    {
        $overlay = $this->readOverlay?->boards($domainId);
        if ($overlay !== null) {
            foreach ($overlay as $board) {
                if ($board->boardId === $boardId) {
                    return $this->fromDescriptor($board);
                }
            }
            return null;
        }
        $row = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->whereRaw('(domain_id = ? OR is_global = 1)', [$domainId])
            ->first();

        return $row ? BoardConfig::fromArray($row) : null;
    }

    /**
     * 슬러그 중복 검사
     *
     * 현재 도메인의 슬러그 + 전역(is_global) 슬러그 충돌도 함께 차단한다.
     */
    public function existsBySlug(int $domainId, string $slug): bool
    {
        return $this->getDb()->table($this->table)
            ->whereRaw('(domain_id = ? OR is_global = 1)', [$domainId])
            ->where('board_slug', '=', $slug)
            ->exists();
    }

    /**
     * 슬러그 중복 검사 (자기 자신 제외)
     */
    public function existsBySlugExceptSelf(int $domainId, string $slug, int $boardId): bool
    {
        $query = $this->getDb()->table($this->table)
            ->whereRaw('(domain_id = ? OR is_global = 1)', [$domainId])
            ->where('board_slug', '=', $slug)
            ->where('board_id', '!=', $boardId);

        return $query->exists();
    }

    /**
     * 도메인별 게시판 수 조회
     */
    public function countByDomain(int $domainId): int
    {
        return $this->getDb()->table($this->table)
            ->whereRaw('(domain_id = ? OR is_global = 1)', [$domainId])
            ->count();
    }

    /**
     * 그룹별 게시판 수 조회
     */
    public function countByGroup(int $groupId): int
    {
        return $this->countBy(['group_id' => $groupId]);
    }

    /**
     * 도메인별 활성 게시판 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return BoardConfig[]
     */
    public function findActiveByDomain(int $domainId): array
    {
        $overlay = $this->readOverlay?->boards($domainId, true);
        if ($overlay !== null) {
            return $this->fromDescriptors($overlay);
        }
        $rows = $this->getDb()->table($this->table)
            ->whereRaw('(domain_id = ? OR is_global = 1)', [$domainId])
            ->where('is_active', '=', 1)
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 그룹별 활성 게시판 목록 조회
     *
     * @param int $groupId 그룹 ID
     * @return BoardConfig[]
     */
    public function findActiveByGroup(int $groupId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('group_id', '=', $groupId)
            ->where('is_active', '=', 1)
            ->orderBy('sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 정렬 순서 업데이트
     *
     * @param int[] $boardIds 정렬된 게시판 ID 배열
     * @return bool
     */
    public function updateOrder(array $boardIds): bool
    {
        foreach ($boardIds as $index => $boardId) {
            $this->getDb()->table($this->table)
                ->where('board_id', '=', $boardId)
                ->update(['sort_order' => $index]);
        }

        return true;
    }

    /**
     * 다음 정렬 순서 반환
     */
    public function getNextSortOrder(int $domainId): int
    {
        $maxOrder = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->max('sort_order');

        return ($maxOrder ?? -1) + 1;
    }

    /**
     * 게시글 수 조회
     */
    public function getArticleCount(int $boardId): int
    {
        return $this->getDb()->table('board_articles')
            ->where('board_id', '=', $boardId)
            ->count();
    }

    /**
     * 선택 옵션용 게시판 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return array [['value' => id, 'label' => name, 'group_id' => groupId], ...]
     */
    public function getSelectOptions(int $domainId): array
    {
        $boards = $this->findActiveByDomain($domainId);

        return array_map(fn(BoardConfig $board) => [
            'value' => $board->getBoardId(),
            'label' => $board->getBoardName(),
            'group_id' => $board->getGroupId(),
        ], $boards);
    }

    /**
     * 게시판 목록 조회 (그룹 정보 포함)
     *
     * @param int $domainId 도메인 ID
     * @return array 게시판 배열 (그룹 정보 포함)
     */
    public function findByDomainWithGroup(int $domainId, int $limit = 100, int $offset = 0): array
    {
        $rows = $this->getDb()->table($this->table . ' AS b')
            ->select([
                'b.*',
                'g.group_name',
                'g.group_slug',
            ])
            ->leftJoin('board_groups AS g', 'b.group_id', '=', 'g.group_id')
            ->whereRaw('(b.domain_id = ? OR b.is_global = 1)', [$domainId])
            ->orderBy('g.sort_order', 'ASC')
            ->orderBy('b.sort_order', 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        // Entity와 그룹 정보를 함께 반환
        $result = [];
        foreach ($rows as $row) {
            $config = BoardConfig::fromArray($row);
            $result[] = [
                'config' => $config,
                'group_name' => $row['group_name'] ?? '',
                'group_slug' => $row['group_slug'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * 카테고리를 사용하는 게시판 목록 조회
     *
     * @param int $categoryId 카테고리 ID
     * @return BoardConfig[]
     */
    public function findByCategoryId(int $categoryId): array
    {
        $rows = $this->getDb()->table($this->table . ' AS b')
            ->select(['b.*'])
            ->join('board_category_mapping AS m', 'b.board_id', '=', 'm.board_id')
            ->where('m.category_id', '=', $categoryId)
            ->orderBy('b.sort_order', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * JSON 필드 업데이트 (board_admin_ids, reaction_config)
     */
    public function updateJsonField(int $boardId, string $field, ?array $value): bool
    {
        $allowedFields = ['board_admin_ids', 'reaction_config'];

        if (!in_array($field, $allowedFields, true)) {
            return false;
        }

        $jsonValue = $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);

        $affected = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->update([$field => $jsonValue]);

        return $affected >= 0;
    }
}
