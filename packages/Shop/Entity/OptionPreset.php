<?php

namespace Mublo\Packages\Shop\Entity;

use Mublo\Entity\BaseEntity;

/**
 * OptionPreset Entity
 *
 * 쇼핑몰 옵션 프리셋 엔티티 (shop_option_presets 테이블)
 *
 * 책임:
 * - shop_option_presets 테이블의 데이터를 객체로 표현
 * - 옵션 프리셋 정보 제공
 *
 * 금지:
 * - DB 직접 접근
 */
class OptionPreset extends BaseEntity
{
    // ========================================
    // 기본 정보
    // ========================================
    protected int $presetId = 0;
    protected int $domainId = 0;
    protected string $name = '';
    protected ?string $description = null;
    protected string $optionMode = 'SINGLE';

    /**
     * Private constructor - fromArray() 사용
     */
    private function __construct()
    {
    }

    /**
     * 기본키 필드명
     */
    protected function getPrimaryKeyField(): string
    {
        return 'presetId';
    }

    /**
     * DB 로우 데이터로부터 객체 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        // 기본 정보
        $entity->presetId = (int) ($data['preset_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->name = $data['name'] ?? '';
        $entity->description = $data['description'] ?? null;
        $entity->optionMode = $data['option_mode'] ?? 'SINGLE';

        // 타임스탬프
        $entity->createdAt = $data['created_at'] ?? '';
        $entity->updatedAt = $data['updated_at'] ?? null;

        return $entity;
    }

    /**
     * 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'preset_id' => $this->presetId,
            'domain_id' => $this->domainId,
            'name' => $this->name,
            'description' => $this->description,
            'option_mode' => $this->optionMode,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // ========================================
    // Getter 메서드
    // ========================================

    public function getPresetId(): int
    {
        return $this->presetId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getOptionMode(): string
    {
        return $this->optionMode;
    }

    // ========================================
    // 상태 판단 메서드
    // ========================================

    /**
     * 조합형 모드 여부
     */
    public function isCombination(): bool
    {
        return $this->optionMode === 'COMBINATION';
    }

    /**
     * 설명이 있는지 여부
     */
    public function hasDescription(): bool
    {
        return $this->description !== null && $this->description !== '';
    }
}
