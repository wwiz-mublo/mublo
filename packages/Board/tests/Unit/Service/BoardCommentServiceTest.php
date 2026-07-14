<?php
/**
 * packages/Board/tests/Unit/Service/BoardCommentServiceTest.php
 *
 * BoardCommentService::create() 보안 회귀 테스트 — 읽기 게이트
 *
 * 배경: canComment()는 article을 인자로 받지 않아 비밀글/등급제한을 볼 수 없다.
 * 따라서 create()는 canComment 이전에 (1) 도메인 경계와 (2) canRead를 먼저 강제해야
 * "못 읽는 글(비밀글·등급제한)에 댓글이 달리는" 정보노출/우회를 막는다.
 *
 * 이 테스트는 다음을 고정한다:
 * - 타 도메인 게시글 → 거부, 저장 없음
 * - canRead=false     → 거부, 저장 없음 (canComment 도달 전 차단)
 * - 존재하지 않는 글  → 거부
 */

namespace Tests\Board\Unit\Service;

use Tests\Board\TestCase;
use Mublo\Packages\Board\Service\BoardCommentService;
use Mublo\Packages\Board\Service\BoardPermissionService;
use Mublo\Packages\Board\Repository\BoardCommentRepository;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Entity\BoardComment;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Contract\Auth\AuthenticatedUser;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Infrastructure\Database\Database;
use Mublo\Core\Http\Request;
use Mublo\Entity\Member\Member;
use Mublo\Packages\Board\Event\CommentCreatingEvent;
use Mublo\Packages\Board\Event\CommentUpdatedEvent;

class BoardCommentServiceTest extends TestCase
{
    private BoardCommentRepository $commentRepo;
    private BoardArticleRepository $articleRepo;
    private BoardConfigRepository $boardRepo;
    private MemberRepository $memberRepo;
    private BoardPermissionService $permission;
    private AuthContextInterface $auth;
    private BoardCommentService $service;
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->commentRepo = $this->createMock(BoardCommentRepository::class);
        $this->articleRepo = $this->createMock(BoardArticleRepository::class);
        $this->boardRepo   = $this->createMock(BoardConfigRepository::class);
        $this->memberRepo  = $this->createMock(MemberRepository::class);
        $this->permission  = $this->createMock(BoardPermissionService::class);
        $this->auth        = $this->createMock(AuthContextInterface::class);
        $this->db          = $this->createMock(Database::class);
        $this->db->method('transaction')->willReturnCallback(static fn(callable $callback) => $callback());
        $this->commentRepo->method('getDb')->willReturn($this->db);

        $this->service = new BoardCommentService(
            $this->commentRepo,
            $this->articleRepo,
            $this->boardRepo,
            $this->memberRepo,
            $this->permission,
            null,               // EventDispatcher 미사용
            $this->auth
        );
    }

    private function makeArticle(array $overrides = []): BoardArticle
    {
        return BoardArticle::fromArray($this->makeArticleData($overrides));
    }

    private function contextWithDomain(int $domainId): Context
    {
        $context = $this->createMock(Context::class);
        $context->method('getDomainId')->willReturn($domainId);
        $request = $this->createMock(Request::class);
        $request->method('getClientIp')->willReturn('127.0.0.1');
        $context->method('getRequest')->willReturn($request);
        return $context;
    }

    public function testRejectsCommentOnMissingArticle(): void
    {
        $this->articleRepo->method('find')->willReturn(null);
        $this->commentRepo->expects($this->never())->method('create');

        $result = $this->service->create(999, ['content' => 'hi'], $this->contextWithDomain(1));

        $this->assertFalse($result->isSuccess());
    }

    public function testRejectsCommentFromForeignDomain(): void
    {
        // 글은 도메인 1, 요청 컨텍스트는 도메인 2 → 경계 위반
        $this->articleRepo->method('find')->willReturn($this->makeArticle(['domain_id' => 1]));
        $this->boardRepo->method('find')->willReturn($this->createMock(BoardConfig::class));
        $this->commentRepo->expects($this->never())->method('create');

        $result = $this->service->create(1, ['content' => 'hi'], $this->contextWithDomain(2));

        $this->assertFalse($result->isSuccess());
    }

    public function testGlobalBoardAllowsCommentFromForeignDomain(): void
    {
        $this->articleRepo->method('find')->willReturn($this->makeArticle([
            'domain_id' => 1,
            'board_id' => 1,
        ]));
        $board = $this->createMock(BoardConfig::class);
        $board->method('isGlobal')->willReturn(true);
        $this->boardRepo->method('find')->willReturn($board);
        $this->permission->expects($this->once())->method('canRead')->willReturn(true);
        $this->permission->method('canComment')->willReturn(false);

        $result = $this->service->create(1, ['content' => 'hi'], $this->contextWithDomain(2));

        $this->assertTrue($result->isFailure());
        $this->assertSame('댓글 작성 권한이 없습니다.', $result->getMessage());
    }

    public function testRejectsCommentWhenArticleNotReadable(): void
    {
        // 도메인은 일치하지만 canRead=false (비밀글/등급제한) → 저장 없이 거부
        $this->articleRepo->method('find')->willReturn($this->makeArticle(['domain_id' => 1]));
        $this->boardRepo->method('find')->willReturn($this->createMock(BoardConfig::class));
        $this->permission->method('canRead')->willReturn(false);
        // 읽기 게이트에서 막히므로 canComment는 도달조차 하면 안 된다.
        $this->permission->expects($this->never())->method('canComment');
        $this->commentRepo->expects($this->never())->method('create');

        $result = $this->service->create(1, ['content' => 'hi'], $this->contextWithDomain(1));

        $this->assertFalse($result->isSuccess());
    }

    public function testRejectsWhenReadableButCannotComment(): void
    {
        // 읽기는 되지만 쓰기 권한 없음 → 여전히 저장 없음
        $this->articleRepo->method('find')->willReturn($this->makeArticle(['domain_id' => 1]));
        $this->boardRepo->method('find')->willReturn($this->createMock(BoardConfig::class));
        $this->permission->method('canRead')->willReturn(true);
        $this->permission->method('canComment')->willReturn(false);
        $this->commentRepo->expects($this->never())->method('create');

        $result = $this->service->create(1, ['content' => 'hi'], $this->contextWithDomain(1));

        $this->assertFalse($result->isSuccess());
    }

    public function testRejectsParentCommentFromAnotherArticle(): void
    {
        $this->prepareReadableCommentTarget();
        $this->commentRepo->method('find')->with(99)->willReturn($this->makeComment([
            'comment_id' => 99,
            'article_id' => 777,
        ]));
        $this->commentRepo->expects($this->never())->method('generatePath');
        $this->commentRepo->expects($this->never())->method('create');

        $result = $this->service->create(1, [
            'content' => 'reply',
            'parent_id' => 99,
        ], $this->contextWithDomain(1));

        $this->assertTrue($result->isFailure());
        $this->assertSame('유효하지 않은 부모 댓글입니다.', $result->getMessage());
    }

    public function testRejectsDeletedParentComment(): void
    {
        $this->prepareReadableCommentTarget();
        $this->commentRepo->method('find')->with(99)->willReturn($this->makeComment([
            'comment_id' => 99,
            'status' => 'deleted',
        ]));
        $this->commentRepo->expects($this->never())->method('generatePath');
        $this->commentRepo->expects($this->never())->method('create');

        $result = $this->service->create(1, [
            'content' => 'reply',
            'parent_id' => 99,
        ], $this->contextWithDomain(1));

        $this->assertTrue($result->isFailure());
    }

    public function testCreateRejectsContentEmptiedByCreatingSubscriber(): void
    {
        $this->prepareReadableCommentTarget();
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(CommentCreatingEvent::class, static function (CommentCreatingEvent $event): void {
            $data = $event->getData();
            $data['content'] = "\0\r\n\x07";
            $event->setData($data);
        });
        $this->service = new BoardCommentService(
            $this->commentRepo,
            $this->articleRepo,
            $this->boardRepo,
            $this->memberRepo,
            $this->permission,
            $dispatcher,
            $this->auth
        );
        $this->commentRepo->expects($this->never())->method('create');

        $result = $this->service->create(1, ['content' => '댓글'], $this->contextWithDomain(1));

        $this->assertTrue($result->isFailure());
        $this->assertSame('댓글 내용을 입력해주세요.', $result->getMessage());
    }

    public function testCreateFailsAsOneUnitWhenCommentCountSyncFails(): void
    {
        $this->prepareReadableCommentTarget();
        $member = Member::fromArray([
            'member_id' => 42,
            'domain_id' => 1,
            'user_id' => 'member42',
            'nickname' => '회원42',
            'password' => 'hash',
            'level_value' => 1,
            'status' => 'active',
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->memberRepo->method('find')->with(42)->willReturn($member);
        $this->commentRepo->method('generatePath')->willReturn('11');
        $this->commentRepo->method('calculateDepth')->willReturn(0);
        $this->commentRepo->expects($this->once())->method('create')->willReturn(11);
        $this->articleRepo->expects($this->once())
            ->method('syncCommentCount')
            ->willThrowException(new \RuntimeException('count sync failed'));
        $this->commentRepo->expects($this->never())->method('find');

        $result = $this->service->create(1, ['content' => '댓글'], $this->contextWithDomain(1));

        $this->assertTrue($result->isFailure());
        $this->assertSame('댓글 저장에 실패했습니다.', $result->getMessage());
    }

    public function testUpdateNormalizesPlainTextPreservesSecretFlagAndDispatchesEvent(): void
    {
        $original = $this->makeComment([
            'comment_id' => 7,
            'domain_id' => 1,
            'member_id' => 42,
            'content' => '기존',
            'is_secret' => 1,
        ]);
        $updated = $this->makeComment([
            'comment_id' => 7,
            'domain_id' => 1,
            'member_id' => 42,
            'content' => "<b>안전한 평문</b>\n둘째 줄",
            'is_secret' => 1,
        ]);
        $this->commentRepo->expects($this->exactly(2))
            ->method('find')
            ->with(7)
            ->willReturnOnConsecutiveCalls($original, $updated);
        $board = $this->createMock(BoardConfig::class);
        $board->method('isGlobal')->willReturn(false);
        $this->boardRepo->method('find')->willReturn($board);
        $this->auth->method('id')->willReturn(42);

        $saved = null;
        $this->commentRepo->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (int $id, array $data) use (&$saved): int {
                $saved = $data;
                return 1;
            });

        $dispatched = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(CommentUpdatedEvent::class, static function (CommentUpdatedEvent $event) use (&$dispatched): void {
            $dispatched = $event;
        });
        $this->service = new BoardCommentService(
            $this->commentRepo,
            $this->articleRepo,
            $this->boardRepo,
            $this->memberRepo,
            $this->permission,
            $dispatcher,
            $this->auth
        );

        $result = $this->service->update(
            7,
            ['content' => " \r\n<b>안전한 평문</b>\x00\r둘째 줄 "],
            $this->contextWithDomain(1)
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame("<b>안전한 평문</b>\n둘째 줄", $saved['content']);
        $this->assertArrayNotHasKey('is_secret', $saved);
        $this->assertInstanceOf(CommentUpdatedEvent::class, $dispatched);
        $this->assertSame(7, $dispatched->getCommentId());
        $this->assertTrue((bool) $dispatched->getOldData()['is_secret']);
    }

    public function testUpdateRejectsCommentBeyondTextCapacity(): void
    {
        $comment = $this->makeComment([
            'comment_id' => 7,
            'domain_id' => 1,
            'member_id' => 42,
        ]);
        $this->commentRepo->method('find')->willReturn($comment);
        $board = $this->createMock(BoardConfig::class);
        $board->method('isGlobal')->willReturn(false);
        $this->boardRepo->method('find')->willReturn($board);
        $this->auth->method('id')->willReturn(42);
        $this->commentRepo->expects($this->never())->method('update');

        $result = $this->service->update(
            7,
            ['content' => str_repeat('가', 16001)],
            $this->contextWithDomain(1)
        );

        $this->assertTrue($result->isFailure());
        $this->assertSame('댓글 내용이 너무 깁니다.', $result->getMessage());
    }

    private function prepareReadableCommentTarget(): void
    {
        $this->articleRepo->method('find')->willReturn($this->makeArticle([
            'domain_id' => 1,
            'board_id' => 1,
        ]));
        $this->boardRepo->method('find')->willReturn($this->createMock(BoardConfig::class));
        $this->permission->method('canRead')->willReturn(true);
        $this->permission->method('canComment')->willReturn(true);
        $this->auth->method('id')->willReturn(42);
        $this->auth->method('currentUser')->willReturn(new AuthenticatedUser(
            memberId: 42,
            domainId: 1,
            userId: 'member42',
            nickname: '회원42',
            levelValue: 1,
            admin: false,
            super: false,
            canOperateDomain: false,
            avatar: null,
        ));
    }

    private function makeComment(array $overrides = []): BoardComment
    {
        return BoardComment::fromArray($this->makeCommentData($overrides));
    }
}
