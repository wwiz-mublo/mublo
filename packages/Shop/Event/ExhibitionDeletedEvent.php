<?php

namespace Mublo\Packages\Shop\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Shop\Helper\ExhibitionUrl;

class ExhibitionDeletedEvent extends AbstractEvent
{
    public function __construct(
        private readonly int $domainId,
        private readonly int $exhibitionId,
        private readonly ?string $slug = null,
    ) {}

    public function getDomainId(): int { return $this->domainId; }
    public function getExhibitionId(): int { return $this->exhibitionId; }
    public function getSlug(): ?string { return $this->slug; }

    public function getUrl(): string
    {
        return ExhibitionUrl::detail($this->exhibitionId, $this->slug);
    }
}
