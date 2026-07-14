<?php
namespace Mublo\Plugin\Banner;

use Mublo\Contract\DataResetResult;
use Mublo\Core\Block\BlockRegistry;
use Mublo\Contract\DataResettableInterface;
use Mublo\Contract\DataResetFilesystemInterface;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Enum\Block\BlockContentKind;
use Mublo\Core\Rendering\AssetManager;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Plugin\Banner\Block\BannerConfigForm;
use Mublo\Plugin\Banner\Block\BannerRenderer;
use Mublo\Plugin\Banner\Controller\BannerController;
use Mublo\Plugin\Banner\Repository\BannerRepository;
use Mublo\Plugin\Banner\Service\BannerService;
use Mublo\Plugin\Banner\Service\BannerDataResetter;
use Mublo\Plugin\Banner\Subscriber\BannerBlockCacheSubscriber;

/**
 * BannerProvider
 *
 * 배너 플러그인 Provider
 */
class BannerProvider implements ExtensionProviderInterface, DataResettableInterface, DataResetFilesystemInterface
{
    private BannerDataResetter $dataResetter;

    public function register(DependencyContainer $container): void
    {
        $container->singleton(BannerDataResetter::class, fn($c) =>
            new BannerDataResetter($c->get(Database::class))
        );
        // Repository
        $container->singleton(BannerRepository::class, function ($c) {
            return new BannerRepository($c->get(Database::class));
        });

        // Service
        $container->singleton(BannerService::class, function ($c) {
            return new BannerService(
                $c->get(BannerRepository::class),
                $c->get(EventDispatcher::class)
            );
        });

        // Controller
        $container->singleton(BannerController::class, function ($c) {
            return new BannerController(
                $c->get(BannerService::class),
                $c->get(MigrationRunner::class),
                $c->get(FileUploader::class),
                $c->get(EventDispatcher::class)
            );
        });

        // Block
        $container->singleton(BannerRenderer::class, function ($c) {
            $renderer = new BannerRenderer(
                $c->get(BannerService::class),
                $c->get(EventDispatcher::class)
            );
            $renderer->assetManager = $c->get(AssetManager::class);
            return $renderer;
        });

        $container->singleton(BannerConfigForm::class, function () {
            return new BannerConfigForm();
        });

    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $this->dataResetter = $container->get(BannerDataResetter::class);
        $eventDispatcher = $container->get(EventDispatcher::class);

        // 관리자 메뉴 구독자 등록
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());
        $eventDispatcher->addSubscriber(new BannerBlockCacheSubscriber($container));

        // 블록 콘텐츠 타입 등록
        BlockRegistry::registerContentType(
            type: 'banner',
            kind: BlockContentKind::PLUGIN->value,
            title: '배너',
            rendererClass: BannerRenderer::class,
            configFormClass: BannerConfigForm::class,
            options: [
                'skinBasePath' => MUBLO_PLUGIN_PATH . '/Banner/views/Block',
                'hasItems' => true,
                'hasStyle' => true,
                'adminScript' => '/serve/plugin/Banner/assets/js/block-banner.js',
                'adminScriptInit' => 'MubloBlockBanner',
            ]
        );

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
