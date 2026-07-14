<?php
namespace Mublo\Repository\Block;

use Mublo\Entity\Block\BlockPage;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * BlockPage Repository
 *
 * 블록 페이지 데이터베이스 접근 담당
 *
 * 책임:
 * - block_pages 테이블 CRUD
 * - BlockPage Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class BlockPageRepository extends BaseRepository
{
    protected string $table = 'block_pages';
    protected string $entityClass = BlockPage::class;
    protected string $primaryKey = 'page_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 도메인별 페이지 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $limit 조회 개수
     * @param int $offset 시작 위치
     * @return BlockPage[]
     */
    public function findByDomain(int $domainId, int $limit = 100, int $offset = 0): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_deleted', '=', 0)
            ->orderBy('page_title', 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 도메인+코드로 페이지 조회
     */
    public function findByCode(int $domainId, string $code): ?BlockPage
    {
        return $this->findOneBy([
            'domain_id' => $domainId,
            'page_code' => $code,
            'is_deleted' => 0,
        ]);
    }

    /**
     * 도메인+코드로 페이지 조회 (소프트 삭제 포함)
     *
     * uk_domain_code (domain_id, page_code) 는 is_deleted 를 포함하지 않는 UNIQUE 키다.
     * 따라서 삭제된 페이지의 코드로 새 페이지를 만들면 중복키 오류가 난다.
     * 블록 킷 적용처럼 "코드를 점유한 행이 있는가"를 물어야 하는 곳은 이 메서드를 쓴다.
     */
    public function findByCodeIncludingDeleted(int $domainId, string $code): ?BlockPage
    {
        return $this->findOneBy([
            'domain_id' => $domainId,
            'page_code' => $code,
        ]);
    }

    /**
     * 코드 중복 검사
     */
    public function existsByCode(int $domainId, string $code): bool
    {
        return $this->existsBy([
            'domain_id' => $domainId,
            'page_code' => $code,
        ]);
    }

    /**
     * 코드 중복 검사 (자기 자신 제외)
     */
    public function existsByCodeExceptSelf(int $domainId, string $code, int $pageId): bool
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('page_code', '=', $code)
            ->where('page_id', '!=', $pageId);

        return $query->exists();
    }

    /**
     * 도메인별 페이지 수 조회
     */
    public function countByDomain(int $domainId): int
    {
        return $this->countBy(['domain_id' => $domainId, 'is_deleted' => 0]);
    }

    /**
     * 도메인별 활성 페이지 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return BlockPage[]
     */
    public function findActiveByDomain(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->orderBy('page_title', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 선택 옵션용 페이지 목록 조회
     *
     * @param int $domainId 도메인 ID
     * @return array [['value' => id, 'label' => title], ...]
     */
    public function getSelectOptions(int $domainId): array
    {
        $pages = $this->findActiveByDomain($domainId);

        return array_map(fn(BlockPage $page) => [
            'value' => $page->getPageId(),
            'label' => $page->getPageTitle(),
            'code' => $page->getPageCode(),
        ], $pages);
    }

    /**
     * 페이지네이션
     *
     * @param int $domainId 도메인 ID
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 개수
     * @return array ['data' => BlockPage[], 'total' => int, ...]
     */
    public function paginateByDomain(int $domainId, int $page = 1, int $perPage = 15, string $keyword = ''): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;
        $keyword = trim($keyword);

        $total = $this->buildListQuery($domainId, $keyword)->count();

        $rows = $this->buildListQuery($domainId, $keyword)
            ->orderBy('page_title', 'ASC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data' => $this->toEntities($rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
            'keyword' => $keyword,
        ];
    }

    /**
     * 목록/검색 공용 쿼리 (도메인 + 미삭제 + 선택적 키워드 OR 검색)
     *
     * 검색 대상 필드: page_code / page_title / page_description
     */
    private function buildListQuery(int $domainId, string $keyword = '')
    {
        $query = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_deleted', '=', 0);

        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->where('page_code', 'LIKE', $like)
                    ->orWhere('page_title', 'LIKE', $like)
                    ->orWhere('page_description', 'LIKE', $like);
            });
        }

        return $query;
    }

    /**
     * 키워드로 검색
     *
     * @param int $domainId 도메인 ID
     * @param string $keyword 검색어
     * @return BlockPage[]
     */
    public function search(int $domainId, string $keyword): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where(function ($query) use ($keyword) {
                $query->where('page_code', 'LIKE', "%{$keyword}%")
                    ->orWhere('page_title', 'LIKE', "%{$keyword}%");
            })
            ->orderBy('page_title', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }
}
