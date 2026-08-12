<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Controller\Front;

use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\ViewResponse;
use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Service\SaasService;

final class WorkspaceController
{
    public function __construct(private SaasService $saas, private AuthContextInterface $authContext)
    {
    }

    public function index(array $params, Context $context): ViewResponse
    {
        $section = trim((string) ($params['section'] ?? 'dashboard'));
        $data = [
            'section' => $section,
            'unavailable' => null,
            'principal' => null,
            'summary' => [],
            'customers' => [],
            'batches' => [],
            'schedules' => [],
            'devices' => [],
            'subscription' => null,
            'companyUsers' => [],
        ];

        try {
            $data = $this->saas->workspace(
                $context->getDomainId(),
                $this->authContext->currentUser()?->userId,
                $section
            ) + ['unavailable' => null];
        } catch (ApiException $exception) {
            $data['unavailable'] = $exception->getMessage();
        }

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Front/Workspace/Index')->withData($data + [
            'pageTitle' => 'AI 비서 워크스페이스',
            '_pageConfig' => ['layout_type' => 1, 'use_fullpage' => 1],
            'frameworkUser' => $this->authContext->currentUser(),
        ]);
    }
}
