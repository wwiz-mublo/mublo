<?php
declare(strict_types=1);

namespace Mublo\Contract\Menu;

/**
 * 메뉴 트리의 한 배치를 나타내는 안정 읽기 모델.
 *
 * 같은 메뉴 아이템은 여러 위치에 배치될 수 있으므로 itemId/menuCode가 아니라
 * placementId로 이동·정렬·제거 대상을 식별한다.
 */
final readonly class MenuPlacementDescriptor
{
    public function __construct(
        public int $placementId,
        public string $menuCode,
        public string $pathCode,
        public ?string $parentCode,
        public int $depth,
        public int $sortOrder,
        public string $label,
        public ?string $url
    ) {
    }
}
