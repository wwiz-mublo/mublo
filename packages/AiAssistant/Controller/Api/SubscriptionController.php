<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Packages\AiAssistant\Service\AuthService;
use Mublo\Packages\AiAssistant\Service\SubscriptionService;

final class SubscriptionController extends ApiController
{
    public function __construct(AuthService $auth, private SubscriptionService $subscriptions)
    {
        parent::__construct($auth);
    }

    public function current(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            return JsonResponse::success($this->subscriptions->summary($this->principal($context)));
        });
    }
}
