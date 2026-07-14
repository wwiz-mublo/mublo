<?php

namespace Mublo\Packages\Shop\Entity;

/**
 * Exhibition (기획전)
 *
 * 기간 기반 기획전 마스터 엔티티
 * - 활성 여부, 기간 유효성 판단 메서드 포함
 */
class Exhibition
{
    private int $exhibitionId;
    private int $domainId;
    private string $title;
    private ?string $description;
    private ?string $slug;
    private ?string $bannerImage;
    private ?string $bannerMobileImage;
    private ?string $startDate;
    private ?string $endDate;
    private bool $isActive;
    private int $sortOrder;
    private string $createdAt;
    private string $updatedAt;

    private function __construct() {}

    public static function fromArray(array $data): self
    {
        $self = new self();
        $self->exhibitionId      = (int) ($data['exhibition_id'] ?? 0);
        $self->domainId          = (int) ($data['domain_id'] ?? 0);
        $self->title             = (string) ($data['title'] ?? '');
        $self->description       = $data['description'] ?? null;
        $self->slug              = $data['slug'] ?? null;
        $self->bannerImage       = $data['banner_image'] ?? null;
        $self->bannerMobileImage = $data['banner_mobile_image'] ?? null;
        $self->startDate         = $data['start_date'] ?? null;
        $self->endDate           = $data['end_date'] ?? null;
        $self->isActive          = (bool) ($data['is_active'] ?? true);
        $self->sortOrder         = (int) ($data['sort_order'] ?? 0);
        $self->createdAt         = (string) ($data['created_at'] ?? '');
        $self->updatedAt         = (string) ($data['updated_at'] ?? '');
        return $self;
    }

    public function toArray(): array
    {
        return [
            'exhibition_id'       => $this->exhibitionId,
            'domain_id'           => $this->domainId,
            'title'               => $this->title,
            'description'         => $this->description,
            'slug'                => $this->slug,
            'banner_image'        => $this->bannerImage,
            'banner_mobile_image' => $this->bannerMobileImage,
            'start_date'          => $this->startDate,
            'end_date'            => $this->endDate,
            'is_active'           => $this->isActive,
            'sort_order'          => $this->sortOrder,
            'created_at'          => $this->createdAt,
            'updated_at'          => $this->updatedAt,
        ];
    }

    /** 현재 기획전이 진행 중인지 판단 (활성 + 기간 유효) */
    public function isOngoing(): bool
    {
        if (!$this->isActive) {
            return false;
        }
        $now = time();
        if ($this->startDate && strtotime($this->startDate) > $now) {
            return false;
        }
        if ($this->endDate && strtotime($this->endDate) < $now) {
            return false;
        }
        return true;
    }

    public function getExhibitionId(): int     { return $this->exhibitionId; }
    public function getDomainId(): int         { return $this->domainId; }
    public function getTitle(): string         { return $this->title; }
    public function getSlug(): ?string         { return $this->slug; }
    public function getBannerImage(): ?string  { return $this->bannerImage; }
    public function isActive(): bool           { return $this->isActive; }
    public function getStartDate(): ?string    { return $this->startDate; }
    public function getEndDate(): ?string      { return $this->endDate; }
}
