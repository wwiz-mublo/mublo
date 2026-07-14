<?php

namespace Mublo\Plugin\Banner\Event;

use Mublo\Core\Event\AbstractEvent;

/**
 * 배너 폼 빌드 이벤트
 *
 * 배너 생성/수정 폼에 확장 필드를 추가할 수 있도록
 * 패키지에게 기회를 제공합니다.
 *
 * 패키지 구독 예시:
 *   $event->addField([
 *       'key'     => 'brand_code',
 *       'type'    => 'select',      // select | text | hidden
 *       'label'   => '브랜드',
 *       'options' => [['value' => 'sk', 'label' => 'SK매직'], ...],
 *       'value'   => $currentValue,  // 수정 시 기존 값
 *   ]);
 */
class BannerFormBuildEvent extends AbstractEvent
{
    /** @var array 확장 필드 정의 목록 */
    private array $fields = [];

    public function __construct(
        private readonly int $domainId,
        private readonly array $banner,
    ) {}

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    /**
     * 배너 데이터 (수정 시 기존 데이터, 생성 시 빈 배열)
     */
    public function getBanner(): array
    {
        return $this->banner;
    }

    /**
     * 배너의 extras에서 특정 키 값 반환 (편의 메서드)
     */
    public function getExtra(string $key, mixed $default = null): mixed
    {
        $extras = $this->banner['extras'] ?? [];
        return $extras[$key] ?? $default;
    }

    /**
     * 확장 필드 추가
     *
     * @param array $field [key, type, label, options?, value?, placeholder?, helpText?]
     */
    public function addField(array $field): void
    {
        $this->fields[] = $field;
    }

    /**
     * @return array 등록된 확장 필드 목록
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function hasFields(): bool
    {
        return !empty($this->fields);
    }
}
