<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Packages\AiAssistant\Service\AuthService;
use Mublo\Packages\AiAssistant\Service\IdempotencyService;
use Mublo\Packages\AiAssistant\Service\MessagingPolicyService;

final class MessagingPolicyController extends ApiController
{
    public function __construct(
        AuthService $auth,
        private MessagingPolicyService $messaging,
        private IdempotencyService $idempotency
    ) {
        parent::__construct($auth);
    }

    public function putPermission(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($params, $context): JsonResponse {
            $principal = $this->principal($context);
            $input = $this->json($context);
            $phoneId = (string) ($params['customer_phone_id'] ?? '');
            $channel = strtoupper((string) ($params['channel'] ?? ''));
            $purpose = strtoupper((string) ($params['purpose'] ?? ''));
            $executed = $this->idempotency->execute(
                (string) $principal['company_id'],
                'customer-phones.permissions.' . $phoneId . '.' . $channel . '.' . $purpose,
                $context->getRequest()->header('Idempotency-Key'),
                $input,
                200,
                fn(): array => $this->messaging->putPermission($principal, $phoneId, $channel, $purpose, $input)
            );
            return JsonResponse::success($executed['data'])
                ->withHeader('Idempotency-Replayed', $executed['replayed'] ? 'true' : 'false');
        });
    }

    public function eligibility(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, fn(): JsonResponse => JsonResponse::success(
            $this->messaging->eligibility($this->principal($context), $this->json($context))
        ));
    }

    public function suppressionEvent(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $principal = $this->principal($context);
            $input = $this->json($context);
            $executed = $this->idempotency->execute(
                (string) $principal['company_id'],
                'messaging.suppressions.events',
                $context->getRequest()->header('Idempotency-Key'),
                $input,
                200,
                fn(): array => $this->messaging->appendSuppressionEvent($principal, $input)
            );
            return JsonResponse::success($executed['data'])
                ->withHeader('Idempotency-Replayed', $executed['replayed'] ? 'true' : 'false');
        });
    }

    public function campaignSnapshot(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($params, $context): JsonResponse {
            $principal = $this->principal($context);
            $input = $this->json($context);
            $campaignId = (string) ($params['campaign_id'] ?? '');
            $executed = $this->idempotency->execute(
                (string) $principal['company_id'],
                'messaging.campaigns.' . $campaignId . '.recipient-snapshot',
                $context->getRequest()->header('Idempotency-Key'),
                $input,
                200,
                fn(): array => $this->messaging->createCampaignSnapshot($principal, $campaignId, $input)
            );
            return JsonResponse::success($executed['data'])
                ->withHeader('Idempotency-Replayed', $executed['replayed'] ? 'true' : 'false');
        });
    }

    public function putCampaignPolicy(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($params, $context): JsonResponse {
            $principal = $this->principal($context);
            $input = $this->json($context);
            $campaignId = (string) ($params['campaign_id'] ?? '');
            $executed = $this->idempotency->execute(
                (string) $principal['company_id'],
                'messaging.campaigns.' . $campaignId . '.dispatch-policy',
                $context->getRequest()->header('Idempotency-Key'),
                $input,
                200,
                fn(): array => $this->messaging->putCampaignPolicy($principal, $campaignId, $input)
            );
            return JsonResponse::success($executed['data'])
                ->withHeader('Idempotency-Replayed', $executed['replayed'] ? 'true' : 'false');
        });
    }

    public function dispatchPreflight(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($params, $context): JsonResponse {
            $principal = $this->principal($context);
            $input = $this->json($context);
            $campaignId = (string) ($params['campaign_id'] ?? '');
            $executed = $this->idempotency->execute(
                (string) $principal['company_id'],
                'messaging.campaigns.' . $campaignId . '.dispatch-preflight',
                $context->getRequest()->header('Idempotency-Key'),
                $input,
                200,
                fn(): array => $this->messaging->createDispatchPreflight($principal, $campaignId, $input)
            );
            return JsonResponse::success($executed['data'])
                ->withHeader('Idempotency-Replayed', $executed['replayed'] ? 'true' : 'false');
        });
    }
}
