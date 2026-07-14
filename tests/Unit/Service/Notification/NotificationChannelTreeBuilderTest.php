<?php

namespace Tests\Unit\Service\Notification;

use Mublo\Contract\Notification\CollectNotificationChannelTreeEvent;
use Mublo\Contract\Notification\NotificationGatewayInterface;
use Mublo\Contract\Notification\NotificationSendResult;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Service\Notification\NotificationChannelTreeBuilder;
use PHPUnit\Framework\TestCase;

/**
 * 채널 트리 조립기 (회귀) — 계층 분리 승격의 계약:
 * 이메일 기본 + 게이트웨이 레지스트리 순회 + 조립 훅 이벤트가
 * 소비자(AutoForm·Mshop FSM)와 무관하게 한 곳에서 동일하게 동작해야 한다.
 */
class NotificationChannelTreeBuilderTest extends TestCase
{
    private function fakeGateway(): NotificationGatewayInterface
    {
        return new class implements NotificationGatewayInterface {
            public function send(string $channel, string $templateCode, string $recipient, array $fieldValues): NotificationSendResult
            {
                return new NotificationSendResult(true);
            }

            public function getSupportedChannels(): array
            {
                return ['alimtalk' => '카카오 알림톡'];
            }

            public function getChannelTree(int $domainId): array
            {
                return [
                    'alimtalk' => [
                        'label' => '카카오 알림톡',
                        'channels' => [
                            [
                                'id' => 3,
                                'name' => '기본채널',
                                'templates' => [
                                    ['code' => 'tpl_order', 'name' => '주문접수 ✅'],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };
    }

    private function registryWith(NotificationGatewayInterface $gateway): ContractRegistry
    {
        $registry = $this->createMock(ContractRegistry::class);
        $registry->method('keys')->willReturn(['sendon_talk']);
        $registry->method('getMeta')->willReturn(['channels' => ['alimtalk'], 'label' => '센드온 알림톡']);
        $registry->method('get')->willReturn($gateway);
        return $registry;
    }

    public function testBuildAssemblesEmailAndGatewayProviders(): void
    {
        $builder = new NotificationChannelTreeBuilder($this->registryWith($this->fakeGateway()));

        $providers = $builder->build(1);

        $this->assertCount(2, $providers);
        // 이메일 기본
        $this->assertSame('email', $providers[0]['type']);
        // 게이트웨이 — 코어 표준 타입 키 유지(alimtalk), 타입 라벨과 중립 템플릿 형태
        $this->assertSame('alimtalk', $providers[1]['type']);
        $this->assertSame('카카오 알림톡', $providers[1]['type_label']);
        $this->assertSame('sendon_talk', $providers[1]['plugin_id']);
        $this->assertSame('기본채널', $providers[1]['channels'][0]['channel_name']);
        $this->assertSame('tpl_order', $providers[1]['channels'][0]['templates'][0]['template_code']);
        $this->assertSame('주문접수 ✅', $providers[1]['channels'][0]['templates'][0]['template_name']);
    }

    public function testAssemblyHookCanSupplementAndReshapeTree(): void
    {
        $dispatcher = $this->createMock(EventDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) {
            if ($event instanceof CollectNotificationChannelTreeEvent) {
                // 보조 공급 (게이트웨이 계약으로 표현되지 않는 특수 공급자)
                $event->addProvider('sms', 'custom_sms', '커스텀 SMS', [
                    ['channel_id' => 9, 'channel_name' => '특수채널', 'templates' => []],
                ]);
                // 가공 — 이메일 제거
                $event->setProviders(array_values(array_filter(
                    $event->getProviders(),
                    static fn(array $p) => ($p['type'] ?? '') !== 'email'
                )));
            }
            return $event;
        });

        $builder = new NotificationChannelTreeBuilder($this->registryWith($this->fakeGateway()), $dispatcher);

        $providers = $builder->build(1);

        $types = array_column($providers, 'type');
        $this->assertNotContains('email', $types);   // 가공 반영
        $this->assertContains('sms', $types);         // 보조 공급 반영
        $this->assertContains('alimtalk', $types);
    }

    public function testGatewayEmailReplacesDefaultFallback(): void
    {
        $emailGateway = new class implements NotificationGatewayInterface {
            public function send(string $channel, string $templateCode, string $recipient, array $fieldValues): NotificationSendResult
            {
                return new NotificationSendResult(true);
            }

            public function getSupportedChannels(): array
            {
                return ['email' => '이메일'];
            }

            public function getChannelTree(int $domainId): array
            {
                return [
                    'email' => [
                        'label' => '이메일',
                        'channels' => [
                            ['id' => 0, 'name' => '이메일', 'templates' => [['code' => 'welcome', 'name' => '환영 메일']]],
                        ],
                    ],
                ];
            }
        };

        $registry = $this->createMock(ContractRegistry::class);
        $registry->method('keys')->willReturn(['core_email']);
        $registry->method('getMeta')->willReturn(['channels' => ['email'], 'label' => '이메일']);
        $registry->method('get')->willReturn($emailGateway);

        $providers = (new NotificationChannelTreeBuilder($registry))->build(1);

        // 게이트웨이 공급 이메일이 폴백 기본 항목을 대체 — email 공급자는 정확히 하나
        $emails = array_values(array_filter($providers, static fn(array $p) => ($p['type'] ?? '') === 'email'));
        $this->assertCount(1, $emails);
        $this->assertSame('core_email', $emails[0]['plugin_id']);
        $this->assertSame('welcome', $emails[0]['channels'][0]['templates'][0]['template_code']);
    }

    public function testGatewayFailureSkipsOnlyThatGateway(): void
    {
        $broken = new class implements NotificationGatewayInterface {
            public function send(string $channel, string $templateCode, string $recipient, array $fieldValues): NotificationSendResult
            {
                return new NotificationSendResult(false);
            }

            public function getSupportedChannels(): array
            {
                return ['sms' => 'SMS'];
            }

            public function getChannelTree(int $domainId): array
            {
                throw new \RuntimeException('table missing');
            }
        };

        $registry = $this->createMock(ContractRegistry::class);
        $registry->method('keys')->willReturn(['broken']);
        $registry->method('getMeta')->willReturn(['channels' => ['sms'], 'label' => '고장난 게이트웨이']);
        $registry->method('get')->willReturn($broken);

        $providers = (new NotificationChannelTreeBuilder($registry))->build(1);

        // 고장 게이트웨이만 건너뛰고 이메일 기본은 유지
        $this->assertCount(1, $providers);
        $this->assertSame('email', $providers[0]['type']);
    }
}
