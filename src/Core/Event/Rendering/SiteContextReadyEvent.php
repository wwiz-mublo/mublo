<?php

namespace Mublo\Core\Event\Rendering;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Http\Request;

/**
 * SiteContextReadyEvent
 *
 * Session 시작 이후, Router 실행 직전에 Application에서 발행.
 * Plugin/Package가 Context의 사이트 이미지 URL과 로고 텍스트를 교체할 수 있는 확장점.
 *
 * 발행 시점:
 * - SessionMiddleware, CsrfMiddleware 처리 완료 이후
 * - 세션이 열려 있으므로 구독자가 세션 값(파트너 코드 등)을 읽을 수 있다
 *
 * 사용 예 (MshopProvider):
 * ```php
 * $eventDispatcher->addSubscriber(new PartnerLogoSubscriber($partnerRepository));
 *
 * // PartnerLogoSubscriber
 * public static function getSubscribedEvents(): array
 * {
 *     return [SiteContextReadyEvent::class => 'onSiteContextReady'];
 * }
 *
 * public function onSiteContextReady(SiteContextReadyEvent $event): void
 * {
 *     $context = $event->getContext();
 *     $partnerCode = $_SESSION['mshop_partner_code'] ?? null;
 *     if (!$partnerCode) return;
 *
 *     $partner = $this->partnerRepository->findByCode($context->getDomainId(), $partnerCode);
 *     if ($partner && !empty($partner['logo_image'])) {
 *         $context->setSiteImageUrl('logo_pc', $partner['logo_image']);
 *         $context->setSiteImageUrl('logo_mobile', $partner['logo_image']);
 *     }
 *     if ($partner && !empty($partner['logo_text'])) {
 *         $context->setSiteLogoText($partner['logo_text']);
 *     }
 * }
 * ```
 */
class SiteContextReadyEvent extends AbstractEvent
{
    public function __construct(
        private readonly Context $context,
        private readonly Request $request
    ) {}

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }
}
