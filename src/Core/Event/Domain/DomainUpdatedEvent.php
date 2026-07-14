<?php

namespace Mublo\Core\Event\Domain;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Entity\Domain\Domain;

/**
 * 도메인 수정 완료 이벤트
 *
 * DomainService::update() 성공 후 발행
 * 캐시 무효화, 설정 변경 로그 등에 활용
 */
class DomainUpdatedEvent extends AbstractEvent
{
    private int $domainId;
    private Domain $previousDomain;
    private array $updatedFields;

    public function __construct(int $domainId, Domain $previousDomain, array $updatedFields)
    {
        $this->domainId = $domainId;
        $this->previousDomain = $previousDomain;
        $this->updatedFields = $updatedFields;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    /**
     * 수정 전 도메인 엔티티 (변경 전 스냅샷)
     */
    public function getPreviousDomain(): Domain
    {
        return $this->previousDomain;
    }

    /**
     * 변경된 필드 데이터 (prepareUpdateData 결과)
     */
    public function getUpdatedFields(): array
    {
        return $this->updatedFields;
    }
}
