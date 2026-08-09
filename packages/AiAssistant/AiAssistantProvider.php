<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant;

use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\AiAssistant\Controller\Api\AuthController;
use Mublo\Packages\AiAssistant\Controller\Api\AnalysisController;
use Mublo\Packages\AiAssistant\Controller\Api\CustomerSyncController;
use Mublo\Packages\AiAssistant\Controller\Api\DeviceController;
use Mublo\Packages\AiAssistant\Controller\Api\InteractionController;
use Mublo\Packages\AiAssistant\Controller\Api\MessagingPolicyController;
use Mublo\Packages\AiAssistant\Controller\Api\WorkerController;
use Mublo\Packages\AiAssistant\Controller\Admin\DashboardController;
use Mublo\Packages\AiAssistant\Repository\AuthTokenRepository;
use Mublo\Packages\AiAssistant\Repository\AnalysisRepository;
use Mublo\Packages\AiAssistant\Repository\AnalysisWorkerRepository;
use Mublo\Packages\AiAssistant\Repository\CompanyUserRepository;
use Mublo\Packages\AiAssistant\Repository\CustomerDirectoryRepository;
use Mublo\Packages\AiAssistant\Repository\DeviceRepository;
use Mublo\Packages\AiAssistant\Repository\IdempotencyRepository;
use Mublo\Packages\AiAssistant\Repository\InteractionRepository;
use Mublo\Packages\AiAssistant\Repository\MessagingPolicyRepository;
use Mublo\Packages\AiAssistant\Repository\MessagingDispatchRepository;
use Mublo\Packages\AiAssistant\Repository\SyncRecordRepository;
use Mublo\Packages\AiAssistant\Repository\SaasRepository;
use Mublo\Packages\AiAssistant\Security\WorkerSecurity;
use Mublo\Packages\AiAssistant\Security\WorkerV2Security;
use Mublo\Packages\AiAssistant\Service\AuthService;
use Mublo\Packages\AiAssistant\Service\AnalysisService;
use Mublo\Packages\AiAssistant\Service\AnalysisWorkerService;
use Mublo\Packages\AiAssistant\Service\CompanyProvisioningService;
use Mublo\Packages\AiAssistant\Service\CustomerSyncService;
use Mublo\Packages\AiAssistant\Service\DeviceService;
use Mublo\Packages\AiAssistant\Service\IdempotencyService;
use Mublo\Packages\AiAssistant\Service\InteractionService;
use Mublo\Packages\AiAssistant\Service\MessagingPolicyService;
use Mublo\Packages\AiAssistant\Service\WorkerJobService;
use Mublo\Packages\AiAssistant\Service\SaasService;

final class AiAssistantProvider implements ExtensionProviderInterface
{
    public function register(DependencyContainer $container): void
    {
        $container->singleton(CompanyUserRepository::class, fn(DependencyContainer $c) =>
            new CompanyUserRepository($c->get(Database::class))
        );
        $container->singleton(AuthTokenRepository::class, fn(DependencyContainer $c) =>
            new AuthTokenRepository($c->get(Database::class))
        );
        $container->singleton(DeviceRepository::class, fn(DependencyContainer $c) =>
            new DeviceRepository($c->get(Database::class))
        );
        $container->singleton(IdempotencyRepository::class, fn(DependencyContainer $c) =>
            new IdempotencyRepository($c->get(Database::class))
        );
        $container->singleton(SyncRecordRepository::class, fn(DependencyContainer $c) =>
            new SyncRecordRepository(
                $c->get(Database::class),
                $c->get(SensitiveValueCodecInterface::class)
            )
        );
        $container->singleton(CustomerDirectoryRepository::class, fn(DependencyContainer $c) =>
            new CustomerDirectoryRepository(
                $c->get(Database::class),
                $c->get(SensitiveValueCodecInterface::class)
            )
        );
        $container->singleton(MessagingPolicyRepository::class, fn(DependencyContainer $c) =>
            new MessagingPolicyRepository($c->get(Database::class))
        );
        $container->singleton(MessagingDispatchRepository::class, fn(DependencyContainer $c) =>
            new MessagingDispatchRepository($c->get(Database::class))
        );
        $container->singleton(InteractionRepository::class, fn(DependencyContainer $c) =>
            new InteractionRepository($c->get(Database::class))
        );
        $container->singleton(AnalysisRepository::class, fn(DependencyContainer $c) =>
            new AnalysisRepository($c->get(Database::class))
        );
        $container->singleton(AnalysisWorkerRepository::class, fn(DependencyContainer $c) =>
            new AnalysisWorkerRepository($c->get(Database::class))
        );
        $container->singleton(SaasRepository::class, fn(DependencyContainer $c) =>
            new SaasRepository($c->get(Database::class), $c->get(SensitiveValueCodecInterface::class))
        );
        $container->singleton(WorkerSecurity::class, function (): WorkerSecurity {
            $publicKey = str_replace('\\n', "\n", (string) env('MUBLO_AI_WORKER_PUBLIC_KEY', ''));
            $publicKeyFile = (string) env('MUBLO_AI_WORKER_PUBLIC_KEY_FILE', '');
            if ($publicKey === '' && $publicKeyFile !== '' && is_file($publicKeyFile)) {
                $loaded = file_get_contents($publicKeyFile);
                $publicKey = is_string($loaded) ? $loaded : '';
            }
            return new WorkerSecurity(
                (string) env('MUBLO_AI_WORKER_KEY_ID', ''),
                $publicKey,
                (string) env('MUBLO_AI_WORKER_TOKEN', ''),
                (string) env('MUBLO_AI_WORKER_SIGNING_SECRET', '')
            );
        });
        $container->singleton(WorkerV2Security::class, fn(): WorkerV2Security => new WorkerV2Security(
            (string) env('MUBLO_AI_WORKER_SIGNING_KEY_ID', ''),
            (string) env('MUBLO_AI_WORKER_SIGNING_PUBLIC_KEY', '')
        ));

        $container->singleton(AuthService::class, fn(DependencyContainer $c) =>
            new AuthService(
                $c->get(CompanyUserRepository::class),
                $c->get(AuthTokenRepository::class)
            )
        );
        $container->singleton(CompanyProvisioningService::class, fn(DependencyContainer $c) =>
            new CompanyProvisioningService($c->get(CompanyUserRepository::class))
        );
        $container->singleton(DeviceService::class, fn(DependencyContainer $c) =>
            new DeviceService($c->get(DeviceRepository::class))
        );
        $container->singleton(IdempotencyService::class, fn(DependencyContainer $c) =>
            new IdempotencyService($c->get(IdempotencyRepository::class))
        );
        $container->singleton(CustomerSyncService::class, fn(DependencyContainer $c) =>
            new CustomerSyncService(
                $c->get(SyncRecordRepository::class),
                $c->get(DeviceRepository::class),
                $c->get(CustomerDirectoryRepository::class)
            )
        );
        $container->singleton(InteractionService::class, fn(DependencyContainer $c) =>
            new InteractionService(
                $c->get(InteractionRepository::class),
                $c->get(DeviceRepository::class),
                $c->get(CustomerDirectoryRepository::class),
                $c->get(WorkerSecurity::class)
            )
        );
        $container->singleton(AnalysisService::class, fn(DependencyContainer $c) =>
            new AnalysisService(
                $c->get(AnalysisRepository::class),
                $c->get(InteractionRepository::class),
                $c->get(DeviceRepository::class),
                $c->get(CustomerDirectoryRepository::class)
            )
        );
        $container->singleton(MessagingPolicyService::class, fn(DependencyContainer $c) =>
            new MessagingPolicyService(
                $c->get(CustomerDirectoryRepository::class),
                $c->get(MessagingPolicyRepository::class),
                $c->get(MessagingDispatchRepository::class)
            )
        );
        $container->singleton(WorkerJobService::class, fn(DependencyContainer $c) =>
            new WorkerJobService($c->get(InteractionRepository::class), $c->get(WorkerSecurity::class))
        );
        $container->singleton(AnalysisWorkerService::class, fn(DependencyContainer $c) =>
            new AnalysisWorkerService(
                $c->get(AnalysisWorkerRepository::class),
                $c->get(WorkerSecurity::class),
                $c->get(WorkerV2Security::class)
            )
        );
        $container->singleton(SaasService::class, fn(DependencyContainer $c) =>
            new SaasService($c->get(SaasRepository::class))
        );

        $container->singleton(AuthController::class, fn(DependencyContainer $c) =>
            new AuthController($c->get(AuthService::class))
        );
        $container->singleton(AnalysisController::class, fn(DependencyContainer $c) =>
            new AnalysisController(
                $c->get(AuthService::class),
                $c->get(AnalysisService::class),
                $c->get(IdempotencyService::class)
            )
        );
        $container->singleton(DeviceController::class, fn(DependencyContainer $c) =>
            new DeviceController(
                $c->get(AuthService::class),
                $c->get(DeviceService::class),
                $c->get(IdempotencyService::class)
            )
        );
        $container->singleton(CustomerSyncController::class, fn(DependencyContainer $c) =>
            new CustomerSyncController(
                $c->get(AuthService::class),
                $c->get(CustomerSyncService::class),
                $c->get(IdempotencyService::class)
            )
        );
        $container->singleton(InteractionController::class, fn(DependencyContainer $c) =>
            new InteractionController(
                $c->get(AuthService::class),
                $c->get(InteractionService::class),
                $c->get(IdempotencyService::class)
            )
        );
        $container->singleton(MessagingPolicyController::class, fn(DependencyContainer $c) =>
            new MessagingPolicyController(
                $c->get(AuthService::class),
                $c->get(MessagingPolicyService::class),
                $c->get(IdempotencyService::class)
            )
        );
        $container->singleton(WorkerController::class, fn(DependencyContainer $c) =>
            new WorkerController(
                $c->get(AuthService::class),
                $c->get(WorkerJobService::class),
                $c->get(AnalysisWorkerService::class)
            )
        );
        $container->singleton(DashboardController::class, fn(DependencyContainer $c) =>
            new DashboardController(
                $c->get(SaasService::class),
                $c->get(AuthContextInterface::class)
            )
        );
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
    }
}
