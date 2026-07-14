<?php
namespace Mublo\Plugin\Manual;

use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Contract\DataResetFilesystemInterface;
use Mublo\Contract\Manual\ManualQueryInterface;
use Mublo\Contract\Sitemap\SitemapUrlProviderInterface;
use Mublo\Core\Block\BlockRegistry;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\InstallableExtensionInterface;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Core\Rendering\AssetManager;
use Mublo\Enum\Block\BlockContentKind;
use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\Manual\Controller\Admin\ManualBookController;
use Mublo\Plugin\Manual\Controller\Admin\ManualPageController;
use Mublo\Plugin\Manual\Controller\Front\ManualController;
use Mublo\Plugin\Manual\Block\ManualBookItemsProvider;
use Mublo\Plugin\Manual\Block\ManualBooksRenderer;
use Mublo\Plugin\Manual\Block\ManualPageItemsProvider;
use Mublo\Plugin\Manual\Block\ManualPageRenderer;
use Mublo\Plugin\Manual\Block\ManualRecentRenderer;
use Mublo\Plugin\Manual\Block\ManualTocRenderer;
use Mublo\Plugin\Manual\Repository\ManualConfigRepository;
use Mublo\Plugin\Manual\Repository\ManualRepository;
use Mublo\Plugin\Manual\Service\ManualService;
use Mublo\Plugin\Manual\Service\ManualDataResetter;
use Mublo\Plugin\Manual\Sitemap\ManualSitemapProvider;
use Mublo\Plugin\Manual\Subscriber\ManualBlockCacheSubscriber;
use Mublo\Repository\Menu\MenuItemRepository;
use Mublo\Service\Menu\MenuService;

/**
 * ManualProvider
 *
 * 매뉴얼 플러그인 Provider.
 * 코어 코드를 수정하지 않고, ManualQueryInterface(신규 계약)를 ContractRegistry에 바인딩한다.
 */
class ManualProvider implements ExtensionProviderInterface, InstallableExtensionInterface, DataResettableInterface, DataResetFilesystemInterface
{
    private ManualDataResetter $dataResetter;

    public function register(DependencyContainer $container): void
    {
        $container->singleton(ManualDataResetter::class, fn($c) =>
            new ManualDataResetter($c->get(Database::class))
        );
        // Repository
        $container->singleton(ManualRepository::class, function ($c) {
            return new ManualRepository($c->get(Database::class));
        });

        $container->singleton(ManualConfigRepository::class, function ($c) {
            return new ManualConfigRepository($c->get(Database::class));
        });

        // Service
        $container->singleton(ManualService::class, function ($c) {
            return new ManualService(
                $c->get(ManualRepository::class),
                null,
                $c->get(EventDispatcher::class)
            );
        });

        $container->singleton(ManualBookItemsProvider::class, fn ($c) =>
            new ManualBookItemsProvider($c->get(ManualService::class))
        );
        $container->singleton(ManualPageItemsProvider::class, fn ($c) =>
            new ManualPageItemsProvider($c->get(ManualService::class))
        );

        foreach ([
            ManualBooksRenderer::class,
            ManualTocRenderer::class,
            ManualPageRenderer::class,
            ManualRecentRenderer::class,
        ] as $rendererClass) {
            $container->singleton($rendererClass, function ($c) use ($rendererClass) {
                $renderer = new $rendererClass($c->get(ManualService::class));
                $renderer->assetManager = $c->get(AssetManager::class);
                return $renderer;
            });
        }

        // Admin Controllers
        $container->singleton(ManualBookController::class, function ($c) {
            return new ManualBookController(
                $c->get(ManualService::class),
                $c->get(MigrationRunner::class),
                $c->get(ManualConfigRepository::class)
            );
        });

        $container->singleton(ManualPageController::class, function ($c) {
            return new ManualPageController($c->get(ManualService::class));
        });

        // Front Controller
        $container->singleton(ManualController::class, function ($c) {
            return new ManualController(
                $c->get(ManualService::class),
                $c->get(ManualConfigRepository::class)
            );
        });
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $this->dataResetter = $container->get(ManualDataResetter::class);
        $eventDispatcher = $container->get(EventDispatcher::class);

        // 관리자 메뉴 등록
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());
        $eventDispatcher->addSubscriber(new ManualBlockCacheSubscriber($container));

        // Contract 바인딩: ManualQueryInterface → ManualService
        $contractRegistry = $container->get(ContractRegistry::class);
        $contractRegistry->bind(
            ManualQueryInterface::class,
            $container->get(ManualService::class)
        );

        // 사이트맵 URL 기여 (1:N 등록) — Closure 로 지연 생성한다.
        // 플러그인이 비활성이면 등록 자체가 없으므로 매뉴얼 URL 도 사라진다.
        $contractRegistry->register(
            SitemapUrlProviderInterface::class,
            'manual',
            fn() => new ManualSitemapProvider($container->get(Database::class)),
        );

        $script = '/serve/plugin/Manual/assets/js/block-manual.js';
        $skinBasePath = MUBLO_PLUGIN_PATH . '/Manual/views/Block';

        BlockRegistry::registerContentType(
            type: 'manual_books',
            kind: BlockContentKind::PLUGIN->value,
            title: '매뉴얼 목록',
            rendererClass: ManualBooksRenderer::class,
            options: [
                'skinBasePath' => $skinBasePath,
                'hasItems' => true,
                'maxItems' => 0,
                'itemsProvider' => ManualBookItemsProvider::class,
                'adminScript' => $script,
                'adminScriptInit' => 'MubloBlockManualBooks',
            ]
        );
        BlockRegistry::registerContentType(
            type: 'manual_toc',
            kind: BlockContentKind::PLUGIN->value,
            title: '매뉴얼 목차',
            rendererClass: ManualTocRenderer::class,
            options: [
                'skinBasePath' => $skinBasePath,
                'hasItems' => true,
                'maxItems' => 1,
                'itemsProvider' => ManualBookItemsProvider::class,
                'adminScript' => $script,
                'adminScriptInit' => 'MubloBlockManualToc',
            ]
        );
        BlockRegistry::registerContentType(
            type: 'manual_page',
            kind: BlockContentKind::PLUGIN->value,
            title: '매뉴얼 페이지',
            rendererClass: ManualPageRenderer::class,
            options: [
                'skinBasePath' => $skinBasePath,
                'hasItems' => true,
                'maxItems' => 1,
                'itemsProvider' => ManualPageItemsProvider::class,
                'adminScript' => $script,
                'adminScriptInit' => 'MubloBlockManualPage',
            ]
        );
        BlockRegistry::registerContentType(
            type: 'manual_recent',
            kind: BlockContentKind::PLUGIN->value,
            title: '최근 수정 매뉴얼',
            rendererClass: ManualRecentRenderer::class,
            options: [
                'skinBasePath' => $skinBasePath,
                'hasItems' => true,
                'maxItems' => 0,
                'itemsProvider' => ManualBookItemsProvider::class,
                'adminScript' => $script,
                'adminScriptInit' => 'MubloBlockManualRecent',
            ]
        );
    }

    /**
     * 첫 활성화 시: 마이그레이션 + 프론트 메뉴 등록
     */
    public function install(DependencyContainer $container, Context $context): void
    {
        // DB 마이그레이션
        $runner = $container->get(MigrationRunner::class);
        $runner->run('plugin', 'Manual', MUBLO_PLUGIN_PATH . '/Manual/database/migrations');

        // Board/Shop은 기본 포함 패키지이므로 신규 도메인에 기본 매뉴얼도 함께 준비한다.
        // 같은 slug의 최신 책은 보존하고, 구버전 기본 번들만 개정본으로 갱신한다.
        $domainId = $context->getDomainId() ?? 1;
        $container->get(ManualService::class)->ensureDefaultManuals($domainId);

        // 프론트 메뉴 아이템 등록 (멱등 — 이미 등록돼 있으면 건너뜀)
        $menuItemRepo = $container->get(MenuItemRepository::class);

        if (!empty($menuItemRepo->findByProvider($domainId, 'plugin', 'Manual'))) {
            return;
        }

        $menuService = $container->get(MenuService::class);
        $menuService->createItem($domainId, [
            'label'         => '매뉴얼',
            'url'           => '/manual',
            'provider_type' => 'plugin',
            'provider_name' => 'Manual',
        ]);
    }

    /**
     * 비활성화 시: 프론트 메뉴 삭제 (DB 데이터는 보존)
     */
    public function uninstall(DependencyContainer $container, Context $context): void
    {
        $menuService = $container->get(MenuService::class);
        $menuItemRepo = $container->get(MenuItemRepository::class);
        $domainId = $context->getDomainId();

        $items = $menuItemRepo->findByProvider($domainId, 'plugin', 'Manual');
        foreach ($items as $item) {
            $menuService->deleteItem((int) $item['item_id']);
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

    public function resetFiles(string $category, int $domainId): int
    {
        return $this->dataResetter->resetFiles($category, $domainId);
    }
}
