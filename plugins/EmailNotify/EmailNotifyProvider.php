<?php
declare(strict_types=1);

namespace Mublo\Plugin\EmailNotify;

use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Contract\Notification\EmailTemplateProviderInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Mail\Mailer;
use Mublo\Plugin\EmailNotify\Controller\Admin\EmailNotifyController;
use Mublo\Plugin\EmailNotify\Repository\EmailConfigRepository;
use Mublo\Plugin\EmailNotify\Repository\EmailLogRepository;
use Mublo\Plugin\EmailNotify\Repository\EmailTemplateRepository;
use Mublo\Plugin\EmailNotify\Service\EmailNotifyService;
use Mublo\Plugin\EmailNotify\Service\EmailNotifyDataResetter;
use Mublo\Contract\Site\DomainQueryInterface;

class EmailNotifyProvider implements ExtensionProviderInterface, DataResettableInterface
{
    private EmailNotifyDataResetter $dataResetter;

    public function register(DependencyContainer $container): void
    {
        $container->singleton(EmailNotifyDataResetter::class, fn(DependencyContainer $c) => new EmailNotifyDataResetter($c->get(Database::class)));
        $container->singleton(EmailConfigRepository::class, fn(DependencyContainer $c) =>
            new EmailConfigRepository($c->get(Database::class))
        );

        $container->singleton(EmailTemplateRepository::class, fn(DependencyContainer $c) =>
            new EmailTemplateRepository($c->get(Database::class))
        );

        $container->singleton(EmailLogRepository::class, fn(DependencyContainer $c) =>
            new EmailLogRepository($c->get(Database::class))
        );

        $container->singleton(EmailNotifyService::class, fn(DependencyContainer $c) =>
            new EmailNotifyService(
                $c->get(EmailConfigRepository::class),
                $c->get(EmailTemplateRepository::class),
                $c->get(EmailLogRepository::class),
                $c->get(Mailer::class),
                $c->get(DomainQueryInterface::class),
                $c->get(ContractRegistry::class)
            )
        );

        $container->singleton(EmailNotifyController::class, fn(DependencyContainer $c) =>
            new EmailNotifyController(
                $c->get(EmailNotifyService::class),
                $c->get(MigrationRunner::class),
                $c->get(EventDispatcher::class)
            )
        );
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $this->dataResetter = $container->get(EmailNotifyDataResetter::class);
        $eventDispatcher = $container->get(EventDispatcher::class);
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());

        // 발송(전송로)은 코어 'core_email' 게이트웨이가 담당한다.
        // 이 플러그인은 템플릿 공급자 계약으로 내용물(템플릿·발신자 설정)과
        // 발송 이력 기록만 공급한다.
        $registry = $container->get(ContractRegistry::class);
        $registry->register(
            EmailTemplateProviderInterface::class,
            'email_notify',
            fn() => new EmailTemplateProvider($container->get(EmailNotifyService::class)),
            [
                'label' => '이메일 템플릿',
                'icon' => 'bi-envelope-paper-heart',
                'description' => '이메일 템플릿 관리·렌더링 공급 (발송은 코어 게이트웨이)',
            ]
        );
    }

    public function getResetCategories(): array { return $this->dataResetter->getResetCategories(); }
    public function reset(string $category, int $domainId): DataResetResult { return $this->dataResetter->reset($category, $domainId); }
}
