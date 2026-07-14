<?php

namespace Mublo\Plugin\SendonTalk;

use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Contract\Notification\NotificationGatewayInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\SendonTalk\Controller\Admin\SendonTalkController;
use Mublo\Plugin\SendonTalk\Repository\SendonTalkConfigRepository;
use Mublo\Plugin\SendonTalk\Repository\SendonTalkChannelRepository;
use Mublo\Plugin\SendonTalk\Repository\SendonTalkTemplateRepository;
use Mublo\Plugin\SendonTalk\Repository\SendonTalkLogRepository;
use Mublo\Plugin\SendonTalk\Service\SendonTalkService;
use Mublo\Plugin\SendonTalk\Service\SendonTalkDataResetter;

class SendonTalkProvider implements ExtensionProviderInterface, DataResettableInterface
{
    private SendonTalkDataResetter $dataResetter;

    public function register(DependencyContainer $container): void
    {
        $container->singleton(SendonTalkDataResetter::class, fn(DependencyContainer $c) => new SendonTalkDataResetter($c->get(Database::class)));
        // Repositories
        $container->singleton(SendonTalkConfigRepository::class, fn(DependencyContainer $c) =>
            new SendonTalkConfigRepository($c->get(Database::class))
        );
        $container->singleton(SendonTalkChannelRepository::class, fn(DependencyContainer $c) =>
            new SendonTalkChannelRepository($c->get(Database::class))
        );
        $container->singleton(SendonTalkTemplateRepository::class, fn(DependencyContainer $c) =>
            new SendonTalkTemplateRepository($c->get(Database::class))
        );
        $container->singleton(SendonTalkLogRepository::class, fn(DependencyContainer $c) =>
            new SendonTalkLogRepository($c->get(Database::class))
        );

        // Service
        $container->singleton(SendonTalkService::class, function (DependencyContainer $c) {
            $encryption = null;
            try { $encryption = $c->get(\Mublo\Service\Member\FieldEncryptionService::class); } catch (\Throwable) {}

            return new SendonTalkService(
                $c->get(SendonTalkConfigRepository::class),
                $c->get(SendonTalkChannelRepository::class),
                $c->get(SendonTalkTemplateRepository::class),
                $c->get(SendonTalkLogRepository::class),
                $encryption
            );
        });

        // Controller
        $container->singleton(SendonTalkController::class, fn(DependencyContainer $c) =>
            new SendonTalkController(
                $c->get(SendonTalkService::class),
                $c->get(MigrationRunner::class),
                $c->get(EventDispatcher::class),
                $c->get(\Mublo\Contract\Notification\NotificationTemplateContextInterface::class)
            )
        );
    }

    public function boot(DependencyContainer $container, \Mublo\Core\Context\Context $context): void
    {
        $this->dataResetter = $container->get(SendonTalkDataResetter::class);
        $eventDispatcher = $container->get(EventDispatcher::class);
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());

        // Notification Gateway 등록
        // register(contract, key:string, impl:object|Closure, meta) — Closure는 get() 시점 lazy 생성
        $registry = $container->get(ContractRegistry::class);
        $registry->register(
            NotificationGatewayInterface::class,
            'sendon_talk',
            fn() => new SendonTalkGateway(
                $container->get(SendonTalkService::class),
                $container->get(SendonTalkChannelRepository::class),
                $container->get(SendonTalkTemplateRepository::class),
                $context->getDomainId()
            ),
            [
                'label' => '센드온 알림톡',
                'icon' => 'bi-chat-dots',
                'description' => '카카오 알림톡 발송 (센드온)',
                'channels' => ['alimtalk'],
            ]
        );
    }

    public function getResetCategories(): array { return $this->dataResetter->getResetCategories(); }
    public function reset(string $category, int $domainId): DataResetResult { return $this->dataResetter->reset($category, $domainId); }
}
