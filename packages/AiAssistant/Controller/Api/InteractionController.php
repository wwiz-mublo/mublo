<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Packages\AiAssistant\Service\AuthService;
use Mublo\Packages\AiAssistant\Service\IdempotencyService;
use Mublo\Packages\AiAssistant\Service\InteractionService;

final class InteractionController extends ApiController
{
    public function __construct(
        AuthService $auth,
        private InteractionService $interactions,
        private IdempotencyService $idempotency
    ) {
        parent::__construct($auth);
    }

    public function workerKey(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $this->principal($context);
            return JsonResponse::success($this->interactions->workerKey());
        });
    }

    public function upload(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $principal = $this->principal($context);
            $input = $this->json($context);
            $executed = $this->idempotency->execute(
                (string) $principal['company_id'],
                'interactions.upload',
                $context->getRequest()->header('Idempotency-Key'),
                $input,
                200,
                fn(): array => $this->interactions->upload($principal, $input)
            );
            return JsonResponse::success($executed['data'])
                ->withHeader('Idempotency-Replayed', $executed['replayed'] ? 'true' : 'false');
        });
    }

    public function latestAnalysis(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context, $params): JsonResponse {
            return JsonResponse::success($this->interactions->latestAnalysis(
                $this->principal($context),
                (string) ($params['customer_id'] ?? '')
            ));
        });
    }
}
