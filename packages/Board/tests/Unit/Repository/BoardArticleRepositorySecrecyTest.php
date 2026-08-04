<?php
/**
 * packages/Board/tests/Unit/Repository/BoardArticleRepositorySecrecyTest.php
 *
 * BoardArticleRepository 보안 회귀 테스트 — 공개 조회 경로의 비밀글 제외
 *
 * 배경: 권한(canRead)은 글 상세 경로에선 지켜지나, 검색·피드·인기글 같은 2차
 * 조회 경로는 쿼리 레이어에서 직접 필터해야 한다. 필터가 빠지면 게스트 검색·
 * 커뮤니티 피드에 비밀글의 제목·발췌가 노출된다(#145에서 수정).
 *
 * 방식: 실DB 대신 QueryBuilder를 목킹하고, 쿼리 빌드 중 적용된 where 조건을
 * 캡처하여 `is_secret = 0`이 반드시 걸리는지 검증한다. 필터가 제거되면 실패한다.
 */

namespace Tests\Board\Unit\Repository;

use Tests\Board\TestCase;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\QueryBuilder;

class BoardArticleRepositorySecrecyTest extends TestCase
{
    /** @var array<int, array> 빌드 중 캡처된 where(컬럼, 연산자, 값) 호출 */
    private array $whereCalls = [];
    private BoardArticleRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whereCalls = [];

        $qb = $this->createMock(QueryBuilder::class);
        // 모든 체이너블 메서드는 self 반환
        foreach (['select', 'leftJoin', 'join', 'whereRaw', 'orderBy', 'groupBy', 'limit', 'offset'] as $m) {
            $qb->method($m)->willReturnSelf();
        }
        // where는 인자를 기록하고 self 반환
        $qb->method('where')->willReturnCallback(function (...$args) use ($qb) {
            $this->whereCalls[] = $args;
            return $qb;
        });
        // 터미널 메서드
        $qb->method('get')->willReturn([]);
        $qb->method('count')->willReturn(0);

        $db = $this->createMock(Database::class);
        $db->method('table')->willReturn($qb);

        $this->repository = new BoardArticleRepository($db);
    }

    /** 캡처된 where 목록에 (컬럼, '=', 0) 형태의 비밀글 제외 조건이 있는지 */
    private function assertSecrecyFilterApplied(): void
    {
        $found = false;
        foreach ($this->whereCalls as $call) {
            $col = $call[0] ?? null;
            $op  = $call[1] ?? null;
            $val = $call[2] ?? null;
            if (in_array($col, ['is_secret', 'a.is_secret'], true) && $op === '=' && (int) $val === 0) {
                $found = true;
                break;
            }
        }
        $this->assertTrue(
            $found,
            '공개 조회 경로에 is_secret=0 필터가 없다 — 비밀글이 노출된다. 적용된 where: '
            . json_encode($this->whereCalls, JSON_UNESCAPED_UNICODE)
        );
    }

    public function testSearchByKeywordExcludesSecretArticles(): void
    {
        $this->repository->searchByKeyword(1, '검색어', 5);
        $this->assertSecrecyFilterApplied();
    }

    public function testCountByKeywordExcludesSecretArticles(): void
    {
        $this->repository->countByKeyword(1, '검색어');
        $this->assertSecrecyFilterApplied();
    }

    public function testGetPopularExcludesSecretArticles(): void
    {
        $this->repository->getPopular(1, 10);
        $this->assertSecrecyFilterApplied();
    }

    public function testGetPopularPaginatedExcludesSecretArticles(): void
    {
        $this->repository->getPopularPaginated(1, 1, 10);
        $this->assertSecrecyFilterApplied();
    }

    public function testGetAllByDomainExcludesSecretArticles(): void
    {
        // 커뮤니티 피드(도메인 전체 목록)에 비밀글이 섞이면 안 된다.
        $this->repository->getAllByDomain(1, 1, 20);
        $this->assertSecrecyFilterApplied();
    }
}
