<?php
declare(strict_types=1);
namespace Mublo\Plugin\Qna;

use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Contract\Menu\MenuManagementInterface;
use Mublo\Contract\Site\SiteProvisioningInterface;
use Mublo\Contract\Sitemap\SitemapUrlProviderInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\InstallableExtensionInterface;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Storage\SecureFileService;
use Mublo\Plugin\Qna\Controller\Admin\QnaAdminController;
use Mublo\Plugin\Qna\Controller\Front\QnaController;
use Mublo\Plugin\Qna\Repository\QnaCategoryRepository;
use Mublo\Plugin\Qna\Repository\QnaConfigRepository;
use Mublo\Plugin\Qna\Repository\QnaPostRepository;
use Mublo\Plugin\Qna\Service\QnaAttachmentService;
use Mublo\Plugin\Qna\Service\QnaService;
use Mublo\Plugin\Qna\Service\QnaDataResetter;
use Mublo\Plugin\Qna\Sitemap\QnaSitemapProvider;
use Mublo\Plugin\Qna\Subscriber\QnaFileAccessSubscriber;
use Mublo\Contract\Auth\AuthContextInterface;

/**
 * QnaProvider
 *
 * 질문과 답변(Q&A) 플러그인 Provider
 */
class QnaProvider implements ExtensionProviderInterface, InstallableExtensionInterface, DataResettableInterface
{
    private QnaDataResetter $dataResetter;

    public function register(DependencyContainer $container): void
    {
        $container->singleton(QnaDataResetter::class, fn($c) =>
            new QnaDataResetter($c->get(Database::class))
        );
        // Repository
        $container->singleton(QnaConfigRepository::class, fn ($c) => new QnaConfigRepository($c->get(Database::class)));
        $container->singleton(QnaCategoryRepository::class, fn ($c) => new QnaCategoryRepository($c->get(Database::class)));
        $container->singleton(QnaPostRepository::class, fn ($c) => new QnaPostRepository($c->get(Database::class)));

        // 첨부(보안 파일) — 코어 SecureFileService 위임
        $container->singleton(QnaAttachmentService::class, fn ($c) => new QnaAttachmentService(
            $c->get(SecureFileService::class),
        ));

        // Service
        $container->singleton(QnaService::class, fn ($c) => new QnaService(
            $c->get(QnaPostRepository::class),
            $c->get(QnaConfigRepository::class),
            $c->get(QnaAttachmentService::class),
        ));

        // Front Controller
        $container->singleton(QnaController::class, fn ($c) => new QnaController(
            $c->get(QnaService::class),
            $c->get(QnaConfigRepository::class),
            $c->get(AuthContextInterface::class),
        ));

        // Admin Controller
        $container->singleton(QnaAdminController::class, fn ($c) => new QnaAdminController(
            $c->get(QnaService::class),
            $c->get(MigrationRunner::class),
            $c->get(QnaConfigRepository::class),
            $c->get(AuthContextInterface::class),
        ));
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $this->dataResetter = $container->get(QnaDataResetter::class);
        $eventDispatcher = $container->get(EventDispatcher::class);
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());

        // 첨부 보안 다운로드 권한(category='qna') 판정
        $eventDispatcher->addSubscriber(new QnaFileAccessSubscriber(
            $container->get(QnaPostRepository::class),
            $container->get(AuthContextInterface::class),
        ));

        // 사이트맵 URL 제공자 — 실제로는 항상 빈 목록.
        // 문의는 작성자 본인/운영자만 열람 가능(QnaPost::canBeViewedBy)하고
        // 프론트 라우트 전체가 로그인 게이트라, 공개로 증명되는 URL 이 없다.
        // 상세 근거는 QnaSitemapProvider 주석 참조.
        $registry = $container->get(ContractRegistry::class);
        $registry->register(
            SitemapUrlProviderInterface::class,
            'qna',
            fn() => new QnaSitemapProvider(),
        );
    }

    /**
     * 첫 활성화 시: 마이그레이션 + 프론트 메뉴 등록
     */
    public function install(DependencyContainer $container, Context $context): void
    {
        $runner = $container->get(MigrationRunner::class);
        $runner->run('plugin', 'Qna', MUBLO_PLUGIN_PATH . '/Qna/database/migrations');

        $domainId = $context->getDomainId();

        // 재활성화 시 운영자가 수정한 라벨을 보존하며 메뉴 존재를 멱등 보장한다.
        $container->get(SiteProvisioningInterface::class)->ensureMenuItem($domainId, 'qna', [
            'label'         => 'Q&A',
            'url'           => '/qna',
            'provider_type' => 'plugin',
            'provider_name' => 'Qna',
        ]);
    }

    /**
     * 비활성화 시: 프론트 메뉴 삭제 (DB 데이터는 보존)
     */
    public function uninstall(DependencyContainer $container, Context $context): void
    {
        $menuManagement = $container->get(MenuManagementInterface::class);
        $domainId = $context->getDomainId();

        foreach ($menuManagement->findProviderMenus($domainId, 'plugin', 'Qna') as $item) {
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
