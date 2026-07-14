<?php

namespace Mublo\Core\Middleware;

use Mublo\Core\Http\Request;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\AbstractResponse;

/**
 * Middleware Interface
 *
 * 모든 미들웨어가 구현해야 하는 인터페이스
 */
interface MiddlewareInterface
{
    /**
     * 미들웨어 처리
     *
     * @param Request $request 현재 요청
     * @param Context $context 요청 컨텍스트
     * @param callable $next 다음 미들웨어/핸들러
     * @return AbstractResponse 응답 객체
     */
    public function handle(Request $request, Context $context, callable $next): AbstractResponse;
}
