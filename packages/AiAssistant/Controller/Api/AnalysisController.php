<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Packages\AiAssistant\Service\AnalysisService;
use Mublo\Packages\AiAssistant\Service\AuthService;
use Mublo\Packages\AiAssistant\Service\IdempotencyService;

final class AnalysisController extends ApiController
{
    public function __construct(
        AuthService $auth,
        private AnalysisService $analysis,
        private IdempotencyService $idempotency
    ) {
        parent::__construct($auth);
    }

    public function consent(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $principal = $this->principal($context);
            $input = $this->json($context);
            $executed = $this->idempotency->execute(
                (string) $principal['company_id'],
                'analysis.consent',
                $context->getRequest()->header('Idempotency-Key'),
                $input,
                200,
                fn(): array => $this->analysis->registerConsent($principal, $input)
            );
            return JsonResponse::success($executed['data'])
                ->withHeader('Idempotency-Replayed', $executed['replayed'] ? 'true' : 'false');
        });
    }

    public function createBatch(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $principal = $this->principal($context);
            $input = $this->json($context);
            $executed = $this->idempotency->execute(
                (string) $principal['company_id'],
                'analysis.batch.create',
                $context->getRequest()->header('Idempotency-Key'),
                $input,
                200,
                fn(): array => $this->analysis->createBatch($principal, $input)
            );
            return JsonResponse::success($executed['data'])
                ->withHeader('Idempotency-Replayed', $executed['replayed'] ? 'true' : 'false');
        });
    }

    public function batch(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, fn(): JsonResponse => JsonResponse::success(
            $this->analysis->getBatch($this->principal($context), (string) ($params['batch_id'] ?? ''))
        ));
    }

    public function run(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, fn(): JsonResponse => JsonResponse::success(
            $this->analysis->getRun($this->principal($context), (string) ($params['run_id'] ?? ''))
        ));
    }

    public function retry(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context, $params): JsonResponse {
            $principal = $this->principal($context);
            $runId = (string) ($params['run_id'] ?? '');
            $executed = $this->idempotency->execute(
                (string) $principal['company_id'],
                'analysis.run.retry.' . $runId,
                $context->getRequest()->header('Idempotency-Key'),
                [],
                200,
                fn(): array => $this->analysis->retry($principal, $runId)
            );
            return JsonResponse::success($executed['data'])
                ->withHeader('Idempotency-Replayed', $executed['replayed'] ? 'true' : 'false');
        });
    }
}
