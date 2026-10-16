<?php
declare(strict_types=1);
namespace Mublo\Repository\Domain;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * Class DomainAliasRepository
 *
 * domain_aliases 테이블 접근 Repository
 *
 * 책임:
 * - 별칭 호스트 → 사이트(domain_id) 조회 (요청마다 도메인 해석에 쓰인다)
 * - 사이트별 별칭 목록·추가·삭제
 *
 * Entity를 두지 않고 배열을 반환한다 — 별칭은 호스트 문자열과 domain_id 한 쌍이라
 * 모델로 감쌀 행동이 없다.
 *
 * 호스트는 호출자가 정규화(소문자·trim)해서 넘긴다
 * (DomainVerificationService::normalizeHost — 검증 기록과 같은 문자열을 쓰기 위함).
 */
class DomainAliasRepository extends BaseRepository
{
    protected string $table = 'domain_aliases';
    protected string $primaryKey = 'alias_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 별칭 호스트가 가리키는 사이트 ID (없으면 null)
     */
    public function findDomainIdByHost(string $host): ?int
    {
        $row = $this->getDb()->selectOne(
            "SELECT domain_id FROM domain_aliases WHERE alias_host = ? LIMIT 1",
            [$host]
        );

        return $row ? (int) $row['domain_id'] : null;
    }

    /**
     * 사이트의 별칭 목록 (등록순)
     *
     * @return array<int,array{alias_id:int,alias_host:string,created_by:?int,created_at:string}>
     */
    public function findByDomainId(int $domainId): array
    {
        $rows = $this->getDb()->select(
            "SELECT alias_id, alias_host, created_by, created_at
               FROM domain_aliases
              WHERE domain_id = ?
              ORDER BY alias_id ASC",
            [$domainId]
        );

        return array_map(static fn(array $row): array => [
            'alias_id' => (int) $row['alias_id'],
            'alias_host' => (string) $row['alias_host'],
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ], $rows);
    }

    /**
     * 별칭 추가
     *
     * @return int 생성된 alias_id
     */
    public function add(int $domainId, string $host, ?int $createdBy = null): int
    {
        return (int) $this->getDb()->insert(
            "INSERT INTO domain_aliases (domain_id, alias_host, created_by) VALUES (?, ?, ?)",
            [$domainId, $host, $createdBy ?: null]
        );
    }

    /**
     * 사이트의 별칭 하나 삭제 (다른 사이트의 별칭은 지우지 않는다)
     *
     * @return bool 실제로 지웠으면 true
     */
    public function removeHost(int $domainId, string $host): bool
    {
        return $this->getDb()->execute(
            "DELETE FROM domain_aliases WHERE domain_id = ? AND alias_host = ?",
            [$domainId, $host]
        ) > 0;
    }
}
