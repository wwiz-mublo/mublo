<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Packages\AiAssistant\Service\AuthService;
use Mublo\Packages\AiAssistant\Service\DeviceService;
use Mublo\Packages\AiAssistant\Service\IdempotencyService;

final class DeviceController extends ApiController
{
    public function __construct(
        AuthService $auth,
        private DeviceService $devices,
        private IdempotencyService $idempotency
    ) {
        parent::__construct($auth);
    }

    public function enroll(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $principal = $this->principal($context);
            $input = $this->json($context);
            $result = $this->idempotency->execute(
                (string) $principal['company_id'],
                'devices.enroll',
                $context->getRequest()->header('Idempotency-Key'),
                $input,
                201,
                fn(): array => $this->devices->enroll($principal, $input)
            );
            return JsonResponse::success($result['data'], '기기를 등록했습니다.', $result['status'])
                ->withHeader('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
        });
    }

    public function heartbeat(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($params, $context): JsonResponse {
            $principal = $this->principal($context);
            $input = $this->json($context);
            $deviceId = (string) ($params['device_id'] ?? '');
            $result = $this->idempotency->execute(
                (string) $principal['company_id'],
                'devices.heartbeat.' . $deviceId,
                $context->getRequest()->header('Idempotency-Key'),
                $input,
                200,
                fn(): array => $this->devices->heartbeat($principal, $deviceId, $input)
            );
            return JsonResponse::success($result['data'], '기기 상태를 갱신했습니다.')
                ->withHeader('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
        });
    }
}
