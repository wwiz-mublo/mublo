<?php
namespace Mublo\Exception;

/**
 * InternalServerException
 *
 * 내부 서버 에러 (500)
 */
class InternalServerException extends ApplicationException
{
    public function __construct(
        string $message = 'Internal Server Error',
        array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message,
            'ERR_INTERNAL_SERVER',
            500,
            $context,
            0,
            $previous
        );
    }
}
