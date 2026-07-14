<?php
namespace Mublo\Exception;

/**
 * ConflictException
 *
 * 충돌 (409) - 예: 중복된 리소스
 */
class ConflictException extends ApplicationException
{
    public function __construct(
        string $message = 'Conflict',
        string $errorCode = 'ERR_CONFLICT',
        array $context = []
    ) {
        parent::__construct(
            $message,
            $errorCode,
            409,
            $context
        );
    }
}
