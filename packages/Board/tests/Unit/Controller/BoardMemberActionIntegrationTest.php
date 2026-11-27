<?php
declare(strict_types=1);

namespace Tests\Board\Unit\Controller;

use Mublo\Contract\Auth\AuthenticatedUser;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Contract\Member\MemberActionQueryInterface;
use Mublo\Contract\Member\MemberActionScope;
use Mublo\Contract\Member\MemberActionTargetTransport;
use Mublo\Contract\Member\MemberActionView;
use Mublo\Contract\Member\MemberPublicAppearance;
use Mublo\Contract\Member\MemberPublicAppearanceQueryInterface;
use Mublo\Packages\Board\Controller\Front\BoardController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class BoardMemberActionIntegrationTest extends TestCase
{
    public function testListDecorationExposesAppearanceWithoutInternalMemberId(): void
    {
        $controller = (new ReflectionClass(BoardController::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(BoardController::class))->getMethod('decorateListAuthorAppearances');

        $result = $method->invoke(
            $controller,
            [['article_id' => 1, 'author_name' => '작성자']],
            [['article_id' => 1, 'member_id' => 77]],
            [77 => new MemberPublicAppearance('a3f9c2e81b47d06f5a92c1', '/storage/avatar.webp', true)],
        );

        self::assertArrayNotHasKey('member_id', $result[0]);
        self::assertSame('/storage/avatar.webp', $result[0]['author_avatar_url']);
        self::assertTrue($result[0]['author_is_super']);
        self::assertSame('a3f9c2e81b47d06f5a92c1', $result[0]['author_public_id']);
    }

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

        $appearances = $this->createMock(MemberPublicAppearanceQueryInterface::class);
        $appearances->expects($this->once())
            ->method('forMembers')
            ->with(1, [77, 88])
            ->willReturn([
                77 => new MemberPublicAppearance($publicIds[77], '/storage/avatar/77.webp', true),
                88 => new MemberPublicAppearance($publicIds[88], '/storage/avatar/88.webp', false),
            ]);

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
        $this->setProperty($controller, 'memberActions', $actions);
        $this->setProperty($controller, 'memberAppearances', $appearances);
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
        self::assertSame('/storage/avatar/77.webp', $result['article']['author_avatar_url']);
        self::assertTrue($result['article']['author_is_super']);

        self::assertSame($publicIds[88], $result['comments'][0]['author_public_id']);
        self::assertSame([$action], $result['comments'][0]['author_actions']);
        self::assertSame('/storage/avatar/88.webp', $result['comments'][0]['author_avatar_url']);
        self::assertFalse($result['comments'][0]['author_is_super']);
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
