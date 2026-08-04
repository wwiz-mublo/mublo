<?php
declare(strict_types=1);

namespace Mublo\Contract\Menu;

/**
 * 확장이 소유한 메뉴를 식별하고 동기화하는 데 필요한 최소 읽기 모델.
 */
final readonly class MenuDescriptor
{
    public function __construct(
        public int $itemId,
        public string $menuCode,
        public string $label,
        public ?string $url
    ) {
    }
}
