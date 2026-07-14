<?php

namespace Mublo\Plugin\Banner\Event;

use Mublo\Core\Event\AbstractEvent;

/**
 * 배너 저장 이벤트
 *
 * 배너 생성/수정 시 패키지가 extras 데이터를 설정할 수 있도록 합니다.
 *
 * 패키지 구독 예시:
 *   public function onBannerSave(BannerSaveEvent $event): void
 *   {
 *       $brandCode = $event->getInput('brand_code');
 *       $event->setExtra('brand_code', $brandCode);
 *   }
 */
class BannerSaveEvent extends AbstractEvent
{
    /** @var array extras에 저장될 데이터 */
    private array $extras = [];

    public function __construct(
        private readonly int $domainId,
        private readonly int $bannerId,
        private readonly array $inputData,
        private readonly bool $isUpdate,
    ) {}

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getBannerId(): int
    {
        return $this->bannerId;
    }

    public function isUpdate(): bool
    {
        return $this->isUpdate;
    }

    /**
     * 폼 입력값에서 특정 키 반환
     */
    public function getInput(string $key, mixed $default = null): mixed
    {
        return $this->inputData[$key] ?? $default;
    }

    /**
     * extras에 키-값 설정
     */
    public function setExtra(string $key, mixed $value): void
    {
        $this->extras[$key] = $value;
    }

    /**
     * 수집된 extras 반환
     */
    public function getExtras(): array
    {
        return $this->extras;
    }

    public function hasExtras(): bool
    {
        return !empty($this->extras);
    }
}
