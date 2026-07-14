<?php

namespace Mublo\Service\Mypage;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Mypage\MypageSectionBuildingEvent;

/**
 * 마이페이지 섹션 레지스트리
 *
 * MypageSectionBuildingEvent를 발행해 패키지가 등록한 섹션을 모은다(요청당 1회 캐시).
 * AdminMenuService가 AdminMenuBuildingEvent로 메뉴를 모으는 것과 동일한 패턴.
 */
class MypageSectionRegistry
{
    private ?MypageSectionBuildingEvent $built = null;

    public function __construct(private EventDispatcher $eventDispatcher) {}

    private function build(): MypageSectionBuildingEvent
    {
        if ($this->built === null) {
            $this->built = $this->eventDispatcher->dispatch(new MypageSectionBuildingEvent());
        }
        return $this->built;
    }

    /**
     * (provider, key) 섹션 조회. 미등록 시 null.
     */
    public function getSection(string $provider, string $key): ?array
    {
        return $this->build()->getSection($provider, $key);
    }

    /**
     * @return array<string, array<string, array>> provider => key => section
     */
    public function getSections(): array
    {
        return $this->build()->getSections();
    }

    /**
     * provider 마이페이지 허브 조회. 미등록 시 null.
     */
    public function getHub(string $provider): ?array
    {
        return $this->build()->getHub($provider);
    }
}
