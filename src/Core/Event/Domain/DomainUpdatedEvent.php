<?php
declare(strict_types=1);

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
     * 수정 전 도메인 값 (변경 전 스냅샷)
     *
     * 갱신이 끝난 뒤라 옛 값은 다시 조회할 수 없다. 무엇이 바뀌었는지 비교하려는
     * 구독자를 위해 남긴다 — 새 값은 getUpdatedFields() 가 담는다.
     *
     * @return array 도메인 행의 컬럼 배열
     */
    public function getPreviousValues(): array
    {
        return $this->previousDomain->toArray();
    }

    /**
     * 변경된 필드 데이터 (prepareUpdateData 결과)
     */
    public function getUpdatedFields(): array
    {
        return $this->updatedFields;
    }
}
