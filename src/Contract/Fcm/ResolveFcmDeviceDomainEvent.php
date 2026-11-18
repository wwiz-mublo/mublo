<?php
declare(strict_types=1);

namespace Mublo\Contract\Fcm;

use Mublo\Core\Event\AbstractEvent;

/**
 * "이 도메인의 업무 기기 알림은 어느 도메인의 기기로 보내는가" 질의 이벤트.
 *
 * 업무 기기를 관리하는 확장(관리자 채널·템플릿 화면, 발송 경로)이 발행하고, 도메인 관계를 아는
 * 패키지가 답한다. 예: 본사가 직영하는 몰은 본사 공용 기기로 받으므로 본사 도메인을 돌려준다.
 *
 * 아무도 답하지 않으면 요청한 도메인 그대로다(자기 기기). 0 이하 값은 무시한다.
 */
class ResolveFcmDeviceDomainEvent extends AbstractEvent
{
    private int $deviceDomainId;

    public function __construct(private readonly int $domainId)
    {
        $this->deviceDomainId = $domainId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getDeviceDomainId(): int
    {
        return $this->deviceDomainId;
    }

    public function setDeviceDomainId(int $deviceDomainId): void
    {
        if ($deviceDomainId > 0) {
            $this->deviceDomainId = $deviceDomainId;
        }
    }

    /** 다른 도메인(본사 공용 기기 등)의 기기를 쓰는지 */
    public function isShared(): bool
    {
        return $this->deviceDomainId !== $this->domainId;
    }
}
