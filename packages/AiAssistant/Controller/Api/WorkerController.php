<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Packages\AiAssistant\Service\AuthService;
use Mublo\Packages\AiAssistant\Service\WorkerJobService;

final class WorkerController extends ApiController
{
    public function __construct(AuthService $auth, private WorkerJobService $jobs)
    {
        parent::__construct($auth);
    }

    public function lease(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $this->jobs->authenticate($context->getRequest()->header('X-Worker-Token'));
            return JsonResponse::success($this->jobs->lease($this->json($context)));
        });
    }

    public function complete(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context, $params): JsonResponse {
            $this->jobs->authenticate($context->getRequest()->header('X-Worker-Token'));
            return JsonResponse::success($this->jobs->complete(
                (string) ($params['job_id'] ?? ''),
                $this->json($context)
            ));
        });
    }

    public function fail(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context, $params): JsonResponse {
            $this->jobs->authenticate($context->getRequest()->header('X-Worker-Token'));
            return JsonResponse::success($this->jobs->fail(
                (string) ($params['job_id'] ?? ''),
                $this->json($context)
            ));
        });
    }
}
