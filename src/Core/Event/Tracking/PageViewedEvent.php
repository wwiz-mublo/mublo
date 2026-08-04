<?php
declare(strict_types=1);

namespace Mublo\Core\Event\Tracking;

use Mublo\Core\Event\AbstractEvent;

/**
 * 페이지뷰 이벤트
 *
 * 모든 Front 페이지 렌더링 완료 시 발행되는 확장 포인트.
 * 현재 번들 구독자는 없다 — 서버 방문 수집(VisitorStats)은
 * 라우팅 직전의 SiteContextReadyEvent 시점에 수행되며, 이 이벤트는
 * 렌더 완료 후처리(감사·외부 연동 등)가 필요한 확장을 위해 열려 있다.
 */
class PageViewedEvent extends AbstractEvent
{
    public function __construct(
        private readonly int $domainId,
        private readonly string $url,
        private readonly string $pageType,
        private readonly ?int $memberId = null,
        private readonly string $ipAddress = '',
        private readonly string $userAgent = '',
        private readonly string $referer = ''
    ) {
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * 페이지 유형
     *
     * 'index', 'page', 'board_list', 'board_view',
     * 'auth', 'member', 'search', 'community', 'other'
     */
    public function getPageType(): string
    {
        return $this->pageType;
    }

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function getReferer(): string
    {
        return $this->referer;
    }
}
