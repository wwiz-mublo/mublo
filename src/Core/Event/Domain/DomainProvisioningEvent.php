<?php
declare(strict_types=1);

namespace Mublo\Core\Event\Domain;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Event\FailFastEventInterface;

/**
 * 도메인 생성 트랜잭션 안에서 실행되는 필수 초기화 이벤트.
 *
 * 이 이벤트의 리스너가 실패하면 도메인·소유자 변경과 필수 시딩을
 * 함께 롤백해야 하므로 항상 fail-fast로 동작한다. 확장 패키지나 시작 킷처럼
 * 선택적인 후처리는 커밋 이후 DomainCreatedEvent에서 실행한다.
 */
class DomainProvisioningEvent extends AbstractEvent implements FailFastEventInterface
{
    public function __construct(
        private int $domainId,
        private string $domainGroup,
        private ?int $ownerId = null,
        private ?int $createdBy = null
    ) {
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getDomainGroup(): string
    {
        return $this->domainGroup;
    }

    public function getOwnerId(): ?int
    {
        return $this->ownerId;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }
}
