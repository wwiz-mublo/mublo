<?php

namespace Mublo\Core\Event;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\AbstractResponse;

/**
 * RequestInterceptEvent
 *
 * 라우팅 전에 Plugin/Package가 요청을 가로채고 Response를 반환할 수 있는 이벤트.
 *
 * 발행 시점:
 * - SiteContextReadyEvent 이후, Router 실행 직전
 * - 세션이 열려 있으므로 구독자가 인증 상태와 세션 값을 확인 가능
 *
 * 사용 예 (비공개 사이트 접근 제어):
 * ```php
 * public function onRequestIntercept(RequestInterceptEvent $event): void
 * {
 *     if ($this->shouldBlock($event)) {
 *         $event->setResponse(new RedirectResponse('/login'));
 *     }
 * }
 * ```
 *
 * Response가 세팅되면 Application은 라우팅을 건너뛰고 해당 Response를 반환합니다.
 */
class RequestInterceptEvent extends AbstractEvent
{
    private ?AbstractResponse $response = null;

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

    /**
     * 요청을 가로채는 Response 세팅
     *
     * 세팅 시 Application은 라우팅을 건너뛰고 이 Response를 반환합니다.
     */
    public function setResponse(AbstractResponse $response): void
    {
        $this->response = $response;
    }

    public function getResponse(): ?AbstractResponse
    {
        return $this->response;
    }

    public function hasResponse(): bool
    {
        return $this->response !== null;
    }
}
