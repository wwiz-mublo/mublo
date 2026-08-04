<?php
declare(strict_types=1);

namespace Mublo\Core\Provider;

use Mublo\Core\ConfigFile;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\App\Router;
use Mublo\Core\App\Dispatcher;
use Mublo\Core\Rendering\AssetManager;
use Mublo\Core\Rendering\LayoutManager;
use Mublo\Core\Rendering\FrontViewRenderer;
use Mublo\Core\Rendering\AdminViewRenderer;
use Mublo\Core\Session\SessionInterface;
use Mublo\Infrastructure\Session\SessionManager;
use Mublo\Core\Cookie\CookieInterface;
use Mublo\Infrastructure\Cookie\CookieManager;

use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Infrastructure\Maintenance\DailyStorageCleanup;
use Mublo\Infrastructure\Cache\CacheInterface;
use Mublo\Infrastructure\Cache\CacheFactory;
use Mublo\Infrastructure\Cache\DomainCache;
use Mublo\Infrastructure\Security\RateLimiter;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Infrastructure\Storage\SecureFileService;
use Mublo\Contract\AI\AiGatewayInterface;
use Mublo\Contract\AI\AiAssetCatalogInterface;
use Mublo\Contract\Notification\MemberNotificationPublisherInterface;
use Mublo\Contract\Notification\NotificationTemplateContextInterface;
use Mublo\Service\AI\CoreAiGateway;
use Mublo\Service\AI\CoreAiAssetCatalog;
use Mublo\Service\Notification\MemberNotificationService;
use Mublo\Service\Notification\NotificationTemplateUiHelper;
use Mublo\Core\Env\Env;

use Mublo\Core\Registry\ContractRegistry;
use Mublo\Core\Registry\CategoryProviderRegistry;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Subscriber\MemberQuerySubscriber;
use Mublo\Core\Event\Domain\DomainEventSubscriber;
use Mublo\Service\Search\SearchService;
use Mublo\Service\Admin\AdminMenuService;
use Mublo\Service\Admin\AdminPermissionService;
use Mublo\Service\Auth\AuthService;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Contract\Auth\MemberAuthenticatorInterface;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Contract\Member\PolicyQueryInterface;
use Mublo\Contract\Site\ManagedSiteGatewayInterface;
use Mublo\Service\Auth\LoginAttemptService;
use Mublo\Service\Menu\MenuService;
use Mublo\Service\Block\BlockKitApplier;
use Mublo\Service\Block\BlockKitExporter;
use Mublo\Service\Block\BlockContentSanitizer;
use Mublo\Service\Block\BlockColumnPayloadNormalizer;
use Mublo\Service\Block\BlockSkinService;
use Mublo\Service\Block\BlockKitScreenshot;
use Mublo\Service\Block\BlockRenderService;
use Mublo\Service\Block\BlockImageProcessor;
use Mublo\Service\Block\MainScreenComposition;
use Mublo\Service\System\InstallIdProvider;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Service\Member\MemberQueryService;
use Mublo\Core\Middleware\AdminMiddleware;
use Mublo\Core\Middleware\AuthMiddleware;
use Mublo\Core\Middleware\CsrfMiddleware;
use Mublo\Core\Middleware\SecurityHeadersMiddleware;
use Mublo\Core\Middleware\SessionMiddleware;
use Mublo\Core\Context\ContextBuilder;
use Mublo\Infrastructure\Security\CsrfManager;
use Mublo\Repository\Balance\BalanceLogRepository;
use Mublo\Repository\Balance\BalanceRepairAuditRepository;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Repository\Member\AdminPermissionRepository;
use Mublo\Repository\Member\MemberLevelRepository;
use Mublo\Repository\Notification\MemberNotificationRepository;
use Mublo\Service\Balance\BalanceManager;
use Mublo\Service\Balance\BalanceResetManager;
use Mublo\Service\Domain\DomainResolver;
use Mublo\Infrastructure\Code\CodeGenerator;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionLoadDiagnostics;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Service\Migration\CoreMigrationService;
use Mublo\Core\Report\Audit\ReportAuditLogger;
use Mublo\Core\Report\Contract\ReportRendererInterface;
use Mublo\Core\Report\Engine\ReportDefinitionRegistry;
use Mublo\Core\Report\Engine\ReportManager;
use Mublo\Core\Report\Engine\ReportRendererResolver;
use Mublo\Core\Report\Renderer\CsvReportRenderer;
use Mublo\Core\Report\Renderer\PdfReportRenderer;
use Mublo\Core\Report\Renderer\XlsxReportRenderer;
use Mublo\Core\Report\Security\AdminPermissionGate;
use Mublo\Core\Report\Store\ReportFileStore;

use Mublo\Core\Dashboard\DashboardWidgetRegistry;
use Mublo\Core\Dashboard\DashboardLayoutManager;
use Mublo\Core\Dashboard\LayoutSanitizer;
use Mublo\Core\Dashboard\SlotGridArranger;
use Mublo\Core\Dashboard\Widget\SystemInfoWidget;
use Mublo\Core\Dashboard\Widget\MemberStatsWidget;
use Mublo\Repository\DashboardLayoutRepository;

use Mublo\Infrastructure\Mail\Mailer;

/**
 * CoreServiceProvider
 * Class ServiceProvider
 *
 * 프레임워크 코어 구성요소 등록
 *
 * 책임:
 * - Router / Dispatcher
 * - Rendering 관련 객체 조립
 * - Database 연결
 *
 * 금지:
 * - 로직
 * - 조건 분기
 * 애플리케이션의 주요 서비스와 의존성을 컨테이너에 등록(Binding)하는 역할
 */
class ServiceProvider
{
    /**
     * 컨테이너에 서비스 등록
     *
     * @param DependencyContainer $container
     */
    public function register(DependencyContainer $container): void
    {
        // ====================================
        // 1. Infrastructure (기반)
        // ====================================

        // ------------------------------------
        // Database
        // ------------------------------------
        $container->singleton(
            DatabaseManager::class,
            fn () => DatabaseManager::getInstance()->loadFromConfig()
        );

        $container->singleton(Database::class, function (DependencyContainer $c) {
            $db = $c->get(DatabaseManager::class)->connect();

            // Logger 연결 (슬로우 쿼리 로깅)
            if ($c->has(Logger::class)) {
                $db->setLogger($c->get(Logger::class));
            }

            // 슬로우 쿼리 임계값 설정 (.env에서 읽기, 기본 1.0초)
            $threshold = (float) Env::get('DB_SLOW_QUERY_THRESHOLD', '1.0');
            $db->setSlowQueryThreshold($threshold);

            // 쿼리 로깅 활성화 (개발 모드에서만)
            $debug = Env::get('APP_DEBUG', false) === true;
            if ($debug) {
                $db->enableQueryLog(true);
            }

            return $db;
        });

        // ------------------------------------
        // Session
        // ------------------------------------
        $container->singleton(DailyStorageCleanup::class, function () {
            return new DailyStorageCleanup(MUBLO_STORAGE_PATH);
        });

        $container->singleton(SessionManager::class, function (DependencyContainer $c) {
            return new SessionManager($c->get(DailyStorageCleanup::class));
        });

        $container->singleton(SessionInterface::class, function (DependencyContainer $c) {
            return $c->get(SessionManager::class);
        });

        // ------------------------------------
        // Cache
        // ------------------------------------
        $container->singleton(CacheInterface::class, function () {
            return CacheFactory::getInstance();
        });

        // 공개 엔드포인트 남용 방지용 레이트 리미터 (Cache 기반)
        $container->singleton(RateLimiter::class, function (DependencyContainer $c) {
            return new RateLimiter($c->get(CacheInterface::class));
        });

        // DomainCache는 공유 CacheInterface 싱글톤과 분리된 독립 인스턴스를 사용.
        // Application::run()의 setDomainId() 호출이 DomainCache 경로에 영향을 주지 않도록 한다.
        // storage/cache/data/domains/ 전용 경로 → global/ 과 격리.
        $container->singleton(DomainCache::class, function () {
            return new DomainCache();
        });

        $container->singleton(DomainResolver::class, function (DependencyContainer $c) {
            return new DomainResolver(
                $c->get(DomainCache::class),
                $c->get(DomainRepository::class)
            );
        });

        $container->factory(ContextBuilder::class, function (DependencyContainer $c) {
            return new ContextBuilder(
                $c->get(DomainResolver::class)
            );
        });

        // ------------------------------------
        // Cookie
        // ------------------------------------
        $container->singleton(CookieInterface::class, function () {
            return new CookieManager();
        });

        // ------------------------------------
        // File Uploader
        // ------------------------------------
        $container->singleton(FileUploader::class, function () {
            return new FileUploader();
        });

        // ------------------------------------
        // Secure File Service (보안 파일)
        // ------------------------------------
        $container->singleton(SecureFileService::class, function () {
            return new SecureFileService();
        });

        // Package / Plugin 공개 AI 계약. API 키는 구현체 밖으로 노출하지 않는다.
        $container->singleton(AiGatewayInterface::class, function (DependencyContainer $c) {
            return $c->get(CoreAiGateway::class);
        });
        $container->singleton(AiAssetCatalogInterface::class, function (DependencyContainer $c) {
            return $c->get(CoreAiAssetCatalog::class);
        });

        // ------------------------------------
        // Mailer (이메일 발송)
        // ------------------------------------
        $container->singleton(Mailer::class, function (DependencyContainer $c) {
            $logger = $c->has(Logger::class) ? $c->get(Logger::class) : null;
            return new Mailer(null, $logger);
        });

        // ------------------------------------
        // Contract Registry (범용 계약 레지스트리)
        // ------------------------------------
        $container->singleton(ContractRegistry::class, function (DependencyContainer $c) {
            $registry = new ContractRegistry();

            $registry->register(
                ReportRendererInterface::class,
                'csv',
                fn() => new CsvReportRenderer(),
                [
                    'label' => 'CSV',
                    'description' => '기본 CSV 렌더러',
                ]
            );
            $registry->register(
                ReportRendererInterface::class,
                'xlsx',
                fn() => new XlsxReportRenderer(),
                [
                    'label' => 'XLSX',
                    'description' => '기본 Excel 렌더러',
                ]
            );
            $registry->register(
                ReportRendererInterface::class,
                'pdf',
                fn() => new PdfReportRenderer(),
                [
                    'label' => 'PDF',
                    'description' => '기본 PDF 렌더러',
                ]
            );

            // 이메일 채널 역할 분담: 발송(전송로)은 코어 게이트웨이가 항상 담당하고,
            // 템플릿(내용물)은 EmailTemplateProviderInterface 공급자(예: EmailNotify)가 공급한다.
            // 과거에는 전용 플러그인이 게이트웨이까지 등록했으나, UI(채널 트리의 이메일
            // 기본 노출)와 실행(게이트웨이 부재 시 발송 실패)이 어긋나 전송로를 코어로 승격.
            $registry->register(
                \Mublo\Contract\Notification\NotificationGatewayInterface::class,
                'core_email',
                fn() => new \Mublo\Core\Notification\EmailNotificationGateway(
                    $c->get(\Mublo\Infrastructure\Mail\Mailer::class),
                    $c->get(ContractRegistry::class),
                    fn() => $c->has(\Mublo\Core\Context\Context::class)
                        ? $c->get(\Mublo\Core\Context\Context::class)->getDomainId()
                        : null,
                    // 도메인 이메일 전송 정책 — 기본설정 > 이메일 발송 활성화
                    fn(int $domainId) => (bool) ($c->get(\Mublo\Service\Domain\DomainSettingsService::class)
                        ->getSiteConfig($domainId)['email_channel_enabled'] ?? true)
                ),
                [
                    'label' => '이메일',
                    'icon' => 'bi-envelope',
                    'description' => '코어 Mailer(서버 메일/SMTP) 기반 이메일 발송 — 템플릿은 EmailTemplateProvider 계약으로 공급',
                    'channels' => ['email'],
                ]
            );

            return $registry;
        });

        // 항상 하나의 코어 구현이 존재하는 안정 계약은 생성자 주입으로 소비한다.
        $container->singleton(
            \Mublo\Contract\Block\BlockKitGatewayInterface::class,
            fn (DependencyContainer $c) => $c->get(\Mublo\Service\Block\BlockKitGateway::class)
        );
        $container->singleton(
            \Mublo\Contract\Block\BlockPageQueryInterface::class,
            fn (DependencyContainer $c) => $c->get(\Mublo\Service\Block\BlockPageService::class)
        );
        $container->singleton(
            \Mublo\Contract\Block\BlockContentCacheInvalidatorInterface::class,
            fn (DependencyContainer $c) => $c->get(\Mublo\Service\Block\BlockContentCacheInvalidator::class)
        );
        $container->singleton(
            \Mublo\Contract\Block\BlockPreviewRendererInterface::class,
            fn (DependencyContainer $c) => $c->get(\Mublo\Service\Block\BlockPreviewRenderer::class)
        );
        $container->singleton(
            \Mublo\Contract\Block\BlockRenderContextInterface::class,
            fn (DependencyContainer $c) => $c->get(\Mublo\Service\Block\BlockRenderContext::class)
        );
        $container->singleton(
            \Mublo\Contract\Site\CompanyInfoInterface::class,
            fn (DependencyContainer $c) => $c->get(\Mublo\Service\Domain\DomainSettingsService::class)
        );
        $container->singleton(
            \Mublo\Contract\Site\SiteProvisioningInterface::class,
            fn (DependencyContainer $c) => $c->get(\Mublo\Service\Site\SiteProvisioningGateway::class)
        );
        $container->singleton(
            \Mublo\Contract\Menu\MenuManagementInterface::class,
            fn (DependencyContainer $c) => $c->get(\Mublo\Service\Menu\MenuManagementGateway::class)
        );
        $container->singleton(
            \Mublo\Contract\Site\DomainQueryInterface::class,
            fn (DependencyContainer $c) => $c->get(\Mublo\Service\Domain\DomainQueryGateway::class)
        );
        $container->singleton(
            \Mublo\Contract\Member\MemberAccountGatewayInterface::class,
            fn (DependencyContainer $c) => $c->get(\Mublo\Service\Member\MemberAccountGateway::class)
        );
        $container->singleton(
            \Mublo\Contract\Member\MemberLevelCatalogInterface::class,
            fn (DependencyContainer $c) => $c->get(\Mublo\Service\Member\MemberLevelCatalog::class)
        );
        $container->singleton(
            \Mublo\Contract\Security\SensitiveValueCodecInterface::class,
            fn (DependencyContainer $c) => $c->get(\Mublo\Service\Member\SensitiveValueCodec::class)
        );
        $container->singleton(
            \Mublo\Contract\Extension\ExtensionCatalogInterface::class,
            fn (DependencyContainer $c) => new \Mublo\Service\Extension\ExtensionCatalog(
                $c->get(\Mublo\Service\Extension\ExtensionService::class)
            )
        );
        $container->singleton(
            \Mublo\Contract\CustomField\CustomFieldValueValidatorInterface::class,
            fn () => new \Mublo\Service\CustomField\CustomFieldValueValidator()
        );
        $container->singleton(
            \Mublo\Contract\CustomField\CustomFieldFileManagerInterface::class,
            fn (DependencyContainer $c) => new \Mublo\Service\CustomField\CustomFieldFileManager(
                new \Mublo\Service\CustomField\CustomFieldFileHandler(
                    $c->get(SecureFileService::class),
                    $c->get(FileUploader::class)
                )
            )
        );

        // ------------------------------------
        // Event System
        // ------------------------------------
        $container->singleton(EventDispatcher::class, function (DependencyContainer $c) {
            return new EventDispatcher(function (
                string $message,
                ?\Throwable $e = null,
                ?\Mublo\Core\Event\EventInterface $event = null,
                array $diagnostic = []
            ) use ($c): void {
                if ($c->has(Logger::class)) {
                    $c->get(Logger::class)->channel('error')->warning($message, $diagnostic);
                    return;
                }

                error_log($message);
            },
            // 개발 환경에서는 구독자 예외를 삼키지 않는다 — 조용히 사라진 기능을
            // 로그를 뒤지기 전에 알아채기 위해서다. 운영에서는 그대로 삼킨다.
            rethrowListenerFailures: env('APP_ENV', 'production') !== 'production');
        });

        $container->singleton(NotificationTemplateUiHelper::class, function (DependencyContainer $c) {
            return new NotificationTemplateUiHelper(
                $c->get(Database::class),
                $c->get(EventDispatcher::class)
            );
        });
        $container->singleton(NotificationTemplateContextInterface::class, function (DependencyContainer $c) {
            return $c->get(NotificationTemplateUiHelper::class);
        });

        // 채널 트리 조립기 (조립 계층 단일 구현) — AutoForm 액션 UI·Mshop FSM 드롭다운 등 공용
        $container->singleton(\Mublo\Service\Notification\NotificationChannelTreeBuilder::class, function (DependencyContainer $c) {
            return new \Mublo\Service\Notification\NotificationChannelTreeBuilder(
                $c->get(\Mublo\Core\Registry\ContractRegistry::class),
                $c->get(EventDispatcher::class)
            );
        });
        // 확장은 구현체가 아니라 이 계약으로 소비한다
        $container->singleton(\Mublo\Contract\Notification\NotificationChannelTreeBuilderInterface::class, function (DependencyContainer $c) {
            return $c->get(\Mublo\Service\Notification\NotificationChannelTreeBuilder::class);
        });

        // ====================================
        // 2. Repository (데이터 접근)
        // ====================================

        $container->singleton(MemberRepository::class, function (DependencyContainer $c) {
            return new MemberRepository($c->get(Database::class));
        });

        $container->singleton(MemberQueryInterface::class, function (DependencyContainer $c) {
            return new MemberQueryService($c->get(MemberRepository::class));
        });

        $container->singleton(
            \Mublo\Contract\Member\MemberCustomFieldQueryInterface::class,
            function (DependencyContainer $c) {
                return new \Mublo\Service\Member\MemberCustomFieldQueryService(
                    $c->get(\Mublo\Repository\Member\MemberFieldRepository::class),
                    $c->get(\Mublo\Service\Member\MemberService::class)
                );
            }
        );

        $container->singleton(PolicyQueryInterface::class, function (DependencyContainer $c) {
            return $c->get(\Mublo\Service\Member\PolicyService::class);
        });

        $container->singleton(MemberNotificationRepository::class, function (DependencyContainer $c) {
            return new MemberNotificationRepository($c->get(Database::class));
        });

        $container->singleton(BalanceLogRepository::class, function (DependencyContainer $c) {
            return new BalanceLogRepository($c->get(Database::class));
        });

        $container->singleton(BalanceRepairAuditRepository::class, function (DependencyContainer $c) {
            return new BalanceRepairAuditRepository($c->get(Database::class));
        });

        $container->singleton(DashboardLayoutRepository::class, function (DependencyContainer $c) {
            return new DashboardLayoutRepository($c->get(Database::class));
        });

        // ====================================
        // 3. Service (비즈니스 로직)
        // ====================================

        $container->singleton(MemberNotificationService::class, function (DependencyContainer $c) {
            return new MemberNotificationService(
                $c->get(MemberNotificationRepository::class),
                $c->get(MemberRepository::class),
                $c->get(EventDispatcher::class)
            );
        });

        $container->singleton(MemberNotificationPublisherInterface::class, function (DependencyContainer $c) {
            return $c->get(MemberNotificationService::class);
        });

        // ------------------------------------
        // Auth
        // ------------------------------------
        $container->singleton(LoginAttemptService::class, function (DependencyContainer $c) {
            // 로그인 제한은 설정이 없으면 성립하지 않는다 — 부재를 기본값으로 덮지 않는다.
            // (배열이 아닌 경우는 ConfigFile 이 예외로 잡는다)
            if (!ConfigFile::exists('security')) {
                throw new \RuntimeException(
                    'Missing security configuration. Run the installer or create config/security.php.'
                );
            }

            $securityConfig = ConfigFile::load('security');

            return new LoginAttemptService(
                $c->get(Database::class),
                $securityConfig['login_rate_limiting'] ?? []
            );
        });

        $container->singleton(AuthService::class, function (DependencyContainer $c) {
            return new AuthService(
                $c->get(SessionInterface::class),
                $c->get(MemberRepository::class),
                $c->get(\Mublo\Core\Crypto\PasswordHasher::class),
                $c->get(CsrfManager::class),
                $c->get(\Mublo\Core\Event\EventDispatcher::class),
                $c->get(LoginAttemptService::class)
            );
        });

        $container->singleton(
            AuthContextInterface::class,
            fn (DependencyContainer $c) => $c->get(AuthService::class)
        );
        $container->singleton(
            MemberAuthenticatorInterface::class,
            fn (DependencyContainer $c) => $c->get(AuthService::class)
        );
        $container->singleton(
            ManagedSiteGatewayInterface::class,
            fn (DependencyContainer $c) => $c->get(\Mublo\Service\Domain\ManagedSiteGateway::class)
        );
        $container->singleton(
            \Mublo\Contract\Frame\DomainFrameEditorInterface::class,
            fn (DependencyContainer $c) => $c->get(\Mublo\Service\Frame\DomainFrameService::class)
        );

        // ------------------------------------
        // Admin Menu
        // ------------------------------------
        $container->singleton(AdminMenuService::class, function (DependencyContainer $c) {
            return new AdminMenuService(
                $c->get(EventDispatcher::class),
                $c->get(AdminPermissionService::class)
            );
        });

        // ------------------------------------
        // Admin Permission
        // ------------------------------------
        $container->singleton(AdminPermissionService::class, function (DependencyContainer $c) {
            return new AdminPermissionService(
                $c->get(Database::class),
                $c->get(AdminPermissionRepository::class),
                $c->get(MemberLevelRepository::class)
            );
        });

        // ------------------------------------
        // Block Render
        // ------------------------------------
        $container->singleton(BlockContentSanitizer::class, fn () => new BlockContentSanitizer());
        $container->singleton(BlockColumnPayloadNormalizer::class, function (DependencyContainer $c) {
            return new BlockColumnPayloadNormalizer(
                $c->get(BlockContentSanitizer::class),
                $c->get(BlockSkinService::class)
            );
        });
        $container->singleton(InstallIdProvider::class, fn () => new InstallIdProvider());
        $container->singleton(BlockKitScreenshot::class, function (DependencyContainer $c) {
            return new BlockKitScreenshot($c->get(\Mublo\Infrastructure\Image\ImageProcessor::class));
        });
        $container->singleton(BlockKitExporter::class, function (DependencyContainer $c) {
            return new BlockKitExporter(
                $c->get(\Mublo\Repository\Block\BlockRowRepository::class),
                $c->get(\Mublo\Repository\Block\BlockColumnRepository::class),
                $c->get(BlockContentSanitizer::class),
                $c->get(MainScreenComposition::class),
                $c->get(\Mublo\Repository\Block\BlockColumnContentRepository::class)
            );
        });
        $container->singleton(BlockKitApplier::class, function (DependencyContainer $c) {
            return new BlockKitApplier(
                $c->get(BlockContentSanitizer::class),
                $c->get(\Mublo\Repository\Block\BlockRowRepository::class),
                $c->get(\Mublo\Repository\Block\BlockColumnRepository::class),
                $c->get(\Mublo\Repository\Block\BlockPageRepository::class),
                $c->get(BlockRenderService::class),
                $c->get(\Mublo\Service\Domain\DomainSettingsService::class),
                $c->get(InstallIdProvider::class),
                $c->get(\Mublo\Service\Extension\ExtensionCompatibility::class),
                $c->get(BlockColumnPayloadNormalizer::class),
                $c->get(MainScreenComposition::class),
                $c->get(EventDispatcher::class),
                $c->get(\Mublo\Repository\Block\BlockRowRevisionRepository::class),
                $c->get(BlockImageProcessor::class),
                $c->get(\Mublo\Service\Block\BlockColumnContentService::class)
            );
        });
        $container->singleton(\Mublo\Service\Block\BlockKitLibrary::class, function (DependencyContainer $c) {
            return new \Mublo\Service\Block\BlockKitLibrary(
                $c->get(\Mublo\Repository\Block\BlockKitRepository::class),
                $c->get(BlockKitApplier::class),
                $c->get(\Mublo\Service\Block\BlockKitScreenshot::class),
                $c->get(\Mublo\Repository\Block\BlockKitApplicationRepository::class),
                $c->get(\Mublo\Service\Domain\DomainSettingsService::class)
            );
        });
        $container->singleton(\Mublo\Service\Block\BlockKitGateway::class, function (DependencyContainer $c) {
            return new \Mublo\Service\Block\BlockKitGateway(
                $c->get(BlockKitApplier::class),
                $c->get(\Mublo\Service\Block\BlockKitScreenshot::class),
                $c->get(\Mublo\Infrastructure\Database\Database::class)
            );
        });

        $container->singleton(BlockRenderService::class, function (DependencyContainer $c) {
            return new BlockRenderService(
                $c->get(\Mublo\Repository\Block\BlockRowRepository::class),
                $c->get(\Mublo\Repository\Block\BlockColumnRepository::class),
                $c->get(CacheInterface::class),
                $c,
                $c->get(\Mublo\Repository\Block\BlockColumnContentRepository::class)
            );
        });

        // ------------------------------------
        // Balance Manager (포인트/잔액 관리)
        // ------------------------------------
        $container->singleton(BalanceManager::class, function (DependencyContainer $c) {
            return new BalanceManager(
                $c->get(BalanceLogRepository::class),
                $c->get(BalanceRepairAuditRepository::class),
                $c->get(MemberRepository::class),
                $c->get(Database::class),
                $c->get(EventDispatcher::class)
            );
        });

        // 공개 확장 계약 — 확장은 구현체가 아니라 이 인터페이스로 소비한다
        $container->singleton(
            \Mublo\Contract\Balance\BalanceGatewayInterface::class,
            fn(DependencyContainer $c) => $c->get(BalanceManager::class)
        );

        $container->singleton(BalanceResetManager::class, function (DependencyContainer $c) {
            return new BalanceResetManager($c->get(Database::class));
        });

        $container->singleton(
            \Mublo\Contract\Balance\BalanceResetGatewayInterface::class,
            fn(DependencyContainer $c) => $c->get(BalanceResetManager::class)
        );

        // ------------------------------------
        // Migration (Core 마이그레이션 추적)
        // ------------------------------------
        $container->singleton(ExtensionLoadDiagnostics::class, function () {
            return new ExtensionLoadDiagnostics();
        });

        $container->singleton(CoreMigrationService::class, function (DependencyContainer $c) {
            return new CoreMigrationService($c->get(Database::class));
        });

        $container->singleton(\Mublo\Service\System\SystemService::class, function (DependencyContainer $c) {
            return new \Mublo\Service\System\SystemService(
                $c->get(Database::class),
                $c->get(\Mublo\Infrastructure\Cache\DomainCache::class),
                $c->get(SecureFileService::class)
            );
        });

        // ------------------------------------
        // Dashboard
        // ------------------------------------
        $container->singleton(DashboardWidgetRegistry::class, function () {
            return new DashboardWidgetRegistry();
        });

        $container->singleton(LayoutSanitizer::class, function () {
            return new LayoutSanitizer();
        });

        $container->singleton(SlotGridArranger::class, function () {
            return new SlotGridArranger();
        });

        $container->singleton(DashboardLayoutManager::class, function (DependencyContainer $c) {
            return new DashboardLayoutManager(
                $c->get(DashboardLayoutRepository::class),
                $c->get(DashboardWidgetRegistry::class),
                $c->get(LayoutSanitizer::class)
            );
        });

        // ------------------------------------
        // Report
        // ------------------------------------
        $container->singleton(ReportDefinitionRegistry::class, function () {
            return new ReportDefinitionRegistry();
        });

        $container->singleton(AdminPermissionGate::class, function (DependencyContainer $c) {
            return new AdminPermissionGate(
                $c->get(AuthService::class),
                $c->get(AdminPermissionService::class)
            );
        });

        $container->singleton(ReportRendererResolver::class, function (DependencyContainer $c) {
            return new ReportRendererResolver(
                $c->get(ContractRegistry::class)
            );
        });

        $container->singleton(ReportFileStore::class, function () {
            return new ReportFileStore();
        });

        $container->singleton(ReportAuditLogger::class, function (DependencyContainer $c) {
            return new ReportAuditLogger(
                $c->has(Logger::class) ? $c->get(Logger::class) : null
            );
        });

        $container->singleton(ReportManager::class, function (DependencyContainer $c) {
            return new ReportManager(
                $c->get(ReportDefinitionRegistry::class),
                $c->get(ReportRendererResolver::class),
                $c->get(AdminPermissionGate::class),
                $c->get(ReportFileStore::class),
                $c->get(ReportAuditLogger::class)
            );
        });

        // ====================================
        // 4. Middleware
        // ====================================

        $container->singleton(AdminMiddleware::class, function (DependencyContainer $c) {
            return new AdminMiddleware(
                $c->get(AuthService::class),
                $c->get(AdminMenuService::class),
                $c->get(AdminPermissionService::class),
                $c->has(Logger::class) ? $c->get(Logger::class) : null
            );
        });

        $container->singleton(AuthMiddleware::class, function (DependencyContainer $c) {
            return new AuthMiddleware(
                $c->get(AuthService::class)
            );
        });

        $container->singleton(CsrfManager::class, function (DependencyContainer $c) {
            return new CsrfManager($c->get(SessionInterface::class));
        });
        $container->singleton(
            \Mublo\Contract\Security\CsrfTokenProviderInterface::class,
            fn (DependencyContainer $c) => $c->get(CsrfManager::class)
        );

        $container->singleton(CsrfMiddleware::class, function (DependencyContainer $c) {
            $csrf = new CsrfMiddleware($c->get(CsrfManager::class));
            // Core CSRF 예외 경로
            $csrf->addExcludePath('/api/track/');
            return $csrf;
        });

        $container->singleton(SecurityHeadersMiddleware::class, fn () => new SecurityHeadersMiddleware());

        // 전역 파이프라인이 쓰는 미들웨어는 셋 다 여기서 등록한다.
        // SessionMiddleware 만 빠져 있으면 확장이 has() 로 물었을 때 CsrfMiddleware 와
        // 결과가 갈린다(PSR-11 has 는 명시 등록만 true). 실제로 PG 플러그인들이
        // has() 가드 안에서 addExcludePath 를 호출하다 통째로 무시된 적이 있다.
        $container->singleton(SessionMiddleware::class, fn (DependencyContainer $c) =>
            new SessionMiddleware($c->get(SessionManager::class))
        );

        // ====================================
        // 5. Rendering
        // ====================================

        $container->factory(
            LayoutManager::class,
            fn () => new LayoutManager()
        );

        $container->singleton(AssetManager::class, fn () => new AssetManager());
        $container->singleton(CategoryProviderRegistry::class, fn () => new CategoryProviderRegistry());
        $container->singleton(\Mublo\Core\Rendering\FrontViewRuntime::class, fn () => new \Mublo\Core\Rendering\FrontViewRuntime());

        $container->factory(
            FrontViewRenderer::class,
            fn (DependencyContainer $c) => new FrontViewRenderer(
                $c->get(LayoutManager::class),
                $c->get(AuthService::class),
                $c->get(MenuService::class),
                $c->get(BlockRenderService::class),
                $c->get(CsrfManager::class),
                $c->get(EventDispatcher::class),
                $c->get(AssetManager::class),
                $c->get(CategoryProviderRegistry::class),
                $c->get(MemberNotificationService::class),
                $c->get(\Mublo\Repository\Frame\DomainFrameOverrideRepository::class),
                $c->get(\Mublo\Core\Rendering\FrontViewRuntime::class)
            )
        );

        $container->factory(
            AdminViewRenderer::class,
            fn (DependencyContainer $c) => new AdminViewRenderer(
                $c->get(AdminMenuService::class),
                $c->get(\Mublo\Service\Auth\AuthService::class),
                $c->get(CsrfManager::class),
                $c->get(AssetManager::class)
            )
        );

        // ====================================
        // 6. Router / Dispatcher
        // ====================================

        $container->factory(
            Router::class,
            fn (DependencyContainer $c) => new Router($c)
        );

        $container->factory(
            Dispatcher::class,
            fn (DependencyContainer $c) => new Dispatcher($c)
        );

        // ====================================
        // 7. Utility
        // ====================================

        // Code Generator (Context 의존)
        $container->factory(CodeGenerator::class, function (DependencyContainer $c) {
            return new CodeGenerator(
                $c->get(Database::class),
                $c->get(Context::class)
            );
        });

        // Migration Runner (Core / Plugin / Package DB 마이그레이션 통합)
        $container->singleton(MigrationRunner::class, function (DependencyContainer $c) {
            return new MigrationRunner($c->get(Database::class));
        });

        // ------------------------------------
        // Search (전체 검색)
        // ------------------------------------
        $container->singleton(SearchService::class, function (DependencyContainer $c) {
            return new SearchService($c->get(EventDispatcher::class));
        });

    }

    /**
     * Core 이벤트 구독자 등록
     *
     * Application.boot()에서 ServiceProvider.register() 이후 호출.
     * EventDispatcher가 준비된 후에 Core 구독자를 등록한다.
     */
    public function bootSubscribers(DependencyContainer $container): void
    {
        $eventDispatcher = $container->get(EventDispatcher::class);

        // 회원 조회 이벤트 구독자 (Package/Plugin → Core 회원 데이터 조회)
        $eventDispatcher->addSubscriber(
            new MemberQuerySubscriber(
                $container->get(MemberRepository::class),
                $container->get(MemberLevelRepository::class),
            )
        );

        // Core 대시보드 위젯 등록
        // Note: boot 시점에는 Context 미존재 → Closure로 지연 해석
        $domainIdResolver = fn() => $container->has(Context::class)
            ? $container->get(Context::class)->getDomainId()
            : null;
        $registry = $container->get(DashboardWidgetRegistry::class);

        $registry->register('core.system_info', new SystemInfoWidget(), 0);
        $registry->register(
            'core.member_stats',
            new MemberStatsWidget($container->get(MemberRepository::class), $domainIdResolver),
            1
        );
        // 번들 시작 킷 안내 — 설치 직후 "메인을 어떻게 채우지?"의 답
        $registry->register(
            'core.starter_kits',
            new \Mublo\Core\Dashboard\Widget\StarterKitWidget(
                $container->get(\Mublo\Infrastructure\Database\Database::class),
                $domainIdResolver
            ),
            2
        );

        // 블록 페이지 → 메뉴 아이템 자동 등록/삭제
        $eventDispatcher->addSubscriber(
            new \Mublo\Subscriber\BlockPageMenuSubscriber($container)
        );

    }

    /**
     * 확장(Plugin/Package) 로드 후 Core 이벤트 구독자 등록
     *
     * Application.loadEnabledExtensions() 이후 호출.
     * PolicyService, ExtensionService 등 Package 의존 서비스 사용.
     */
    public static function bootPostExtensionSubscribers(DependencyContainer $container): void
    {
        $eventDispatcher = $container->get(EventDispatcher::class);

        // 도메인 생성/수정/삭제 이벤트 구독자 (기본 데이터 시딩)
        $eventDispatcher->addSubscriber(new DomainEventSubscriber(
            $container->get(MenuService::class),
            $container->get(\Mublo\Service\Member\PolicyService::class),
            $container->has(\Mublo\Service\Extension\ExtensionService::class)
                ? $container->get(\Mublo\Service\Extension\ExtensionService::class)
                : null,
            $container->get(Database::class),
            $container->has(\Mublo\Service\Block\BlockKitGateway::class)
                ? $container->get(\Mublo\Service\Block\BlockKitGateway::class)
                : null
        ));
    }
}
