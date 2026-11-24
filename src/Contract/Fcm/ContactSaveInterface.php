<?php
declare(strict_types=1);

namespace Mublo\Contract\Fcm;

use Mublo\Core\Result\Result;

/**
 * 고객 연락처를 업무용 기기(앱) 전화번호부에 저장하도록 요청하는 계약.
 *
 * 주문·상담 화면을 가진 패키지가 FCM·앱 세부를 몰라도 "이 번호를 업무폰에 저장해 줘" 라고
 * 요청할 수 있게 한다. 대상 기기는 `DeviceBindingInterface::resolveDeviceForPhone()` 결과를
 * 따르고, 연결이 없으면 구현체가 주 기기를 골라 새 연결을 만든다.
 *
 * 구현 확장이 `ContractRegistry` 에 1:1 로 바인딩한다. 설치되지 않았을 수 있으므로
 * 소비자는 `has()` 로 먼저 확인한다.
 */
interface ContactSaveInterface
{
    /**
     * 전화번호부 저장 요청을 만들고 대상 기기에 푸시를 보낸다.
     *
     * @param string $name 전화번호부에 표시할 이름
     * @param array<string, mixed> $meta [
     *   'order_id' => int, 'consultation_id' => int, 'requested_by' => int(member_id),
     *   'overwrite' => bool, 'device_id' => int (지정하면 그 기기로만 보낸다)
     * ]
     * @return Result data: ['contact_id' => int, 'device_id' => int, 'status' => 'pending'|'sent'|'saved'|'failed']
     */
    public function saveContact(int $domainId, string $phone, string $name, array $meta = []): Result;
}
