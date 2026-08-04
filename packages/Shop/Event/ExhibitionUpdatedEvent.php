<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Shop\Helper\ExhibitionUrl;

/**
 * 기획전 수정 이벤트
 *
 * 슬러그가 바뀌면 URL도 바뀌므로 변경 전/후 URL을 모두 노출한다.
 * (구독자는 변경 전 URL로 기존 메뉴 아이템을 찾아 갱신)
 */
class ExhibitionUpdatedEvent extends AbstractEvent
{
    public function __construct(
        private readonly int $domainId,
        private readonly int $exhibitionId,
        private readonly string $title,
        private readonly ?string $oldSlug,
        private readonly ?string $newSlug,
    ) {}

    public function getDomainId(): int { return $this->domainId; }
    public function getExhibitionId(): int { return $this->exhibitionId; }
    public function getTitle(): string { return $this->title; }

    public function getOldUrl(): string
    {
        return ExhibitionUrl::detail($this->exhibitionId, $this->oldSlug);
    }

    public function getNewUrl(): string
    {
        return ExhibitionUrl::detail($this->exhibitionId, $this->newSlug);
    }
}
