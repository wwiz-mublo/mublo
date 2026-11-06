<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay;

use Mublo\Contract\Payment\PaymentGatewayInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Crypto\EncryptionService;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\InstallableExtensionInterface;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Middleware\CsrfMiddleware;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\Innopay\Repository\InnopayAttemptRepository;
use Mublo\Plugin\Innopay\Repository\InnopayCancelRepository;
use Mublo\Plugin\Innopay\Repository\InnopayConfigRepository;
use Mublo\Plugin\Innopay\Repository\InnopayLogRepository;
use Mublo\Plugin\Innopay\Service\InnopayConfigService;
use Mublo\Plugin\Innopay\Service\InnopayLogger;
use Mublo\Plugin\Innopay\Service\InnopayNotiService;
use Mublo\Plugin\Innopay\Service\InnopayReturnService;

class InnopayProvider implements ExtensionProviderInterface, InstallableExtensionInterface
{
    public function install(DependencyContainer $container, Context $context): void
    {
        $container->get(MigrationRunner::class)
            ->run('plugin', 'Innopay', MUBLO_PLUGIN_PATH . '/Innopay/database/migrations');
    }

    public function uninstall(DependencyContainer $container, Context $context): void
    {
        // 설정·결제 기록 유지 (결제 로그는 분쟁 대응 자료라 지우지 않는다)
    }

    public function register(DependencyContainer $container): void
    {
        $container->singleton(InnopayConfigRepository::class, fn($c) => new InnopayConfigRepository($c->get(Database::class)));
        $container->singleton(InnopayAttemptRepository::class, fn($c) => new InnopayAttemptRepository($c->get(Database::class)));
        $container->singleton(InnopayLogRepository::class, fn($c) => new InnopayLogRepository($c->get(Database::class)));

        $container->singleton(InnopayConfigService::class, fn($c) => new InnopayConfigService(
            $c->get(InnopayConfigRepository::class),
            $c->get(EncryptionService::class)
        ));

        $container->singleton(InnopayLogger::class, fn($c) => new InnopayLogger($c->get(InnopayLogRepository::class)));

        $container->singleton(InnopayCancelRepository::class, fn($c) => new InnopayCancelRepository($c->get(Database::class)));

        $container->singleton(InnopayReturnService::class, fn($c) => new InnopayReturnService(
            $c->get(InnopayConfigService::class),
            $c->get(InnopayAttemptRepository::class),
            $c->get(InnopayLogger::class),
            $c->get(ContractRegistry::class),
            fn(int $domainId) => self::makeGateway($c, $domainId)
        ));

        $container->singleton(InnopayNotiService::class, fn($c) => new InnopayNotiService(
            $c->get(InnopayConfigService::class),
            $c->get(InnopayAttemptRepository::class),
            $c->get(InnopayCancelRepository::class),
            $c->get(InnopayLogger::class),
            $c->get(InnopayReturnService::class),
            fn(int $domainId) => self::makeGateway($c, $domainId)
        ));
    }

    private static function makeGateway(DependencyContainer $c, int $domainId): InnopayGateway
    {
        return new InnopayGateway(
            $c->get(InnopayConfigService::class),
            $c->get(InnopayAttemptRepository::class),
            $c->get(InnopayLogger::class),
            $c->get(InnopayCancelRepository::class),
            $domainId
        );
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        // 결제창 복귀(GET)·브라우저 로그(POST)·결제결과 통보(서버→서버)는 CSRF 토큰 없이 들어온다
        if ($container->has(CsrfMiddleware::class)) {
            $container->get(CsrfMiddleware::class)->addExcludePath('/innopay/callback/');
        }

        $container->get(EventDispatcher::class)->addSubscriber(new AdminMenuSubscriber());

        try {
            $registry = $container->get(ContractRegistry::class);
            $domainId = $context->getDomainId() ?? 1;

            $registry->register(
                PaymentGatewayInterface::class,
                InnopayGateway::PG_KEY,
                fn() => self::makeGateway($container, $domainId),
                [
                    'label'       => '이노페이',
                    'icon'        => 'bi-credit-card-2-front',
                    'description' => '신용카드/간편결제/계좌이체',
                ]
            );
        } catch (\Throwable $e) {
            error_log('[Innopay] boot registration FAILED: ' . $e->getMessage());
        }
    }
}
