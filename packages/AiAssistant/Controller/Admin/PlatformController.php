<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Controller\Admin;

use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\ViewResponse;
use Mublo\Packages\AiAssistant\Service\SaasService;

final class PlatformController
{
    public function __construct(private SaasService $saas, private AuthContextInterface $authContext)
    {
    }

    public function index(array $params, Context $context): ViewResponse
    {
        if (!$this->authContext->isSuper()) {
            return ViewResponse::view('Error/403')->fullPage()->withStatusCode(403)->withData([
                'message' => '플랫폼 전체 운영 화면은 SUPER 관리자만 볼 수 있습니다.',
            ]);
        }
        $section = trim((string) ($params['section'] ?? 'dashboard'));
        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Platform/Index')->withData(
            $this->saas->platform($section) + ['pageTitle' => 'Mublo AI 플랫폼 운영']
        );
    }
}
