<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Packages\AiAssistant\Service\AuthService;
use Mublo\Packages\AiAssistant\Service\IdempotencyService;
use Mublo\Packages\AiAssistant\Service\MessageScheduleService;

final class ScheduleController extends ApiController
{
    public function __construct(
        AuthService $auth,
        private MessageScheduleService $schedules,
        private IdempotencyService $idempotency
    ) {
        parent::__construct($auth);
    }

    public function create(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $principal = $this->principal($context);
            $input = $this->json($context);
            $result = $this->idempotency->execute(
                (string) $principal['company_id'],
                'schedules.create',
                $context->getRequest()->header('Idempotency-Key'),
                $input,
                201,
                fn(): array => $this->schedules->create($principal, $input)
            );
            return JsonResponse::success($result['data'], '서버 발신 일정을 등록했습니다.', $result['status'])
                ->withHeader('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
        });
    }

    public function cancel(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($params, $context): JsonResponse {
            $principal = $this->principal($context);
            $scheduleId = (string) ($params['schedule_id'] ?? '');
            $input = $this->json($context);
            $result = $this->idempotency->execute(
                (string) $principal['company_id'],
                'schedules.cancel.' . $scheduleId,
                $context->getRequest()->header('Idempotency-Key'),
                $input,
                200,
                fn(): array => $this->schedules->cancel($principal, $scheduleId)
            );
            return JsonResponse::success($result['data'], '서버 발신 일정을 취소했습니다.')
                ->withHeader('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
        });
    }

    public function dispatch(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($params, $context): JsonResponse {
            $principal = $this->principal($context);
            $request = $context->getRequest();
            return JsonResponse::success($this->schedules->dispatchPayload(
                $principal,
                (string) ($params['schedule_id'] ?? ''),
                (string) $request->query('device_id', ''),
                (string) $request->query('dispatch_id', ''),
                (int) $request->query('revision', 0)
            ));
        });
    }

    public function acknowledge(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($params, $context): JsonResponse {
            $principal = $this->principal($context);
            return JsonResponse::success($this->schedules->acknowledge(
                $principal,
                (string) ($params['schedule_id'] ?? ''),
                $this->json($context)
            ));
        });
    }
}
