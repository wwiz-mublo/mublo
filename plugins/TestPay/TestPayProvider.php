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
    public function register(DependencyContainer $container): void
    {
        // TestPayGateway는 의존성 없이 단순 생성
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        // 프로덕션 환경에서는 '모든 결제 즉시 성공' 가상 게이트웨이를 절대 등록하지 않는다.
        // 활성화 여부와 무관하게, 운영 도메인에 켜지더라도 게이트웨이 자체가
        // 등록되지 않아 결제 위조가 불가능하도록 방어한다.
        if (env('APP_ENV', 'production') === 'production') {
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
