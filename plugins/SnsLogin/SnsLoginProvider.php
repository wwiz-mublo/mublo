<?php
declare(strict_types=1);
namespace Mublo\Plugin\SnsLogin;

use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\InstallableExtensionInterface;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Session\SessionInterface;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Plugin\SnsLogin\Controller\Admin\SettingsController;
use Mublo\Plugin\SnsLogin\Controller\Admin\AccountsController;
use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Mublo\Plugin\SnsLogin\Controller\Front\SnsAuthController;
use Mublo\Plugin\SnsLogin\Controller\Front\SnsProfileController;
use Mublo\Plugin\SnsLogin\Provider\GoogleProvider;
use Mublo\Plugin\SnsLogin\Provider\KakaoProvider;
use Mublo\Plugin\SnsLogin\Provider\NaverProvider;
use Mublo\Plugin\SnsLogin\Repository\SnsAccountRepository;
use Mublo\Plugin\SnsLogin\Repository\SnsLoginConfigRepository;
use Mublo\Plugin\SnsLogin\Service\SnsLoginConfigService;
use Mublo\Plugin\SnsLogin\Service\KoreanNicknameGenerator;
use Mublo\Plugin\SnsLogin\Service\SnsLoginService;
use Mublo\Plugin\SnsLogin\Service\SnsLoginDataResetter;
use Mublo\Plugin\SnsLogin\Service\SnsConnectionManager;
use Mublo\Plugin\SnsLogin\Subscriber\LoginFormSubscriber;
use Mublo\Plugin\SnsLogin\Subscriber\MemberLifecycleSubscriber;
use Mublo\Contract\Member\MemberAccountGatewayInterface;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Contract\Auth\MemberAuthenticatorInterface;

class SnsLoginProvider implements ExtensionProviderInterface, InstallableExtensionInterface, DataResettableInterface
{
    private SnsLoginDataResetter $dataResetter;

    /**
     * 첫 활성화 시: 마이그레이션 자동 실행
     */
    public function install(DependencyContainer $container, Context $context): void
    {
        $runner = $container->get(MigrationRunner::class);
        $runner->run('plugin', 'SnsLogin', MUBLO_PLUGIN_PATH . '/SnsLogin/database/migrations');
    }

    /**
     * 비활성화 시: 데이터 보존 (테이블/설정 삭제 안 함)
     */
    public function uninstall(DependencyContainer $container, Context $context): void
    {
        // SNS 계정 연동 데이터는 회원 데이터이므로 비활성화 시 삭제하지 않음
    }

    public function register(DependencyContainer $container): void
    {
        $container->singleton(SnsLoginDataResetter::class, fn($c) =>
            new SnsLoginDataResetter($c->get(Database::class))
        );
        $container->singleton(SnsLoginConfigRepository::class, fn($c) =>
            new SnsLoginConfigRepository($c->get(Database::class))
        );

        $container->singleton(SnsLoginConfigService::class, fn($c) =>
            new SnsLoginConfigService(
                $c->get(SnsLoginConfigRepository::class),
                $c->get(SensitiveValueCodecInterface::class),
            )
        );

        $container->singleton(SnsAccountRepository::class, fn($c) =>
            new SnsAccountRepository(
                $c->get(Database::class),
                $c->get(SensitiveValueCodecInterface::class),
            )
        );

        $container->singleton(SnsProviderRegistry::class, fn() => new SnsProviderRegistry());

        $container->singleton(KoreanNicknameGenerator::class, fn() => new KoreanNicknameGenerator());

        $container->singleton(SnsConnectionManager::class, fn($c) =>
            new SnsConnectionManager(
                $c->get(SnsAccountRepository::class),
                $c->get(SnsProviderRegistry::class),
                $c->get(Logger::class)->channel('sns-login'),
            )
        );

        $container->singleton(SnsLoginService::class, fn($c) =>
            new SnsLoginService(
                $c->get(SnsAccountRepository::class),
                $c->get(MemberAccountGatewayInterface::class),
                $c->get(MemberQueryInterface::class),
                $c->get(Database::class),
                $c->get(MemberAuthenticatorInterface::class),
                $c->get(SnsLoginConfigService::class),
                $c->get(SessionInterface::class),
                $c->get(KoreanNicknameGenerator::class),
                $c->get(SnsConnectionManager::class),
            )
        );

        $container->singleton(SnsAuthController::class, fn($c) =>
            new SnsAuthController(
                $c->get(SnsProviderRegistry::class),
                $c->get(SnsLoginService::class),
                $c->get(SnsLoginConfigService::class),
                $c->get(SessionInterface::class),
                $c->get(Logger::class)->channel('sns-login'),
                $c->get(AuthContextInterface::class),
            )
        );

        $container->singleton(SnsProfileController::class, fn($c) =>
            new SnsProfileController(
                $c->get(SnsLoginService::class),
                $c->get(MemberAccountGatewayInterface::class),
                $c->get(MemberAuthenticatorInterface::class),
            )
        );

        $container->singleton(SettingsController::class, fn($c) =>
            new SettingsController(
                $c->get(SnsLoginConfigService::class),
                $c->get(MigrationRunner::class),
                $c->get(EventDispatcher::class),
            )
        );

        $container->singleton(AccountsController::class, fn($c) =>
            new AccountsController(
                $c->get(SnsAccountRepository::class),
                $c->get(SnsConnectionManager::class),
            )
        );
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $this->dataResetter = $container->get(SnsLoginDataResetter::class);
        $registry        = $container->get(SnsProviderRegistry::class);
        $eventDispatcher = $container->get(EventDispatcher::class);

        // 구독자 등록은 항상 먼저 (DB 접근 전) — 설치 전에도 관리자 메뉴가 보여야 함
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());
        $eventDispatcher->addSubscriber(new LoginFormSubscriber($registry));
        $eventDispatcher->addSubscriber(new MemberLifecycleSubscriber(
            $container->get(SnsConnectionManager::class),
            $container->get(Logger::class)->channel('sns-login'),
        ));

        // DB 접근이 필요한 설정 로드 — 테이블 미설치 시 예외를 잡아 무시
        try {
            $domainId      = $context->getDomainId() ?? 1;
            $configService = $container->get(SnsLoginConfigService::class);

            $enabledMap = $configService->getEnabledMap($domainId);
            $registry->setEnabled($enabledMap);

            $snsLogger = $container->get(Logger::class)->channel('sns-login');
            $this->registerProviders($registry, $configService, $domainId, $context, $snsLogger);
        } catch (\Throwable $e) {
            // 마이그레이션 미실행 상태에서도 관리자 메뉴는 동작해야 함
            error_log('[SnsLogin] boot config load failed (migration needed?): ' . $e->getMessage());
        }
    }

    private function registerProviders(
        SnsProviderRegistry   $registry,
        SnsLoginConfigService $configService,
        int                   $domainId,
        Context               $context,
        ?Logger               $logger = null,
    ): void {
        $request  = $context->getRequest();
        $scheme   = $request->isHttps() ? 'https' : 'http';
        $host     = $request->getHost();
        $baseUrl  = "{$scheme}://{$host}";

        $providers = ['naver', 'kakao', 'google'];

        foreach ($providers as $name) {
            $cfg         = $configService->getProviderConfig($domainId, $name);
            $clientId    = $cfg['client_id'] ?? '';
            $callbackUrl = "{$baseUrl}/sns-login/callback/{$name}";

            // client_id 없으면 스킵
            if (empty($clientId)) {
                continue;
            }

            $provider = match ($name) {
                'naver'  => new NaverProvider($clientId, $cfg['client_secret'] ?? '', $callbackUrl),
                'kakao'  => new KakaoProvider(
                    $clientId,
                    $cfg['client_secret'] ?? '',
                    $cfg['admin_key'] ?? '',
                    $cfg['javascript_key'] ?? '',
                    $callbackUrl,
                    $logger,
                ),
                'google' => new GoogleProvider($clientId, $cfg['client_secret'] ?? '', $callbackUrl),
            };

            $registry->register($provider);
        }
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
