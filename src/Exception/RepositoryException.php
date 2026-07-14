<?php
namespace Mublo\Exception;

/**
 * RepositoryException
 *
 * Repository 계층 에러
 */
class RepositoryException extends ApplicationException
{
    public function __construct(
        string $message = 'Repository operation failed',
        string $errorCode = 'ERR_REPOSITORY',
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
