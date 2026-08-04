<?php
declare(strict_types=1);
namespace Mublo\Plugin\Faq;

use Mublo\Contract\DataResetResult;
use Mublo\Contract\Block\BlockContentCacheInvalidatorInterface;
use Mublo\Contract\Faq\FaqProvisioningInterface;
use Mublo\Contract\Faq\FaqQueryInterface;
use Mublo\Contract\Menu\MenuManagementInterface;
use Mublo\Contract\Site\SiteProvisioningInterface;
use Mublo\Plugin\Faq\Api\FaqProvisioningGateway;
use Mublo\Contract\DataResettableInterface;
use Mublo\Contract\Sitemap\SitemapUrlProviderInterface;
use Mublo\Core\Block\BlockRegistry;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\InstallableExtensionInterface;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Rendering\AssetManager;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Enum\Block\BlockContentKind;
use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\Faq\Block\FaqConfigForm;
use Mublo\Plugin\Faq\Block\FaqItemsProvider;
use Mublo\Plugin\Faq\Block\FaqRenderer;
use Mublo\Plugin\Faq\Controller\Admin\FaqCategoryController;
use Mublo\Plugin\Faq\Controller\Admin\FaqItemController;
use Mublo\Plugin\Faq\Controller\Front\FaqController;
use Mublo\Plugin\Faq\Repository\FaqConfigRepository;
use Mublo\Plugin\Faq\Repository\FaqRepository;
use Mublo\Plugin\Faq\Service\FaqService;
use Mublo\Plugin\Faq\Service\FaqDataResetter;
use Mublo\Plugin\Faq\Sitemap\FaqSitemapProvider;
use Mublo\Plugin\Faq\Subscriber\FaqBlockCacheSubscriber;

/**
 * FaqProvider
 *
 * FAQ 플러그인 Provider
 */
class FaqProvider implements ExtensionProviderInterface, InstallableExtensionInterface, DataResettableInterface
{
    private FaqDataResetter $dataResetter;

    public function register(DependencyContainer $container): void
    {
        $container->singleton(FaqDataResetter::class, fn($c) =>
            new FaqDataResetter($c->get(Database::class))
        );
        // Repository
        $container->singleton(FaqRepository::class, function ($c) {
            return new FaqRepository($c->get(Database::class));
        });
        $container->singleton(FaqConfigRepository::class, function ($c) {
            return new FaqConfigRepository($c->get(Database::class));
        });

        // Service
        $container->singleton(FaqService::class, function ($c) {
            return new FaqService(
                $c->get(FaqRepository::class),
                $c->get(EventDispatcher::class)
            );
        });

        // Admin Controller
        $container->singleton(FaqCategoryController::class, function ($c) {
            return new FaqCategoryController($c->get(FaqService::class));
        });

        $container->singleton(FaqItemController::class, function ($c) {
            return new FaqItemController(
                $c->get(FaqService::class),
                $c->get(MigrationRunner::class),
                $c->get(FaqConfigRepository::class)
            );
        });

        // Front Controller
        $container->singleton(FaqController::class, function ($c) {
            return new FaqController(
                $c->get(FaqService::class),
                $c->get(FaqConfigRepository::class)
            );
        });

        // Block
        $container->singleton(FaqRenderer::class, function ($c) {
            $renderer = new FaqRenderer($c->get(FaqService::class));
            $renderer->assetManager = $c->get(AssetManager::class);
            return $renderer;
        });

        $container->singleton(FaqConfigForm::class, function () {
            return new FaqConfigForm();
        });

        $container->singleton(FaqItemsProvider::class, function ($c) {
            return new FaqItemsProvider($c->get(FaqRepository::class));
        });
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $this->dataResetter = $container->get(FaqDataResetter::class);
        $eventDispatcher = $container->get(EventDispatcher::class);

        // 관리자 메뉴 등록
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());
        $eventDispatcher->addSubscriber(new FaqBlockCacheSubscriber(
            $container->get(BlockContentCacheInvalidatorInterface::class)
        ));

        // Contract 바인딩: FaqQueryInterface → FaqService
        $contractRegistry = $container->get(ContractRegistry::class);
        $contractRegistry->bind(
            FaqQueryInterface::class,
            $container->get(FaqService::class)
        );

        // 확장이 FAQ 카테고리를 프로그래밍으로 만들 때 쓰는 안정 계약
        // Closure = 지연 생성. 사이트 구축 때만 쓰이므로 일반 요청에서는 만들지 않는다.
        $contractRegistry->bind(
            FaqProvisioningInterface::class,
            fn(): FaqProvisioningGateway => new FaqProvisioningGateway($container->get(FaqRepository::class))
        );

        // Contract 등록(1:N): 사이트맵 URL 제공자
        // Closure = 지연 생성 — 사이트맵 요청 때만 인스턴스가 만들어진다.
        $contractRegistry->register(
            SitemapUrlProviderInterface::class,
            'faq',
            fn() => new FaqSitemapProvider(
                $container->get(FaqRepository::class)
            )
        );

        // 블록 콘텐츠 타입 등록
        BlockRegistry::registerContentType(
            type: 'faq',
            kind: BlockContentKind::PLUGIN->value,
            title: 'FAQ',
            rendererClass: FaqRenderer::class,
            configFormClass: FaqConfigForm::class,
            options: [
                'icon' => 'bi-question-circle',
                'capabilities' => BlockRegistry::capabilities(
                    skin: true, items: true, count: true, style: false, aos: true, customConfig: false,
                ),
                'skinBasePath' => MUBLO_PLUGIN_PATH . '/Faq/views/Block',
                'hasItems' => true,
                'maxItems' => 0,
                'itemsProvider' => FaqItemsProvider::class,
                'hasStyle' => true,
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
        $runner->run('plugin', 'Faq', MUBLO_PLUGIN_PATH . '/Faq/database/migrations');

        $domainId = $context->getDomainId();

        // 재활성화 시 운영자가 수정한 라벨을 보존하며 메뉴 존재를 멱등 보장한다.
        $container->get(SiteProvisioningInterface::class)->ensureMenuItem($domainId, 'faq', [
            'label' => 'FAQ',
            'url' => '/faq',
            'provider_type' => 'plugin',
            'provider_name' => 'Faq',
        ]);
    }

    /**
     * 비활성화 시: 프론트 메뉴 삭제 (DB 데이터는 보존)
     */
    public function uninstall(DependencyContainer $container, Context $context): void
    {
        $menuManagement = $container->get(MenuManagementInterface::class);
        $domainId = $context->getDomainId();

        $items = $menuManagement->findProviderMenus($domainId, 'plugin', 'Faq');
        foreach ($items as $item) {
            $menuManagement->removeMenu($domainId, $item->itemId);
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
