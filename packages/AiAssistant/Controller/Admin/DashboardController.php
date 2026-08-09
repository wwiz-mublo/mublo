<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\ViewResponse;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Service\SaasService;

final class DashboardController
{
    public function __construct(private SaasService $saas, private AuthContextInterface $authContext)
    {
    }

    public function index(array $params, Context $context): ViewResponse
    {
        try {
            $data = $this->saas->dashboard($context->getDomainId(), $this->authContext->currentUser()?->userId);
        } catch (ApiException $exception) {
            return ViewResponse::view('Error/403')->fullPage()->withStatusCode($exception->statusCode)->withData([
                'message' => $exception->getMessage(),
            ]);
        }
        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Dashboard/Index')->withData($data + [
            'pageTitle' => 'Mublo AI 비서',
        ]);
    }
}
