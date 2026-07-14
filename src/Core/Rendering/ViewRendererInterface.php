<?php

namespace Mublo\Core\Rendering;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Context\Context;

/**
 * ViewRendererInterface
 *
 * 모든 ViewRenderer가 구현해야 하는 인터페이스.
 *
 * Core에서 기본 제공하는 구현체:
 * - AdminViewRenderer: Admin 영역 렌더링
 * - FrontViewRenderer: Front 영역 렌더링
 *
 * Package에서 커스텀 렌더러를 만들어
 * RendererResolveEvent를 통해 특정 URL 패턴에 대해
 * 독자적인 렌더링 파이프라인을 제공할 수 있다.
 *
 * @see RendererResolveEvent 렌더러 결정 이벤트
 */
interface ViewRendererInterface
{
    /**
     * ViewResponse를 해석하여 HTML을 출력한다.
     */
    public function render(ViewResponse $response, Context $context): void;
}
