<?php

namespace Mublo\Contract\Notification;

use Mublo\Core\Event\AbstractEvent;

/**
 * 알림 채널 트리 조립 훅 이벤트 (코어 — 조립 계층)
 *
 * NotificationChannelTreeBuilder 가 "이메일 기본 + NotificationGatewayInterface
 * 레지스트리 순회"로 트리를 조립한 직후 dispatch 한다. 구독자는
 *  - addProvider() 로 게이트웨이 계약으로 표현되지 않는 특수 공급자를 보태거나
 *  - getProviders()/setProviders() 로 조립 결과를 가공(필터·정렬·라벨 변경)할 수 있다.
 *
 * ── 계층 구분 (중복 통로 아님) ─────────────────────────────────────────
 * 공급 계층: NotificationGatewayInterface.getChannelTree() — 벤더당 유일 통로
 * 조립 계층: NotificationChannelTreeBuilder — 소비자 공통 조립 (단일 구현)
 * 훅 계층:   이 이벤트 — 조립 결과에 대한 보조 공급·가공 (조립 계층의 유일 훅)
 * 소비자:    AutoForm 액션 설정 UI, Mshop FSM 템플릿 드롭다운 등 — 빌더 경유로
 *            훅이 모든 소비 화면에 균일하게 반영된다.
 * ───────────────────────────────────────────────────────────────────────
 *
 * 채널 타입 키는 코어 표준(email / sms / alimtalk)을 쓴다 — UI 별 표기 변환
 * (예: AutoForm 의 alimtalk→kakao)은 각 소비자의 몫이다.
 *
 * 이메일은 빌더가 기본 등록. 같은 type 에 여러 공급자 등록 가능, 중복 방지 키는 plugin_id.
 */
class CollectNotificationChannelTreeEvent extends AbstractEvent
{
    /** @var array<int, array{key: string, type: string, type_label: string, plugin_id: string|null, plugin_label: string, channels: array}> */
    private array $providers = [];

    /** @var array<string, bool> 등록된 key 추적 (plugin_id 또는 type) */
    private array $registeredKeys = [];

    /**
     * 알림 채널 트리 등록
     *
     * @param string      $type         채널 타입 (코어 표준: email, sms, alimtalk)
     * @param string|null $pluginId     플러그인 식별자 (email 기본 공급은 null)
     * @param string      $pluginLabel  공급자 표시 라벨 (예: '센드온 알림톡')
     * @param array       $channels     채널 목록
     *   [
     *     [
     *       'channel_id'   => int,
     *       'channel_name' => string,
     *       'templates'    => [
     *         ['template_code' => string, 'template_name' => string, ...공급자 추가 필드 통과],
     *       ]
     *     ]
     *   ]
     * @param string      $typeLabel    채널 타입 표시 라벨 (예: '카카오 알림톡' — 비우면 pluginLabel 사용)
     */
    public function addProvider(string $type, ?string $pluginId, string $pluginLabel, array $channels = [], string $typeLabel = ''): void
    {
        // plugin_id 기준 중복 방지 (이메일 기본 공급은 type 키)
        $key = $pluginId ?: $type;
        if (isset($this->registeredKeys[$key])) {
            return;
        }
        $this->registeredKeys[$key] = true;

        $this->providers[] = [
            'key' => $key,
            'type' => $type,
            'type_label' => $typeLabel !== '' ? $typeLabel : $pluginLabel,
            'plugin_id' => $pluginId,
            'plugin_label' => $pluginLabel,
            'channels' => $channels,
        ];
    }

    /**
     * @return array<int, array{key: string, type: string, type_label: string, plugin_id: string|null, plugin_label: string, channels: array}>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * 조립 결과 가공 (필터·정렬·라벨 변경 등)
     *
     * @param array<int, array<string, mixed>> $providers
     */
    public function setProviders(array $providers): void
    {
        $this->providers = $providers;
        $this->registeredKeys = [];
        foreach ($providers as $provider) {
            $key = (string) ($provider['key'] ?? ($provider['plugin_id'] ?? $provider['type'] ?? ''));
            if ($key !== '') {
                $this->registeredKeys[$key] = true;
            }
        }
    }
}
