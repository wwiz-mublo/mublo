<?php

namespace Mublo\Core\Event\Domain;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Entity\Domain\Domain;

/**
 * 도메인 삭제 완료 이벤트
 *
 * DomainService::delete() 성공 후 발행
 * 관련 데이터 정리, 파일 삭제 등에 활용
 */
class DomainDeletedEvent extends AbstractEvent
{
    private int $domainId;
    private Domain $deletedDomain;

    public function __construct(int $domainId, Domain $deletedDomain)
    {
        $this->domainId = $domainId;
        $this->deletedDomain = $deletedDomain;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    /**
     * 삭제된 도메인 엔티티 (삭제 전 스냅샷)
     */
    public function getDeletedDomain(): Domain
    {
        return $this->deletedDomain;
    }
}
