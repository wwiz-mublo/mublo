<?php
declare(strict_types=1);

namespace Mublo\Contract\Fcm;

/**
 * 전화번호 ↔ 업무용 기기 연결 계약.
 *
 * "이 고객(전화번호)은 어느 매장·담당자의 기기가 맡는가" 라는 사실을 업무 기기를 관리하는
 * 확장에 맡긴다. 주문·상담 같은 패키지는 FCM·기기 저장 방식을 몰라도 이 계약만으로
 * 연결을 조회·변경할 수 있다.
 *
 * 기기 ID 는 구현 확장이 관리하는 업무 기기(앱 설치)의 식별자다. 같은 확장이
 * `FcmMessageServiceInterface` 도 구현한다면 그 Installation ID 와 같은 값을 쓰는 것을 권장한다.
 *
 * 구현 확장이 `ContractRegistry` 에 1:1 로 바인딩한다. 설치되지 않았을 수 있으므로
 * 소비자는 `has()` 로 먼저 확인한다.
 *
 * ```php
 * $binding = $registry->resolve(DeviceBindingInterface::class);
 * if ($binding->resolveDeviceForPhone($domainId, $phone) === null) {
 *     $binding->bind($domainId, $phone, $primaryDeviceId, ['order_id' => $orderId]);
 * }
 * ```
 */
interface DeviceBindingInterface
{
    /**
     * 전화번호에 연결된 기기 ID.
     *
     * @param string $phone 정규화하지 않은 원문도 받는다 — 정규화는 구현체 책임
     * @return int|null 연결이 없으면 null
     */
    public function resolveDeviceForPhone(int $domainId, string $phone): ?int;

    /**
     * 연결 생성 또는 사용 시각 갱신.
     *
     * 같은 (domainId, phone) 연결이 이미 있으면 기기를 바꾸지 않고 마지막 사용 시각만 갱신한다.
     * 기기를 바꾸려면 rebind() 를 쓴다.
     *
     * @param array<string, mixed> $meta 추적용 값 (예: ['order_id' => int, 'consultation_id' => int])
     */
    public function bind(int $domainId, string $phone, int $deviceId, array $meta = []): void;

    /**
     * 연결 해제.
     */
    public function unbind(int $domainId, string $phone): void;

    /**
     * 기존 연결을 다른 기기로 옮긴다 (기기 고장·담당 이관 등 명시적 재배정).
     */
    public function rebind(int $domainId, string $phone, int $newDeviceId): void;
}
