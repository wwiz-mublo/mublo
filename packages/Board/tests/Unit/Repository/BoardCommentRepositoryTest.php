<?php
/**
 * packages/Board/tests/Unit/Repository/BoardCommentRepositoryTest.php
 *
 * BoardCommentRepository 보안 회귀 테스트 — 대댓글 삭제의 게시글 스코프
 *
 * 배경: comment.path는 게시글별로 재採番되어 서로 다른 글의 루트 댓글이 동일한
 * path를 가질 수 있다. 따라서 'path LIKE' 로 하위 트리를 지울 때 반드시 같은
 * article_id로 스코프해야 한다. 스코프가 없으면 내 댓글 하나 삭제가 다른 글의
 * 답글 트리까지 지우는 데이터 손상이 된다(#145에서 수정).
 *
 * 이 테스트는 다음을 고정한다:
 * - deleteWithChildren가 where('article_id', '=', <해당 글>) 로 스코프한다
 * - OR 조건이 whereRaw('(comment_id = ? OR path LIKE ?)') 로 괄호 그룹핑되어
 *   연산자 우선순위 누수(bare orWhere)가 발생하지 않는다
 */

namespace Tests\Board\Unit\Repository;

use Tests\Board\TestCase;
use Mublo\Packages\Board\Repository\BoardCommentRepository;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\QueryBuilder;

class BoardCommentRepositoryTest extends TestCase
{
    private const COMMENT_ID = 10;
    private const ARTICLE_ID = 7;

    /** @var array<int, array> 캡처된 where 호출 */
    private array $whereCalls = [];
    /** @var array<int, array> 캡처된 whereRaw 호출 */
    private array $whereRawCalls = [];
    private BoardCommentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whereCalls = [];
        $this->whereRawCalls = [];

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'leftJoin', 'orderBy', 'limit', 'offset'] as $m) {
            $qb->method($m)->willReturnSelf();
        }
        $qb->method('where')->willReturnCallback(function (...$args) use ($qb) {
            $this->whereCalls[] = $args;
            return $qb;
        });
        $qb->method('whereRaw')->willReturnCallback(function (...$args) use ($qb) {
            $this->whereRawCalls[] = $args;
            return $qb;
        });
        // find()의 first() — 삭제 대상 댓글(글 7, path '7')을 반환
        $qb->method('first')->willReturn($this->makeCommentData([
            'comment_id' => self::COMMENT_ID,
            'article_id' => self::ARTICLE_ID,
            'path'       => (string) self::ARTICLE_ID,
        ]));
        $qb->method('update')->willReturn(3); // 영향 행 수

        $db = $this->createMock(Database::class);
        $db->method('table')->willReturn($qb);
        $db->method('prefixTable')->willReturnArgument(0);

        $this->repository = new BoardCommentRepository($db);
    }

    public function testDeleteWithChildrenScopesToArticle(): void
    {
        $affected = $this->repository->deleteWithChildren(self::COMMENT_ID);

        $this->assertSame(3, $affected);

        // 반드시 해당 게시글로 스코프되어야 한다 (크로스-아티클 삭제 방지)
        $scoped = false;
        foreach ($this->whereCalls as $call) {
            if (($call[0] ?? null) === 'article_id'
                && ($call[1] ?? null) === '='
                && (int) ($call[2] ?? -1) === self::ARTICLE_ID) {
                $scoped = true;
                break;
            }
        }
        $this->assertTrue(
            $scoped,
            'deleteWithChildren가 article_id로 스코프하지 않는다 — 타 게시글 답글 트리까지 삭제될 수 있다. '
            . 'where: ' . json_encode($this->whereCalls, JSON_UNESCAPED_UNICODE)
        );
    }

    public function testDeleteWithChildrenGroupsOrCondition(): void
    {
        $this->repository->deleteWithChildren(self::COMMENT_ID);

        // OR은 괄호로 묶인 whereRaw여야 한다 (bare orWhere 우선순위 누수 방지)
        $this->assertNotEmpty($this->whereRawCalls, 'whereRaw로 묶인 OR 조건이 없다');
        $sql = $this->whereRawCalls[0][0] ?? '';
        $this->assertStringContainsString('(', $sql);
        $this->assertStringContainsString(')', $sql);
        $this->assertStringContainsStringIgnoringCase('comment_id', $sql);
        $this->assertStringContainsStringIgnoringCase('path like', $sql);

        // path 파라미터는 하위 트리 접두어(예: '7/%')여야 한다
        $params = $this->whereRawCalls[0][1] ?? [];
        $this->assertContains(self::ARTICLE_ID . '/%', $params);
    }

    public function testDeleteWithChildrenReturnsZeroWhenCommentMissing(): void
    {
        // first()가 null이면 find()가 null → 아무 것도 삭제하지 않는다
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('whereRaw')->willReturnSelf();
        $qb->method('first')->willReturn(null);
        $qb->expects($this->never())->method('update');

        $db = $this->createMock(Database::class);
        $db->method('table')->willReturn($qb);
        $db->method('prefixTable')->willReturnArgument(0);

        $repo = new BoardCommentRepository($db);
        $this->assertSame(0, $repo->deleteWithChildren(999));
    }
}
