<?php
namespace Mublo\Core\Event\Domain;

use Mublo\Core\Event\AbstractEvent;

/**
 * DomainSettingsLinksEvent
 *
 * 도메인 목록에서 패키지별 설정 링크를 수집하는 이벤트
 *
 * 패키지가 구독하여 자신의 관리자 설정 URL을 등록하면,
 * 도메인 목록 뷰에서 각 도메인에 대한 바로가기 링크로 표시된다.
 *
 * @example
 * // MshopProvider::boot()
 * $eventDispatcher->addSubscriber(new DomainSettingsSubscriber());
 *
 * // DomainSettingsSubscriber
 * public function onDomainSettingsLinks(DomainSettingsLinksEvent $event): void
 * {
 *     $event->addLink('핸드폰 쇼핑몰 설정', '/admin/mshop/config', 'bi-phone');
 * }
 */
class DomainSettingsLinksEvent extends AbstractEvent
{
    private array $links = [];

    /**
     * 설정 링크 추가
     *
     * @param string $label 표시 이름
     * @param string $adminUrl 관리자 페이지 URL (대상 도메인 기준)
     * @param string $icon Bootstrap 아이콘 클래스
     * @param string $packageName 패키지 이름 (도메인별 설치 여부 필터링용)
     */
    public function addLink(string $label, string $adminUrl, string $icon = 'bi-gear', string $packageName = ''): self
    {
        $this->links[] = [
            'label' => $label,
            'url' => $adminUrl,
            'icon' => $icon,
            'package' => $packageName,
        ];
        return $this;
    }

    /**
     * 등록된 링크 목록 반환
     */
    public function getLinks(): array
    {
        return $this->links;
    }
}
