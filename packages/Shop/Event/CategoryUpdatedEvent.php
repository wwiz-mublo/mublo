<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Event;

use Mublo\Core\Event\AbstractEvent;

/**
 * 카테고리 수정 이벤트 (이름 변경)
 *
 * 카테고리 코드는 불변이라 메뉴 URL은 그대로고, 라벨(이름)만 갱신 대상이다.
 */
class CategoryUpdatedEvent extends AbstractEvent
{
    public function __construct(
        private readonly int $domainId,
        private readonly string $categoryCode,
        private readonly string $name,
    ) {}

    public function getDomainId(): int { return $this->domainId; }
    public function getCategoryCode(): string { return $this->categoryCode; }

    /**
     * 카테고리 이름(메뉴 라벨용).
     * 주의: 프레임워크 이벤트 식별자인 getName()(=static::class)과 충돌하면 안 되므로
     *       반드시 별도 이름을 쓴다.
     */
    public function getCategoryName(): string { return $this->name; }
}
