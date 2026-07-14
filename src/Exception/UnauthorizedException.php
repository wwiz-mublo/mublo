<?php
namespace Mublo\Exception;

/**
 * UnauthorizedException
 *
 * 인증되지 않음 (401)
 */
class UnauthorizedException extends ApplicationException
{
    public function __construct(
        string $message = 'Unauthorized',
        array $context = []
    ) {
        parent::__construct(
            $message,
            'ERR_UNAUTHORIZED',
            401,
            $context
        );
    }
}
