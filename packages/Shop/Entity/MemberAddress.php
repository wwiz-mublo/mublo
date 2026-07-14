<?php

namespace Mublo\Packages\Shop\Entity;

use Mublo\Entity\BaseEntity;

/**
 * MemberAddress Entity
 *
 * 회원 배송지 주소록 엔티티 (shop_member_addresses 테이블)
 */
class MemberAddress extends BaseEntity
{
    protected int $addressId = 0;
    protected int $memberId = 0;
    protected int $domainId = 0;
    protected string $addressName = '';
    protected string $recipientName = '';
    protected string $recipientPhone = '';
    protected string $zipCode = '';
    protected string $address1 = '';
    protected string $address2 = '';
    protected bool $isDefault = false;

    private function __construct() {}

    protected function getPrimaryKeyField(): string
    {
        return 'addressId';
    }

    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->addressId = (int) ($data['address_id'] ?? 0);
        $entity->memberId = (int) ($data['member_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->addressName = $data['address_name'] ?? '';
        $entity->recipientName = $data['recipient_name'] ?? '';
        $entity->recipientPhone = $data['recipient_phone'] ?? '';
        $entity->zipCode = $data['zip_code'] ?? '';
        $entity->address1 = $data['address1'] ?? '';
        $entity->address2 = $data['address2'] ?? '';
        $entity->isDefault = (bool) ($data['is_default'] ?? false);
        $entity->createdAt = $data['created_at'] ?? '';
        $entity->updatedAt = $data['updated_at'] ?? null;

        return $entity;
    }

    public function toArray(): array
    {
        return [
            'address_id' => $this->addressId,
            'member_id' => $this->memberId,
            'domain_id' => $this->domainId,
            'address_name' => $this->addressName,
            'recipient_name' => $this->recipientName,
            'recipient_phone' => $this->recipientPhone,
            'zip_code' => $this->zipCode,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'is_default' => $this->isDefault ? 1 : 0,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // Getters
    public function getAddressId(): int { return $this->addressId; }
    public function getMemberId(): int { return $this->memberId; }
    public function getDomainId(): int { return $this->domainId; }
    public function getAddressName(): string { return $this->addressName; }
    public function getRecipientName(): string { return $this->recipientName; }
    public function getRecipientPhone(): string { return $this->recipientPhone; }
    public function getZipCode(): string { return $this->zipCode; }
    public function getAddress1(): string { return $this->address1; }
    public function getAddress2(): string { return $this->address2; }
    public function isDefault(): bool { return $this->isDefault; }
}
