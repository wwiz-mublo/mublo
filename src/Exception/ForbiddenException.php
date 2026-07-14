<?php
namespace Mublo\Exception;

/**
 * ForbiddenException
 *
 * 권한 부족 (403)
 */
class ForbiddenException extends ApplicationException
{
    public function __construct(
        string $message = 'Forbidden',
        array $context = []
    ) {
        parent::__construct(
            $message,
            'ERR_FORBIDDEN',
            403,
            $context
        );
    }
}
