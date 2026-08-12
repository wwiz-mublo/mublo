<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Service\AuthService;
use Mublo\Packages\AiAssistant\Support\ApiJsonResponse;
use Mublo\Packages\AiAssistant\Support\TokenCodec;

abstract class ApiController
{
    public function __construct(protected AuthService $auth)
    {
    }

    /** @return array<string, mixed> */
    protected function principal(Context $context): array
    {
        return $this->auth->authenticate($context->getRequest()->bearerToken());
    }

    /** @return array<string, mixed> */
    protected function json(Context $context): array
    {
        $value = $context->getRequest()->json();
        if (!is_array($value)) {
            throw new ApiException('JSON_BODY_REQUIRED', 'JSON 요청 본문이 필요합니다.', 400);
        }
        return $value;
    }

    protected function requestId(Context $context): string
    {
        $provided = trim((string) $context->getRequest()->header('X-Request-Id', ''));
        return $provided !== '' && strlen($provided) <= 128 ? $provided : TokenCodec::generate(12);
    }

    /** @param callable(string): JsonResponse $operation */
    protected function respond(Context $context, callable $operation): JsonResponse
    {
        $requestId = $this->requestId($context);
        try {
            return $operation($requestId)->withHeader('X-Request-Id', $requestId);
        } catch (ApiException $exception) {
            return ApiJsonResponse::fromException($exception, $requestId)
                ->withHeader('X-Request-Id', $requestId);
        } catch (\Throwable $exception) {
            error_log(sprintf(
                '[AI_ASSISTANT_API] request_id=%s exception=%s',
                $requestId,
                $exception::class
            ));
            return ApiJsonResponse::failure(
                'INTERNAL_ERROR',
                '요청을 처리하지 못했습니다.',
                500,
                [],
                $requestId
            )->withHeader('X-Request-Id', $requestId);
        }
    }
}
