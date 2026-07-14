<?php

namespace Mublo\Plugin\Popup\Entity;

use Mublo\Entity\BaseEntity;

class Popup extends BaseEntity
{
    protected int $popupId = 0;
    protected int $domainId = 0;
    protected string $title = '';
    protected ?string $htmlContent = null;
    protected ?string $linkUrl = null;
    protected string $linkTarget = '_self';
    protected string $position = 'center';
    protected int $width = 500;
    protected int $height = 0;
    protected string $displayDevice = 'all';
    protected ?string $startDate = null;
    protected ?string $endDate = null;
    protected int $hideDuration = 24;
    protected int $sortOrder = 0;
    protected bool $isActive = true;

    protected function getPrimaryKeyField(): string
    {
        return 'popupId';
    }

    public static function fromArray(array $data): self
    {
        $entity = new self();
        $entity->popupId = (int) ($data['popup_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->title = $data['title'] ?? '';
        $entity->htmlContent = $data['html_content'] ?? null;
        $entity->linkUrl = $data['link_url'] ?? null;
        $entity->linkTarget = $data['link_target'] ?? '_self';
        $entity->position = $data['position'] ?? 'center';
        $entity->width = (int) ($data['width'] ?? 500);
        $entity->height = (int) ($data['height'] ?? 0);
        $entity->displayDevice = $data['display_device'] ?? 'all';
        $entity->startDate = $data['start_date'] ?? null;
        $entity->endDate = $data['end_date'] ?? null;
        $entity->hideDuration = (int) ($data['hide_duration'] ?? 24);
        $entity->sortOrder = (int) ($data['sort_order'] ?? 0);
        $entity->isActive = (bool) ($data['is_active'] ?? true);
        $entity->createdAt = $data['created_at'] ?? '';
        $entity->updatedAt = $data['updated_at'] ?? null;

        return $entity;
    }

    public function toArray(): array
    {
        return [
            'popup_id' => $this->popupId,
            'domain_id' => $this->domainId,
            'title' => $this->title,
            'html_content' => $this->htmlContent,
            'link_url' => $this->linkUrl,
            'link_target' => $this->linkTarget,
            'position' => $this->position,
            'width' => $this->width,
            'height' => $this->height,
            'display_device' => $this->displayDevice,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'hide_duration' => $this->hideDuration,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

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
}
