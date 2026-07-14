<?php
namespace Mublo\Plugin\SnsLogin\Subscriber;

use Mublo\Core\Event\Auth\LoginFormRenderingEvent;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Plugin\SnsLogin\SnsProviderRegistry;

/**
 * 로그인 폼에 SNS 로그인 버튼 주입
 */
class LoginFormSubscriber implements EventSubscriberInterface
{
    public function __construct(private SnsProviderRegistry $registry) {}

    public static function getSubscribedEvents(): array
    {
        return [
            LoginFormRenderingEvent::class => 'onLoginFormRendering',
        ];
    }

    public function onLoginFormRendering(LoginFormRenderingEvent $event): void
    {
        $providers = $this->registry->getActiveProviders();

        if (empty($providers)) {
            return;
        }

        // 로그인 페이지의 redirect 파라미터를 SNS 로그인 URL에 전달
        $redirect = $event->getContext()->getRequest()->get('redirect', '/');
        $redirectQuery = ($redirect && $redirect !== '/')
            ? '?redirect=' . urlencode($redirect)
            : '';

        $buttons = '';
        foreach ($providers as $provider) {
            $buttons .= sprintf(
                '<a href="/sns-login/auth/%s%s" class="btn-sns %s"><span>%s로 로그인</span></a>',
                htmlspecialchars($provider->getName()),
                $redirectQuery,
                htmlspecialchars($provider->getButtonClass()),
                htmlspecialchars($provider->getLabel())
            );
        }

        $html = <<<HTML
<div class="sns-login-section">
    <div class="sns-login-divider"><span>또는</span></div>
    <div class="sns-login-buttons">
        {$buttons}
    </div>
</div>
HTML;

        $event->addHtml($html, 50);
    }
}
