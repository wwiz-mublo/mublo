<?php
namespace Mublo\Exception;

/**
 * ServiceException
 *
 * Service 계층 에러
 */
class ServiceException extends ApplicationException
{
    public function __construct(
        string $message = 'Service operation failed',
        string $errorCode = 'ERR_SERVICE',
        array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message,
            $errorCode,
            500,
            $context,
            0,
            $previous
        );
    }
}
