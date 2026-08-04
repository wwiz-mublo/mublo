<?php
declare(strict_types=1);

namespace Mublo\Plugin\SendonSms;

use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Contract\Notification\NotificationGatewayInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\SendonSms\Controller\Admin\SendonSmsController;
use Mublo\Plugin\SendonSms\Repository\SendonSmsChannelRepository;
use Mublo\Plugin\SendonSms\Repository\SendonSmsConfigRepository;
use Mublo\Plugin\SendonSms\Repository\SendonSmsLogRepository;
use Mublo\Plugin\SendonSms\Repository\SendonSmsTemplateRepository;
use Mublo\Plugin\SendonSms\Service\SendonSmsService;
use Mublo\Plugin\SendonSms\Service\SendonSmsDataResetter;
use Mublo\Contract\Security\SensitiveValueCodecInterface;

class SendonSmsProvider implements ExtensionProviderInterface, DataResettableInterface
{
    private SendonSmsDataResetter $dataResetter;

    public function register(DependencyContainer $container): void
    {
        $container->singleton(SendonSmsDataResetter::class, fn(DependencyContainer $c) => new SendonSmsDataResetter($c->get(Database::class)));
        $container->singleton(SendonSmsConfigRepository::class, fn(DependencyContainer $c) =>
            new SendonSmsConfigRepository($c->get(Database::class))
        );

        $container->singleton(SendonSmsChannelRepository::class, fn(DependencyContainer $c) =>
            new SendonSmsChannelRepository($c->get(Database::class))
        );

        $container->singleton(SendonSmsTemplateRepository::class, fn(DependencyContainer $c) =>
            new SendonSmsTemplateRepository($c->get(Database::class))
        );

        $container->singleton(SendonSmsLogRepository::class, fn(DependencyContainer $c) =>
            new SendonSmsLogRepository($c->get(Database::class))
        );

        $container->singleton(SendonSmsService::class, function (DependencyContainer $c) {
            $encryptionService = null;
            try {
                $encryptionService = $c->get(SensitiveValueCodecInterface::class);
            } catch (\Throwable $e) {
            }

            return new SendonSmsService(
                $c->get(SendonSmsConfigRepository::class),
                $c->get(SendonSmsChannelRepository::class),
                $c->get(SendonSmsTemplateRepository::class),
                $c->get(SendonSmsLogRepository::class),
                $encryptionService
            );
        });

        $container->singleton(SendonSmsController::class, fn(DependencyContainer $c) =>
            new SendonSmsController(
                $c->get(SendonSmsService::class),
                $c->get(MigrationRunner::class),
                $c->get(EventDispatcher::class)
            )
        );
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $this->dataResetter = $container->get(SendonSmsDataResetter::class);
        $eventDispatcher = $container->get(EventDispatcher::class);
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());

        $registry = $container->get(ContractRegistry::class);
        $registry->register(
            NotificationGatewayInterface::class,
            'sendon_sms',
            fn() => new SendonSmsGateway(
                $container->get(SendonSmsService::class),
                $container->get(SendonSmsChannelRepository::class),
                $container->get(SendonSmsTemplateRepository::class),
                $context->getDomainId()
            ),
            [
                'label' => '센드온 SMS',
                'icon' => 'bi-chat-square-text',
                'description' => 'SMS/LMS/MMS 발송 (센드온)',
                'channels' => ['sms'],
            ]
        );
    }

    public function getResetCategories(): array { return $this->dataResetter->getResetCategories(); }
    public function reset(string $category, int $domainId): DataResetResult { return $this->dataResetter->reset($category, $domainId); }
}
