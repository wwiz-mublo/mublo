<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Service\AuthService;
use Mublo\Packages\AiAssistant\Service\CustomerSyncService;
use Mublo\Packages\AiAssistant\Service\IdempotencyService;

final class CustomerSyncController extends ApiController
{
    public function __construct(
        AuthService $auth,
        private CustomerSyncService $sync,
        private IdempotencyService $idempotency
    ) {
        parent::__construct($auth);
    }

    public function bootstrap(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $principal = $this->principal($context);
            $cursorValue = $context->getRequest()->query('cursor');
            $cursor = is_string($cursorValue) ? $cursorValue : null;
            $limit = $this->limit($context->getRequest()->query('limit', 200));
            return JsonResponse::success($this->sync->bootstrap($principal, $cursor, $limit));
        });
    }

    public function delta(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $principal = $this->principal($context);
            $cursor = (string) $context->getRequest()->query('cursor', '');
            if ($cursor === '') {
                throw new ApiException('SYNC_CURSOR_REQUIRED', '동기화 cursor가 필요합니다.', 400);
            }
            $limit = $this->limit($context->getRequest()->query('limit', 200));
            return JsonResponse::success($this->sync->delta($principal, $cursor, $limit));
        });
    }

    public function push(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $principal = $this->principal($context);
            $input = $this->json($context);
            $deviceId = (string) ($input['device_id'] ?? '');
            $records = $input['records'] ?? null;
            if (!is_array($records)) {
                throw new ApiException('SYNC_BATCH_INVALID', '동기화 레코드 목록이 필요합니다.', 422);
            }
            $result = $this->idempotency->execute(
                (string) $principal['company_id'],
                'sync.customers.push',
                $context->getRequest()->header('Idempotency-Key'),
                $input,
                200,
                fn(): array => $this->sync->push($principal, $deviceId, array_values($records))
            );
            return JsonResponse::success($result['data'])
                ->withHeader('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
        });
    }

    private function limit(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new ApiException('PAGINATION_LIMIT_INVALID', '조회 개수를 확인해 주세요.', 400);
        }
        return max(1, min(500, (int) $value));
    }
}
