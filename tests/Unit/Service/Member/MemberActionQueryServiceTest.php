<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Member;

use Mublo\Contract\Member\MemberActionDefinition;
use Mublo\Contract\Member\MemberActionScope;
use Mublo\Contract\Member\MemberActionStateResolverInterface;
use Mublo\Contract\Member\MemberActionStateScope;
use Mublo\Contract\Member\MemberActionTargetTransport;
use Mublo\Contract\Member\MemberActionVariant;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Member\MemberActionBuildingEvent;
use Mublo\Service\Member\MemberActionQueryService;
use Mublo\Service\Member\MemberActionRegistry;
use PHPUnit\Framework\TestCase;

final class MemberActionQueryServiceTest extends TestCase
{
    public function testStaticActionsAreValidatedFilteredAndSorted(): void
    {
        $events = new EventDispatcher();
        $events->addListener(MemberActionBuildingEvent::class, static function (MemberActionBuildingEvent $event): void {
            $event->setSource('plugin', 'Example')
                ->register(new MemberActionDefinition(
                    key: 'later',
                    label: '나중 액션',
                    endpoint: '/example/later',
                    priority: 200,
                    placements: ['member.author'],
                    requiresLogin: false,
                    allowSelf: true,
                ))
                ->register(new MemberActionDefinition(
                    key: 'first',
                    label: '먼저 액션',
                    endpoint: '/profile',
                    priority: 10,
                    placements: ['board.comment_author'],
                    targetTransport: MemberActionTargetTransport::PublicPath,
                ))
                ->register(new MemberActionDefinition(
                    key: 'bad',
                    label: '잘못된 액션',
                    endpoint: '/profile?member=forbidden',
                ));
        });

        $registry = new MemberActionRegistry($events);
        $query = new MemberActionQueryService($registry, static fn (): int => 1);
        $scope = new MemberActionScope(1, 5, false, 10, ['board.comment_author', 'member.author']);

        $actions = $query->forMember($scope, 8);

        self::assertSame(['plugin:Example:first', 'plugin:Example:later'], array_map(
            static fn ($action): string => $action->getId(),
            $actions
        ));
        self::assertCount(1, $registry->diagnostics(1));
        self::assertSame([], $query->forMember($scope, 0));

        $foreign = new MemberActionScope(2, 5, false, 10, ['member.author']);
        self::assertSame([], $query->forMember($foreign, 8));
    }

    public function testStateResolverReceivesOnlyNewMembersAndSelectsVariants(): void
    {
        $resolver = new class implements MemberActionStateResolverInterface {
            /** @var list<list<int>> */
            public array $calls = [];

            public function resolve(MemberActionStateScope $scope, array $targetMemberIds): array
            {
                $this->calls[] = $targetMemberIds;
                $states = [];
                foreach ($targetMemberIds as $targetMemberId) {
                    $states[$targetMemberId] = $targetMemberId === 11
                        ? MemberActionVariant::HIDDEN
                        : 'following';
                }
                return ['follow' => $states];
            }
        };

        $events = new EventDispatcher();
        $events->addListener(MemberActionBuildingEvent::class, static function (MemberActionBuildingEvent $event) use ($resolver): void {
            $event->setSource('plugin', 'Follow')
                ->register(new MemberActionDefinition(
                    key: 'follow',
                    label: '팔로우',
                    endpoint: '/follow/confirm',
                    placements: ['member.author'],
                    stateful: true,
                    variants: [
                        'following' => new MemberActionVariant('팔로우 취소', '/follow/confirm-unfollow'),
                    ],
                ))
                ->registerStateResolver($resolver);
        });

        $query = new MemberActionQueryService(new MemberActionRegistry($events), static fn (): int => 1);
        $scope = new MemberActionScope(1, 5, false, 1, ['board.article_author', 'member.author']);

        self::assertSame('팔로우 취소', $query->forMember($scope, 10)[0]->getLabel());
        $second = $query->forMembers($scope, [10, 11, 12]);

        self::assertSame([[10], [11, 12]], $resolver->calls);
        self::assertSame([], $second[11]);
        self::assertSame('팔로우 취소', $second[12][0]->getLabel());
    }

    public function testMissingResolverUsesEachDefinitionsFailurePolicy(): void
    {
        $events = new EventDispatcher();
        $events->addListener(MemberActionBuildingEvent::class, static function (MemberActionBuildingEvent $event): void {
            $event->setSource('package', 'Shop')
                ->register(new MemberActionDefinition(
                    key: 'store',
                    label: '판매자 상점',
                    endpoint: '/shop/seller',
                    stateful: true,
                    onResolveFailure: MemberActionVariant::HIDDEN,
                    targetTransport: MemberActionTargetTransport::PublicPath,
                ));
        });

        $registry = new MemberActionRegistry($events);
        $query = new MemberActionQueryService($registry, static fn (): int => 1);
        $scope = new MemberActionScope(1, 5, false, 1, ['member.author']);

        self::assertSame([], $query->forMember($scope, 10));
        self::assertSame('missing state resolver', $registry->diagnostics(1)[0]['reason']);
    }
}
