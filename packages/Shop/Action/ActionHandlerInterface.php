<?php

namespace Mublo\Packages\Shop\Action;

use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;

/**
 * ActionHandlerInterface
 *
 * 주문 상태 변경 시 실행되는 액션 핸들러 계약
 *
 * 구현체 예:
 * - NotificationActionHandler: 알림톡/SMS/이메일 발송
 * - PointActionHandler: 포인트 적립
 * - WebhookActionHandler: 외부 웹훅 호출
 *
 * Plugin 확장:
 * - Plugin에서 ActionTypeRegistry에 커스텀 핸들러 등록 가능
 */
interface ActionHandlerInterface
{
    /**
     * 액션 실행
     *
     * @param array $config 액션 설정 (type, enabled, 타입별 파라미터)
     * @param OrderStatusChangedEvent $event 상태 변경 이벤트
     */
    public function execute(array $config, OrderStatusChangedEvent $event): void;

    /**
     * 액션 타입 식별자
     *
     * @return string 예: 'notification', 'point', 'webhook'
     */
    public function getType(): string;

    /**
     * 액션 타입 표시 라벨
     *
     * @return string 예: '알림 발송', '포인트 적립', '웹훅 호출'
     */
    public function getLabel(): string;

    /**
     * 관리자 UI 설명 (액션 모달에서 표시)
     */
    public function getDescription(): string;

    /**
     * 액션 파라미터 스키마
     *
     * Config 저장 시 필수 파라미터 검증에 사용
     *
     * @return array ['required' => [...], 'fields' => [...]]
     */
    public function getSchema(): array;

    /**
     * 동일 상태에 같은 타입의 액션을 여러 번 등록할 수 있는지 여부
     *
     * false: 상태당 1개만 (포인트, 주문종료 등)
     * true: 다중 등록 가능 (알림, 웹훅 등 — 채널/엔드포인트별)
     */
    public function allowDuplicate(): bool;
}
