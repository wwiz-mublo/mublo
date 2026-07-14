<?php

namespace Mublo\Core\Event\Domain;

use Mublo\Core\Event\AbstractEvent;

/**
 * 도메인 생성 완료 이벤트
 *
 * DomainService::create() 성공 후 발행
 * Plugin/Package가 도메인 생성 시 초기 데이터를 시딩하는 데 사용
 *
 * 구독 예시:
 * - PolicySubscriber: 기본 약관 시드
 * - MenuSubscriber: 기본 메뉴 시드
 * - ShopSubscriber: Shop 초기 설정
 */
class DomainCreatedEvent extends AbstractEvent
{
    private int $domainId;
    private string $domainGroup;
    private ?int $ownerId;
    private ?int $createdBy;

    public function __construct(int $domainId, string $domainGroup, ?int $ownerId = null, ?int $createdBy = null)
    {
        $this->domainId = $domainId;
        $this->domainGroup = $domainGroup;
        $this->ownerId = $ownerId;
        $this->createdBy = $createdBy;
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
