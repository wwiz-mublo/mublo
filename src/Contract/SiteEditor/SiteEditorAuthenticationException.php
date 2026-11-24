<?php
declare(strict_types=1);

namespace Mublo\Contract\SiteEditor;

use RuntimeException;

final class SiteEditorAuthenticationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = 'Site editor client authentication failed.',
        public readonly int $statusCode = 401
    ) {
        if ($statusCode < 400 || $statusCode > 499) {
            throw new \InvalidArgumentException('Authentication status must be a 4xx response.');
        }
        parent::__construct($message);
    }
}
