<?php
declare(strict_types=1);

namespace Tests\Board\Unit\Controller;

use Mublo\Contract\Auth\AuthenticatedUser;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Contract\Member\MemberActionQueryInterface;
use Mublo\Contract\Member\MemberActionScope;
use Mublo\Contract\Member\MemberActionTargetTransport;
use Mublo\Contract\Member\MemberActionView;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Packages\Board\Controller\Front\BoardController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class BoardMemberActionIntegrationTest extends TestCase
{
    public function testItBatchesAuthorsAndRemovesInternalIdsFromViewData(): void
    {
        $publicIds = [
            77 => 'a3f9c2e81b47d06f5a92c1',
            88 => 'b3f9c2e81b47d06f5a92c2',
        ];
        $action = new MemberActionView(
            'plugin:DirectMessage:compose',
            '쪽지 보내기',
            'bi bi-envelope',
            '/direct-message/compose',
            MemberActionTargetTransport::PrivateBody,
        );

        $members = $this->createMock(MemberQueryInterface::class);
        $members->expects($this->once())
            ->method('publicIdsFor')
            ->with(1, [77, 88])
            ->willReturn($publicIds);

        $actions = $this->createMock(MemberActionQueryInterface::class);
        $actions->expects($this->once())
            ->method('forMember')
            ->with(
                $this->callback(fn (MemberActionScope $scope): bool => $this->scopeMatches(
                    $scope,
                    ['board.article_author', 'member.author']
                )),
                77,
            )
            ->willReturn([$action]);
        $actions->expects($this->once())
            ->method('forMembers')
            ->with(
                $this->callback(fn (MemberActionScope $scope): bool => $this->scopeMatches(
                    $scope,
                    ['board.comment_author', 'member.author']
                )),
                [88, 77],
            )
            ->willReturn([88 => [$action], 77 => []]);

        $auth = $this->createMock(AuthContextInterface::class);
        $auth->method('currentUser')->willReturn(new AuthenticatedUser(
            5,
            1,
            'viewer',
            '뷰어',
            3,
            false,
            false,
            false,
            null,
        ));
        $auth->method('isProxyLogin')->willReturn(false);

        $controller = (new ReflectionClass(BoardController::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'memberQueries', $members);
        $this->setProperty($controller, 'memberActions', $actions);
        $this->setProperty($controller, 'authService', $auth);

        $method = (new ReflectionClass(BoardController::class))->getMethod('decorateMemberAuthors');
        $result = $method->invoke($controller, 1, [], [
            ['comment_id' => 1, 'member_id' => 88, 'is_own' => false],
            ['comment_id' => 2, 'member_id' => 88, 'is_own' => false],
            ['comment_id' => 3, 'member_id' => 77, 'is_own' => false],
            ['comment_id' => 4, 'member_id' => null, 'is_own' => false],
        ], 77);

        self::assertArrayNotHasKey('member_id', $result['article']);
        self::assertSame($publicIds[77], $result['article']['author_public_id']);
        self::assertSame([$action], $result['article']['author_actions']);

        self::assertSame($publicIds[88], $result['comments'][0]['author_public_id']);
        self::assertSame([$action], $result['comments'][0]['author_actions']);
        self::assertSame([$action], $result['comments'][1]['author_actions']);
        self::assertSame([], $result['comments'][2]['author_actions']);
        self::assertSame('', $result['comments'][3]['author_public_id']);
        self::assertSame([], $result['comments'][3]['author_actions']);
        foreach ($result['comments'] as $comment) {
            self::assertArrayNotHasKey('member_id', $comment);
        }
    }

    /** @param list<string> $placements */
    private function scopeMatches(MemberActionScope $scope, array $placements): bool
    {
        return $scope->domainId === 1
            && $scope->viewerMemberId === 5
            && $scope->viewerLevel === 3
            && $scope->proxyLogin === false
            && $scope->placements === $placements;
    }

    private function setProperty(BoardController $controller, string $property, object $value): void
    {
        $reflection = new ReflectionClass(BoardController::class);
        $reflection->getProperty($property)->setValue($controller, $value);
    }
}
