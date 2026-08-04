<?php
declare(strict_types=1);
namespace Mublo\Plugin\Survey;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\InstallableExtensionInterface;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Rendering\AssetManager;
use Mublo\Enum\Block\BlockContentKind;
use Mublo\Infrastructure\Database\Database;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Plugin\Survey\AdminMenuSubscriber;
use Mublo\Plugin\Survey\Block\SurveyConfigForm;
use Mublo\Plugin\Survey\Block\SurveyItemsProvider;
use Mublo\Plugin\Survey\Block\SurveyRenderer;
use Mublo\Plugin\Survey\Controller\Admin\SurveyAdminController;
use Mublo\Plugin\Survey\Controller\Front\SurveyController;
use Mublo\Plugin\Survey\Repository\SurveyAnswerRepository;
use Mublo\Plugin\Survey\Repository\SurveyConfigRepository;
use Mublo\Plugin\Survey\Repository\SurveyQuestionRepository;
use Mublo\Plugin\Survey\Repository\SurveyRepository;
use Mublo\Plugin\Survey\Repository\SurveyResponseRepository;
use Mublo\Plugin\Survey\Service\SurveyResultService;
use Mublo\Plugin\Survey\Service\SurveyService;
use Mublo\Plugin\Survey\Service\SurveySubmitService;
use Mublo\Plugin\Survey\Service\SurveyDataResetter;

class SurveyProvider implements ExtensionProviderInterface, InstallableExtensionInterface, DataResettableInterface
{
    private SurveyDataResetter $dataResetter;

    public function register(DependencyContainer $container): void
    {
        $container->singleton(SurveyDataResetter::class, fn($c) => new SurveyDataResetter($c->get(Database::class)));
        // Repository
        $container->singleton(SurveyRepository::class,
            fn($c) => new SurveyRepository($c->get(Database::class)));
        $container->singleton(SurveyQuestionRepository::class,
            fn($c) => new SurveyQuestionRepository($c->get(Database::class)));
        $container->singleton(SurveyResponseRepository::class,
            fn($c) => new SurveyResponseRepository($c->get(Database::class)));
        $container->singleton(SurveyAnswerRepository::class,
            fn($c) => new SurveyAnswerRepository($c->get(Database::class)));
        $container->singleton(SurveyConfigRepository::class,
            fn($c) => new SurveyConfigRepository($c->get(Database::class)));

        // Service
        $container->singleton(SurveyService::class, function ($c) {
            return new SurveyService(
                $c->get(SurveyRepository::class),
                $c->get(SurveyQuestionRepository::class),
                $c->get(SurveyResponseRepository::class),
                $c->get(SurveyAnswerRepository::class),
            );
        });

        $container->singleton(SurveySubmitService::class, function ($c) {
            return new SurveySubmitService(
                $c->get(SurveyRepository::class),
                $c->get(SurveyQuestionRepository::class),
                $c->get(SurveyResponseRepository::class),
                $c->get(SurveyAnswerRepository::class),
                $c->get(EventDispatcher::class),
            );
        });

        $container->singleton(SurveyResultService::class, function ($c) {
            return new SurveyResultService(
                $c->get(SurveyRepository::class),
                $c->get(SurveyQuestionRepository::class),
                $c->get(SurveyResponseRepository::class),
                $c->get(SurveyAnswerRepository::class),
            );
        });

        // Controller
        $container->singleton(SurveyAdminController::class, function ($c) {
            return new SurveyAdminController(
                $c->get(SurveyService::class),
                $c->get(SurveyResultService::class),
                $c->get(MigrationRunner::class),
                $c->get(SurveyConfigRepository::class),
            );
        });

        $container->singleton(SurveyController::class, function ($c) {
            return new SurveyController(
                $c->get(SurveyService::class),
                $c->get(SurveySubmitService::class),
                $c->get(AuthContextInterface::class),
                $c->get(SurveyConfigRepository::class),
            );
        });

        // Block
        $container->singleton(SurveyRenderer::class, function ($c) {
            $renderer = new SurveyRenderer(
                $c->get(SurveyService::class),
                $c->get(SurveyResultService::class),
                $c->get(SurveySubmitService::class),
            );
            $renderer->assetManager = $c->get(AssetManager::class);
            return $renderer;
        });
        $container->singleton(SurveyConfigForm::class, fn() => new SurveyConfigForm());
        $container->singleton(SurveyItemsProvider::class,
            fn($c) => new SurveyItemsProvider($c->get(SurveyRepository::class)));
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $this->dataResetter = $container->get(SurveyDataResetter::class);
        $eventDispatcher = $container->get(EventDispatcher::class);
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());

        BlockRegistry::registerContentType(
            type:            'survey',
            kind:            BlockContentKind::PLUGIN->value,
            title:           '설문',
            rendererClass:   SurveyRenderer::class,
            configFormClass: SurveyConfigForm::class,
            options: [
                'capabilities' => BlockRegistry::capabilities(
                    skin: true, items: true, count: false, style: false, aos: true, customConfig: false,
                ),
                'hasItems'     => true,
                'maxItems'     => 1,
                'hasStyle'     => true,
                'itemsProvider' => SurveyItemsProvider::class,
                'skinBasePath' => MUBLO_PLUGIN_PATH . '/Survey/views/Block',
                // 기간·상태·응답 통계가 요청 사이에도 변한다. 행 HTML 캐시에 넣지 않는다.
                'noCache'      => true,
            ]
        );
    }

    public function install(DependencyContainer $container, Context $context): void
    {
        $runner = $container->get(MigrationRunner::class);
        $runner->run('plugin', 'Survey', MUBLO_PLUGIN_PATH . '/Survey/database/migrations');
    }

    public function uninstall(DependencyContainer $container, Context $context): void {}

    public function getResetCategories(): array { return $this->dataResetter->getResetCategories(); }
    public function reset(string $category, int $domainId): DataResetResult { return $this->dataResetter->reset($category, $domainId); }
}
