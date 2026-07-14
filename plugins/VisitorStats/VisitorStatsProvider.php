<?php

namespace Mublo\Plugin\VisitorStats;

use Mublo\Contract\DataResetResult;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Dashboard\DashboardWidgetRegistry;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Contract\DataResettableInterface;
use Mublo\Core\Block\BlockRegistry;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Rendering\AssetManager;
use Mublo\Core\Session\SessionInterface;
use Mublo\Enum\Block\BlockContentKind;
use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\VisitorStats\Block\VisitorStatsRenderer;
use Mublo\Plugin\VisitorStats\Block\VisitorTrendRenderer;
use Mublo\Plugin\VisitorStats\Controller\VisitorStatsController;
use Mublo\Plugin\VisitorStats\Dashboard\VisitorStatsWidget;
use Mublo\Plugin\VisitorStats\Repository\VisitorDailyRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorHourlyRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorLogRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorPageRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignKeyRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorReferrerRepository;
use Mublo\Plugin\VisitorStats\Repository\ConversionRepository;
use Mublo\Plugin\VisitorStats\Service\ConversionStatsService;
use Mublo\Plugin\VisitorStats\Service\VisitorCollector;
use Mublo\Plugin\VisitorStats\Service\VisitorStatsService;
use Mublo\Plugin\VisitorStats\Service\VisitorStatsDataResetter;
use Mublo\Plugin\VisitorStats\Subscriber\VisitorTrackingSubscriber;

class VisitorStatsProvider implements ExtensionProviderInterface, DataResettableInterface
{
    private VisitorStatsDataResetter $dataResetter;

    public function register(DependencyContainer $container): void
    {
        $container->singleton(VisitorStatsDataResetter::class, fn($c) =>
            new VisitorStatsDataResetter($c->get(Database::class))
        );
        // Repositories
        $container->singleton(VisitorLogRepository::class, function ($c) {
            return new VisitorLogRepository($c->get(Database::class));
        });
        $container->singleton(VisitorDailyRepository::class, function ($c) {
            return new VisitorDailyRepository($c->get(Database::class));
        });
        $container->singleton(VisitorHourlyRepository::class, function ($c) {
            return new VisitorHourlyRepository($c->get(Database::class));
        });
        $container->singleton(VisitorPageRepository::class, function ($c) {
            return new VisitorPageRepository($c->get(Database::class));
        });
        $container->singleton(VisitorReferrerRepository::class, function ($c) {
            return new VisitorReferrerRepository($c->get(Database::class));
        });
        $container->singleton(VisitorCampaignKeyRepository::class, function ($c) {
            return new VisitorCampaignKeyRepository($c->get(Database::class));
        });
        $container->singleton(VisitorCampaignRepository::class, function ($c) {
            return new VisitorCampaignRepository($c->get(Database::class));
        });

        // Conversion (form_submissions 기반)
        $container->singleton(ConversionRepository::class, function ($c) {
            return new ConversionRepository($c->get(Database::class));
        });

        // Services
        $container->singleton(VisitorCollector::class, function ($c) {
            return new VisitorCollector(
                $c->get(VisitorLogRepository::class),
                $c->get(VisitorDailyRepository::class),
                $c->get(VisitorHourlyRepository::class),
                $c->get(VisitorPageRepository::class),
                $c->get(VisitorReferrerRepository::class),
                $c->get(VisitorCampaignRepository::class),
                $c->get(SessionInterface::class),
            );
        });

        $container->singleton(VisitorStatsService::class, function ($c) {
            return new VisitorStatsService(
                $c->get(VisitorLogRepository::class),
                $c->get(VisitorDailyRepository::class),
                $c->get(VisitorHourlyRepository::class),
                $c->get(VisitorPageRepository::class),
                $c->get(VisitorReferrerRepository::class),
                $c->get(VisitorCampaignRepository::class),
                $c->get(VisitorCampaignKeyRepository::class),
            );
        });

        $container->singleton(ConversionStatsService::class, function ($c) {
            return new ConversionStatsService(
                $c->get(ConversionRepository::class),
                $c->get(VisitorCampaignRepository::class),
                $c->get(VisitorCampaignKeyRepository::class),
                $c->get(VisitorStatsService::class),
            );
        });

        // Controller
        $container->singleton(VisitorStatsController::class, function ($c) {
            return new VisitorStatsController(
                $c->get(VisitorStatsService::class),
                $c->get(ConversionStatsService::class),
                $c->get(VisitorLogRepository::class),
                $c->get(VisitorCampaignKeyRepository::class),
                $c->get(MigrationRunner::class)
            );
        });

        // Block 렌더러
        $container->singleton(VisitorStatsRenderer::class, function ($c) {
            $renderer = new VisitorStatsRenderer($c->get(VisitorStatsService::class));
            $renderer->assetManager = $c->get(AssetManager::class);
            return $renderer;
        });
        $container->singleton(VisitorTrendRenderer::class, function ($c) {
            $renderer = new VisitorTrendRenderer($c->get(VisitorStatsService::class));
            $renderer->assetManager = $c->get(AssetManager::class);
            return $renderer;
        });
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $this->dataResetter = $container->get(VisitorStatsDataResetter::class);
        $eventDispatcher = $container->get(EventDispatcher::class);

        // 관리자 메뉴
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());

        // 블록 콘텐츠 타입 등록 (설정 없이 스킨 선택만, 매 방문마다 바뀌므로 noCache)
        // 1) 방문자 통계 — 숫자형(오늘 요약 + 누적)
        BlockRegistry::registerContentType(
            type: 'visitor_stats',
            kind: BlockContentKind::PLUGIN->value,
            title: '방문자 통계',
            rendererClass: VisitorStatsRenderer::class,
            options: [
                'skinBasePath' => MUBLO_PLUGIN_PATH . '/VisitorStats/views/Block',
                'hasItems' => false,
                'hasStyle' => true,
                'noCache' => true,
            ]
        );
        // 2) 방문자 추이 — 그래프형(최근 7일)
        BlockRegistry::registerContentType(
            type: 'visitor_trend',
            kind: BlockContentKind::PLUGIN->value,
            title: '방문자 추이',
            rendererClass: VisitorTrendRenderer::class,
            options: [
                'skinBasePath' => MUBLO_PLUGIN_PATH . '/VisitorStats/views/Block',
                'hasItems' => false,
                'hasStyle' => true,
                'noCache' => true,
            ]
        );

        // 방문자 추적 (프론트에서만)
        $eventDispatcher->addSubscriber(
            new VisitorTrackingSubscriber(
                $container->get(VisitorCollector::class),
                $container->get(SessionInterface::class)
            )
        );

        // 대시보드 위젯
        try {
            $widgetRegistry = $container->get(DashboardWidgetRegistry::class);
            $widget = new VisitorStatsWidget(
                $container->get(VisitorDailyRepository::class),
                $context->getDomainId()
            );
            $widgetRegistry->register($widget->id(), $widget, 5);
        } catch (\Throwable $e) {
            // 위젯 등록 실패는 무시
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
