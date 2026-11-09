<?php
declare(strict_types=1);

namespace Mublo\Plugin\TestPay;

use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Contract\Payment\PaymentGatewayInterface;

class TestPayProvider implements ExtensionProviderInterface
{
    /**
     * 게이트웨이 등록을 허용하는 APP_ENV 값.
     *
     * 화이트리스트로 둔다. `!== 'production'` 방식이면 오타(APP_ENV=prod 등)나
     * staging 같은 운영급 환경이 곧 '공짜 결제 허용'이 된다. 모르는 값은 거부한다(fail-closed).
     * 코어 DomainVerificationService::DEV_LOCAL_ENVS 와 같은 목록이다.
     */
    private const ALLOWED_ENVS = ['local', 'development', 'dev', 'testing'];

    public static function isAllowedEnvironment(string $appEnv): bool
    {
        return in_array($appEnv, self::ALLOWED_ENVS, true);
    }

    public function register(DependencyContainer $container): void
    {
        // TestPayGateway는 의존성 없이 단순 생성
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        // 개발 환경이 아니면 '모든 결제 즉시 성공' 가상 게이트웨이를 절대 등록하지 않는다.
        // 활성화 여부와 무관하게, 운영 도메인에 켜지더라도 게이트웨이 자체가
        // 등록되지 않아 결제 위조가 불가능하도록 방어한다.
        if (!self::isAllowedEnvironment((string) env('APP_ENV', 'production'))) {
            return;
        }

        $registry = $container->get(ContractRegistry::class);

        $registry->register(
            PaymentGatewayInterface::class,
            'testpay',
            fn() => new TestPayGateway(),
            [
                'label' => '테스트 결제',
                'icon'  => 'bi-credit-card',
                'description' => '개발/테스트용 가상 결제',
            ]
        );
    }
}
