<?php
declare(strict_types=1);

namespace Mublo\Contract\Menu;

/** 운영 메뉴 트리와 미리보기 overlay가 공유하는 안정 읽기 모델. */
final readonly class MenuTreeEntry
{
    public function __construct(
        public int $nodeId,
        public string $menuCode,
        public string $pathCode,
        public string $pathName,
        public ?string $parentCode,
        public int $depth,
        public int $sortOrder,
        public string $label,
        public ?string $url = null,
        public ?string $icon = null,
        public string $target = '_self',
        public string $visibility = 'all',
        public int $minLevel = 0,
        public ?string $requiredPermission = null,
        public bool $showOnPc = true,
        public bool $showOnMobile = true,
        public bool $isActive = true,
        public ?string $providerType = null,
        public ?string $providerName = null,
        public ?string $pairCode = null
    ) {
    }

    /** @return array<string, mixed> MenuTreeRepository::findTreeWithItems() 호환 행 */
    public function toArray(int $domainId, ?int $nodeId = null): array
    {
        return [
            'node_id' => $nodeId ?? $this->nodeId,
            'domain_id' => $domainId,
            'menu_code' => $this->menuCode,
            'path_code' => $this->pathCode,
            'path_name' => $this->pathName,
            'parent_code' => $this->parentCode,
            'depth' => $this->depth,
            'sort_order' => $this->sortOrder,
            'label' => $this->label,
            'url' => $this->url,
            'icon' => $this->icon,
            'target' => $this->target,
            'visibility' => $this->visibility,
            'min_level' => $this->minLevel,
            'required_permission' => $this->requiredPermission,
            'show_on_pc' => $this->showOnPc ? 1 : 0,
            'show_on_mobile' => $this->showOnMobile ? 1 : 0,
            'is_active' => $this->isActive ? 1 : 0,
            'provider_type' => $this->providerType,
            'provider_name' => $this->providerName,
            'pair_code' => $this->pairCode,
        ];
    }
}
