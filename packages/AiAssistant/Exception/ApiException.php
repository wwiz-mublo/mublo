<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Exception;

final class ApiException extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 400,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}
