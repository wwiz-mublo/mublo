<?php
namespace Mublo\Exception;

/**
 * HttpNotFoundException
 *
 * HTTP 라우팅 레벨에서 대상을 찾을 수 없음 (Controller, Method 등)
 */
class HttpNotFoundException extends ApplicationException
{
    public function __construct(
        string $message = 'Not Found',
        array $context = []
    ) {
        parent::__construct(
            $message,
            'ERR_HTTP_NOT_FOUND',
            404,
            $context
        );
    }
}
