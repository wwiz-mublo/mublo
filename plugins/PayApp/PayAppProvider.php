<?php

namespace Mublo\Plugin\PayApp;

use Mublo\Contract\Payment\PaymentGatewayInterface;
use Mublo\Core\Context\Context;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\InstallableExtensionInterface;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\PayApp\Repository\PayAppConfigRepository;
use Mublo\Plugin\PayApp\Service\PayAppConfigService;

class PayAppProvider implements ExtensionProviderInterface, InstallableExtensionInterface
{
    public function install(DependencyContainer $container, Context $context): void
    {
        $runner = $container->get(MigrationRunner::class);
        $runner->run('plugin', 'PayApp', MUBLO_PLUGIN_PATH . '/PayApp/database/migrations');
    }

    public function uninstall(DependencyContainer $container, Context $context): void
    {
        // 설정 데이터 유지 (재활성화 시 복원)
    }

    public function register(DependencyContainer $container): void
    {
        $container->singleton(PayAppConfigRepository::class, fn($c) =>
            new PayAppConfigRepository($c->get(Database::class))
        );

        $container->singleton(PayAppConfigService::class, fn($c) =>
            new PayAppConfigService(
                $c->get(PayAppConfigRepository::class),
                $c->get(\Mublo\Core\Crypto\EncryptionService::class)
            )
        );
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        // PG 콜백 CSRF 예외 등록
        if ($container->has(\Mublo\Core\Middleware\CsrfMiddleware::class)) {
            $container->get(\Mublo\Core\Middleware\CsrfMiddleware::class)
                ->addExcludePath('/pay-app/callback/');
        }

        $eventDispatcher = $container->get(EventDispatcher::class);
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());

        try {
            $registry = $container->get(ContractRegistry::class);
            $configService = $container->get(PayAppConfigService::class);
            $domainId = $context->getDomainId() ?? 1;

            $registry->register(
                PaymentGatewayInterface::class,
                'payapp',
                fn() => new PayAppGateway($configService, $domainId),
                [
                    'label'       => '페이앱',
                    'icon'        => 'bi-credit-card',
                    'description' => '카드/휴대폰/카카오페이/네이버페이/가상계좌',
                ]
            );
        } catch (\Throwable $e) {
            error_log('[PayApp] boot registration FAILED: ' . $e->getMessage());
        }
    }
}
