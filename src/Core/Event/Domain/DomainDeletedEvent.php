<?php
declare(strict_types=1);

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
     * 삭제된 도메인 이름 (삭제 전 스냅샷)
     *
     * 행이 이미 사라졌으므로 이 값은 다시 조회할 수 없다. 호스트 기준으로 캐시·파일을
     * 정리하는 구독자를 위해 남긴다.
     */
    public function getDomainName(): string
    {
        return $this->deletedDomain->getDomain();
    }
}
