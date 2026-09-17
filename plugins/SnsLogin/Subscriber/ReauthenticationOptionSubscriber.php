<?php
declare(strict_types=1);
namespace Mublo\Plugin\SnsLogin\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Member\ReauthenticationOptionsRenderingEvent;
use Mublo\Plugin\SnsLogin\Repository\SnsAccountRepository;
use Mublo\Plugin\SnsLogin\SnsProviderRegistry;

/**
 * 민감한 작업 앞의 본인 확인 수단으로 "연결된 SNS 로 확인" 을 내놓는다.
 *
 * 자기 비밀번호를 모르는 회원(SNS 로만 가입한 계정)은 코어가 가진 유일한 수단인
 * 현재 비밀번호 입력을 쓸 수 없다. 그 회원이 들어올 때 쓴 문으로 다시 확인받게 한다.
 */
class ReauthenticationOptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SnsAccountRepository $accounts,
        private SnsProviderRegistry $registry,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ReauthenticationOptionsRenderingEvent::class => 'onReauthenticationOptions',
        ];
    }

    public function onReauthenticationOptions(ReauthenticationOptionsRenderingEvent $event): void
    {
        // 이미 확인이 끝났으면 수단을 더 보여줄 이유가 없다.
        if ($event->isConfirmed()) {
            return;
        }

        try {
            $accounts = $this->accounts->findByMember($event->getMemberId());
        } catch (\Throwable) {
            // 설치 전이거나 조회가 실패해도 회원정보 화면 자체는 떠야 한다.
            return;
        }

        $request  = $event->getContext()->getRequest();
        $redirect = $request->getPath();
        if (!is_string($redirect) || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            $redirect = '/mypage/profile';
        }

        $buttons = '';
        foreach ($accounts as $account) {
            $provider = $this->registry->get($account->getProvider());

            // 관리자가 제공자를 껐거나 키를 지우면 재인증을 시작할 수 없다.
            // 누를 수 없는 버튼을 보여주는 대신 내놓지 않는다.
            if (!$provider) {
                continue;
            }

            $buttons .= sprintf(
                '<a href="/sns-login/reauth/%s?redirect=%s" class="btn-sns %s"><span>%s로 본인 확인</span></a>',
                rawurlencode($provider->getName()),
                rawurlencode($redirect),
                htmlspecialchars($provider->getButtonClass()),
                htmlspecialchars($provider->getLabel()),
            );
        }

        if ($buttons === '') {
            return;
        }

        $event->addHtml('<div class="sns-login-buttons">' . $buttons . '</div>', 50);
    }
}
