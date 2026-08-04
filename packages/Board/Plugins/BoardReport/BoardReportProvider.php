<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Plugins\BoardReport;

use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\InstallableExtensionInterface;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\Board\Plugins\BoardReport\Repository\BoardReportRepository;
use Mublo\Packages\Board\Plugins\BoardReport\Service\BoardReportService;
use Mublo\Packages\Board\Plugins\BoardReport\Service\BoardReportDataResetter;
use Mublo\Packages\Board\Plugins\BoardReport\Subscriber\AdminMenuSubscriber;
use Mublo\Packages\Board\Plugins\BoardReport\Subscriber\ArticleSubscriber;
use Mublo\Packages\Board\Contract\Extension\BoardExtensionApiInterface;
use Mublo\Contract\Auth\AuthContextInterface;

/**
 * BoardReportProvider
 *
 * Board 패키지 종속 플러그인의 레퍼런스 구현.
 *
 * 종속 플러그인 규약 (NestedPlugin 참고):
 * - 위치:       packages/Board/Plugins/BoardReport/
 * - 활성 이름:  "Board/BoardReport" (부모가 꺼지면 함께 꺼진다)
 * - 부모 의존:  manifest 의 package:Board version 범위로 선언
 * - 부모 접근:  BoardExtensionApiInterface 공개 Contract만 사용
 */
class BoardReportProvider implements ExtensionProviderInterface, InstallableExtensionInterface, DataResettableInterface
{
    private BoardReportDataResetter $dataResetter;

    public function register(DependencyContainer $container): void
    {
        $container->singleton(BoardReportDataResetter::class, fn($c) => new BoardReportDataResetter($c->get(Database::class)));
        $container->singleton(BoardReportRepository::class, fn($c) =>
            new BoardReportRepository($c->get(Database::class))
        );

        $container->singleton(BoardReportService::class, fn($c) =>
            new BoardReportService(
                $c->get(BoardReportRepository::class),
                $c->get(BoardExtensionApiInterface::class)
            )
        );
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $this->dataResetter = $container->get(BoardReportDataResetter::class);
        $eventDispatcher = $container->get(EventDispatcher::class);

        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());
        $eventDispatcher->addSubscriber(new ArticleSubscriber(
            $container->get(BoardReportService::class),
            $container->get(AuthContextInterface::class)
        ));
    }

    public function install(DependencyContainer $container, Context $context): void
    {
        $runner = $container->get(MigrationRunner::class);
        $runner->run('plugin', 'Board/BoardReport', __DIR__ . '/database/migrations');
    }

    public function uninstall(DependencyContainer $container, Context $context): void
    {
        // 비활성화는 데이터를 보존한다 (재활성화 대비 — 확장 필수사항 §2)
    }

    public function getResetCategories(): array { return $this->dataResetter->getResetCategories(); }
    public function reset(string $category, int $domainId): DataResetResult { return $this->dataResetter->reset($category, $domainId); }
}
