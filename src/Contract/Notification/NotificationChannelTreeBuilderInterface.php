<?php

namespace Mublo\Contract\Notification;

/**
 * 알림 채널 트리 조립기 공개 계약 (조립 계층)
 *
 * 확장(AutoForm 액션 UI, Mshop FSM 드롭다운 등)이 채널·템플릿 트리를 얻는
 * 유일한 안정 통로. 구현(코어 NotificationChannelTreeBuilder)은
 * "이메일 기본 + NotificationGatewayInterface 레지스트리 순회"로 조립한 뒤
 * CollectNotificationChannelTreeEvent 를 dispatch 해 보조 공급·가공을 반영한다.
 *
 * 채널 타입 키는 코어 표준(email/sms/alimtalk) — UI 별 표기 변환은 소비자의 몫.
 */
interface NotificationChannelTreeBuilderInterface
{
    /**
     * @return array<int, array{key: string, type: string, type_label: string, plugin_id: string|null, plugin_label: string, channels: array}>
     */
    public function build(int $domainId): array;
}
