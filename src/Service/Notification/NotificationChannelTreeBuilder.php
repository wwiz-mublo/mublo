<?php

namespace Mublo\Service\Notification;

use Mublo\Contract\Notification\CollectNotificationChannelTreeEvent;
use Mublo\Contract\Notification\NotificationChannelTreeBuilderInterface;
use Mublo\Contract\Notification\NotificationGatewayInterface;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Registry\ContractRegistry;

/**
 * 알림 채널 트리 조립기 (코어 — 조립 계층 단일 구현)
 *
 * "이메일 기본 + NotificationGatewayInterface 레지스트리 순회" 조립을 한 곳으로
 * 모은다. 과거에는 AutoForm 액션 설정 UI 와 Mshop FSM 템플릿 드롭다운이 같은
 * 조립을 각자 복제했고, 훅 이벤트가 AutoForm 내부라 화면마다 확장 반영이
 * 어긋날 수 있었다 — 이 빌더가 그 복제와 불일치를 해소한다.
 *
 * 조립 직후 CollectNotificationChannelTreeEvent 를 dispatch 하므로, 구독자의
 * 보조 공급·가공이 빌더를 쓰는 모든 소비 화면에 균일하게 반영된다.
 *
 * 채널 타입 키는 코어 표준(email/sms/alimtalk) 그대로 반환한다 — UI 별 표기
 * 변환은 소비자의 몫.
 */
class NotificationChannelTreeBuilder implements NotificationChannelTreeBuilderInterface
{
    public function __construct(
        private ContractRegistry $registry,
        private ?EventDispatcher $eventDispatcher = null,
    ) {}

    /**
     * @return array<int, array{key: string, type: string, type_label: string, plugin_id: string|null, plugin_label: string, channels: array}>
     */
    public function build(int $domainId): array
    {
        $event = new CollectNotificationChannelTreeEvent();

        // 1) 이메일 기본 — 게이트웨이(코어 'core_email' 등)가 email 을 공급하면
        //    아래 2.5)에서 이 폴백 항목을 제거한다 (레지스트리 미구성 환경 안전망)
        $event->addProvider('email', null, '이메일');

        // 2) ContractRegistry 자동 인식 (게이트웨이 계약 — 벤더 공급의 유일 통로)
        try {
            foreach ($this->registry->keys(NotificationGatewayInterface::class) as $pluginId) {
                $meta = $this->registry->getMeta(NotificationGatewayInterface::class, $pluginId);
                $label = (string) ($meta['label'] ?? $pluginId);

                try {
                    $gateway = $this->registry->get(NotificationGatewayInterface::class, $pluginId);
                    $tree = $gateway->getChannelTree($domainId);
                } catch (\Throwable) {
                    continue; // 미설치 테이블 등 — 해당 게이트웨이만 건너뜀
                }

                foreach ($tree as $type => $node) {
                    if (!is_array($node)) {
                        continue;
                    }
                    $event->addProvider(
                        (string) $type,
                        $pluginId,
                        $label,
                        $this->normalizeChannels($node['channels'] ?? []),
                        (string) ($node['label'] ?? '')
                    );
                }
            }
        } catch (\Throwable) {
            // ContractRegistry 미설치 환경 fallback — 이메일 기본만 유지
        }

        // 2.5) 게이트웨이가 email 을 공급했으면 1)의 폴백 항목 제거 — 채널당 공급자 중복 방지
        $providers = $event->getProviders();
        $hasGatewayEmail = false;
        foreach ($providers as $p) {
            if (($p['type'] ?? '') === 'email' && ($p['plugin_id'] ?? null) !== null) {
                $hasGatewayEmail = true;
                break;
            }
        }
        if ($hasGatewayEmail) {
            $event->setProviders(array_values(array_filter(
                $providers,
                static fn(array $p) => !((($p['type'] ?? '') === 'email') && (($p['plugin_id'] ?? null) === null))
            )));
        }

        // 3) 조립 훅 — 보조 공급·가공 (구독자 없으면 무변)
        if ($this->eventDispatcher !== null) {
            $this->eventDispatcher->dispatch($event);
        }

        return $event->getProviders();
    }

    /**
     * 게이트웨이 네이티브 노드({id,name,templates:[{code,name}]}) → 중립 형태
     * 공급자 추가 필드(admin_recipients, message_body 등)는 그대로 통과시킨다.
     */
    private function normalizeChannels(array $channels): array
    {
        $normalized = [];
        foreach ($channels as $ch) {
            if (!is_array($ch)) {
                continue;
            }

            $templates = [];
            foreach (($ch['templates'] ?? []) as $tpl) {
                if (!is_array($tpl)) {
                    continue;
                }
                $templates[] = array_merge($tpl, [
                    'template_code' => (string) ($tpl['code'] ?? $tpl['template_code'] ?? ''),
                    'template_name' => (string) ($tpl['name'] ?? $tpl['template_name'] ?? ''),
                ]);
            }

            $normalized[] = [
                'channel_id' => (int) ($ch['id'] ?? $ch['channel_id'] ?? 0),
                'channel_name' => (string) ($ch['name'] ?? $ch['channel_name'] ?? ''),
                'templates' => $templates,
            ];
        }

        return $normalized;
    }
}
