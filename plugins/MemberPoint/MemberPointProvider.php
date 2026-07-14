<?php
namespace Mublo\Plugin\MemberPoint;

use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Infrastructure\Database\Database;
use Mublo\Contract\Balance\BalanceGatewayInterface;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Plugin\MemberPoint\Repository\MemberPointConfigRepository;
use Mublo\Plugin\MemberPoint\Service\MemberPointConfigService;
use Mublo\Plugin\MemberPoint\Service\MemberPointService;
use Mublo\Plugin\MemberPoint\Service\MemberPointDataResetter;
use Mublo\Plugin\MemberPoint\Subscriber\MemberEventSubscriber;

class MemberPointProvider implements ExtensionProviderInterface, DataResettableInterface
{
    private MemberPointDataResetter $dataResetter;

    public function __construct(?MemberPointDataResetter $dataResetter = null)
    {
        if ($dataResetter !== null) {
            $this->dataResetter = $dataResetter;
        }
    }

    public function register(DependencyContainer $container): void
    {
        $container->singleton(MemberPointDataResetter::class, fn($c) =>
            new MemberPointDataResetter($c->get(\Mublo\Contract\Balance\BalanceResetGatewayInterface::class))
        );
        $container->singleton(MemberPointConfigRepository::class, fn($c) =>
            new MemberPointConfigRepository($c->get(Database::class))
        );

        $container->singleton(MemberPointConfigService::class, fn($c) =>
            new MemberPointConfigService($c->get(MemberPointConfigRepository::class))
        );

        $container->singleton(MemberPointService::class, fn($c) =>
            new MemberPointService(
                $c->get(BalanceGatewayInterface::class),
                $c->get(MemberPointConfigService::class),
            )
        );
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $this->dataResetter = $container->get(MemberPointDataResetter::class);
        $eventDispatcher = $container->get(EventDispatcher::class);

        // 관리자 메뉴 (DB 접근 불필요, 항상 등록)
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());

        $pointService = $container->get(MemberPointService::class);
        $members = $container->get(MemberQueryInterface::class);
        $eventDispatcher->addSubscriber(new MemberEventSubscriber($pointService, $members));
    }

    public function getResetCategories(): array
    {
        return $this->dataResetter->getResetCategories();
    }

    public function reset(string $category, int $domainId): DataResetResult
    {
        return $this->dataResetter->reset($category, $domainId);
    }
}
