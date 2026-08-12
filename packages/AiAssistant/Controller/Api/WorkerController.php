<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Packages\AiAssistant\Service\AuthService;
use Mublo\Packages\AiAssistant\Service\AnalysisWorkerService;
use Mublo\Packages\AiAssistant\Service\WorkerJobService;

final class WorkerController extends ApiController
{
    public function __construct(AuthService $auth, private WorkerJobService $jobs, private AnalysisWorkerService $analysisJobs)
    {
        parent::__construct($auth);
    }

    public function preflight(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $this->analysisJobs->authenticate($context->getRequest()->header('X-Worker-Token'));
            return JsonResponse::success($this->analysisJobs->preflight());
        });
    }

    public function lease(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $this->jobs->authenticate($context->getRequest()->header('X-Worker-Token'));
            $input = $this->json($context);
            if (is_array($input['capabilities'] ?? null)
                && in_array('CUSTOMER_PROFILE_ANALYSIS_V2', $input['capabilities'], true)) {
                return JsonResponse::success($this->analysisJobs->lease($input));
            }
            return JsonResponse::success($this->jobs->lease($input));
        });
    }

    public function complete(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context, $params): JsonResponse {
            $this->jobs->authenticate($context->getRequest()->header('X-Worker-Token'));
            $input = $this->json($context);
            $jobId = (string) ($params['job_id'] ?? '');
            return JsonResponse::success(($input['schema_version'] ?? null) === 'worker-complete-v2'
                ? $this->analysisJobs->complete($jobId, $input)
                : $this->jobs->complete($jobId, $input));
        });
    }

    public function fail(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context, $params): JsonResponse {
            $this->jobs->authenticate($context->getRequest()->header('X-Worker-Token'));
            $jobId = (string) ($params['job_id'] ?? '');
            $input = $this->json($context);
            return JsonResponse::success($this->analysisJobs->hasJob($jobId)
                ? $this->analysisJobs->fail($jobId, $input)
                : $this->jobs->fail($jobId, $input));
        });
    }

    public function heartbeat(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $this->analysisJobs->authenticate($context->getRequest()->header('X-Worker-Token'));
            return JsonResponse::success($this->analysisJobs->heartbeat($this->json($context)));
        });
    }

    public function renew(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context, $params): JsonResponse {
            $this->analysisJobs->authenticate($context->getRequest()->header('X-Worker-Token'));
            return JsonResponse::success($this->analysisJobs->renew(
                (string) ($params['job_id'] ?? ''),
                $this->json($context)
            ));
        });
    }
}
