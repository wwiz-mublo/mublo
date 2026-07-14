<?php

namespace Mublo\Contract;

/**
 * 관리자 데이터 초기화 화면에 노출할 카테고리 계약입니다.
 */
final readonly class DataResetCategory
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $icon,
        public bool $includeInFullReset = true
    ) {
    }

    /** @return array{key: string, label: string, description: string, icon: string, include_in_full_reset: bool} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'icon' => $this->icon,
            'include_in_full_reset' => $this->includeInFullReset,
        ];
    }
}
