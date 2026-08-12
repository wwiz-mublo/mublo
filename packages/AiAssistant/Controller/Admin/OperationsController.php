<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Controller\Admin;

use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\ViewResponse;
use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Service\SaasService;

final class OperationsController
{
    public function __construct(private SaasService $saas, private AuthContextInterface $authContext)
    {
    }

    public function index(array $params, Context $context): ViewResponse
    {
        $section = trim((string) ($params['section'] ?? 'customers'));
        $allowed = ['customers', 'analysis', 'schedules', 'devices', 'company', 'workers'];
        if (!in_array($section, $allowed, true)) {
            $section = 'customers';
        }

        $domainId = $context->getDomainId();
        $loginId = $this->authContext->currentUser()?->userId;
        try {
            // 회사 운영 화면은 Framework 관리자이면서 AI 회사 OWNER/MANAGER여야 한다.
            $dashboard = $this->saas->dashboard($domainId, $loginId);
            $workspaceSection = $section === 'workers' ? 'dashboard' : $section;
            $data = $this->saas->workspace($domainId, $loginId, $workspaceSection);
            if ($section === 'workers') {
                $data['workers'] = $dashboard['workers'];
            }
            $data['section'] = $section;
        } catch (ApiException $exception) {
            return ViewResponse::view('Error/403')->fullPage()->withStatusCode($exception->statusCode)->withData([
                'message' => $exception->getMessage(),
            ]);
        }

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Operations/Index')->withData($data + [
            'pageTitle' => 'Mublo AI 비서 운영',
        ]);
    }
}
