<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Support;

use Mublo\Core\Response\JsonResponse;
use Mublo\Packages\AiAssistant\Exception\ApiException;

final class ApiJsonResponse extends JsonResponse
{
    /** @param array<string, mixed> $details */
    public static function failure(
        string $code,
        string $message,
        int $statusCode,
        array $details = [],
        ?string $requestId = null
    ): self {
        $response = new self(null, false, $message, $statusCode);
        $error = ['code' => $code];
        if ($details !== []) {
            $error['details'] = $details;
        }
        if ($requestId !== null && $requestId !== '') {
            $error['request_id'] = $requestId;
        }
        $response->data = [
            'result' => 'error',
            'success' => false,
            'message' => $message,
            'error' => $error,
        ];

        return $response;
    }

    public static function fromException(ApiException $exception, ?string $requestId = null): self
    {
        return self::failure(
            $exception->errorCode,
            $exception->getMessage(),
            $exception->statusCode,
            $exception->details,
            $requestId
        );
    }
}
