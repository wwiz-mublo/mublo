<?php
namespace Mublo\Plugin\Banner\Entity;

use Mublo\Entity\BaseEntity;

/**
 * Banner Entity
 *
 * 배너 엔티티 (banners 테이블)
 */
class Banner extends BaseEntity
{
    protected int $bannerId = 0;
    protected int $domainId = 0;
    protected string $title = '';
    protected string $pcImageUrl = '';
    protected ?string $moImageUrl = null;
    protected ?string $linkUrl = null;
    protected string $linkTarget = '_self';
    protected int $sortOrder = 0;
    protected bool $isActive = true;
    protected ?string $startDate = null;
    protected ?string $endDate = null;
    protected ?array $extras = null;

    protected function getPrimaryKeyField(): string
    {
        return 'bannerId';
    }

    public static function fromArray(array $data): self
    {
        $entity = new self();
        $entity->bannerId = (int) ($data['banner_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->title = $data['title'] ?? '';
        $entity->pcImageUrl = $data['pc_image_url'] ?? '';
        $entity->moImageUrl = $data['mo_image_url'] ?? null;
        $entity->linkUrl = $data['link_url'] ?? null;
        $entity->linkTarget = $data['link_target'] ?? '_self';
        $entity->sortOrder = (int) ($data['sort_order'] ?? 0);
        $entity->isActive = (bool) ($data['is_active'] ?? true);
        $entity->startDate = $data['start_date'] ?? null;
        $entity->endDate = $data['end_date'] ?? null;
        $entity->extras = isset($data['extras']) ? (is_string($data['extras']) ? json_decode($data['extras'], true) : $data['extras']) : null;
        $entity->createdAt = $data['created_at'] ?? '';
        $entity->updatedAt = $data['updated_at'] ?? null;

        return $entity;
    }

    public function toArray(): array
    {
        return [
            'banner_id' => $this->bannerId,
            'domain_id' => $this->domainId,
            'title' => $this->title,
            'pc_image_url' => $this->pcImageUrl,
            'mo_image_url' => $this->moImageUrl,
            'link_url' => $this->linkUrl,
            'link_target' => $this->linkTarget,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'extras' => $this->extras,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // Getters

    public function getBannerId(): int
    {
        return $this->bannerId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getPcImageUrl(): string
    {
        return $this->pcImageUrl;
    }

    /**
     * 모바일 이미지 URL (없으면 PC 이미지 반환)
     */
    public function getMoImageUrl(): string
    {
        return $this->moImageUrl ?? $this->pcImageUrl;
    }

    /**
     * 모바일 전용 이미지가 있는지
     */
    public function hasMoImage(): bool
    {
        return $this->moImageUrl !== null && $this->moImageUrl !== '';
    }

    public function getLinkUrl(): ?string
    {
        return $this->linkUrl;
    }

    public function getLinkTarget(): string
    {
        return $this->linkTarget;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getStartDate(): ?string
    {
        return $this->startDate;
    }

    public function getEndDate(): ?string
    {
        return $this->endDate;
    }

    /**
     * 현재 날짜가 노출 기간 내인지 확인
     */
    public function isInDateRange(): bool
    {
        $today = date('Y-m-d');

        if ($this->startDate !== null && $today < $this->startDate) {
            return false;
        }

        if ($this->endDate !== null && $today > $this->endDate) {
            return false;
        }

        return true;
    }

    public function hasLink(): bool
    {
        return $this->linkUrl !== null && $this->linkUrl !== '';
    }

    public function opensInNewTab(): bool
    {
        return $this->linkTarget === '_blank';
    }

    /**
     * 확장 데이터 전체 반환
     */
    public function getExtras(): ?array
    {
        return $this->extras;
    }

    /**
     * 확장 데이터 특정 키 반환
     */
    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extras[$key] ?? $default;
    }
}
