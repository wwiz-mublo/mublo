<?php
declare(strict_types=1);

namespace Mublo\Plugin\EmailNotify;

use Mublo\Contract\Notification\EmailTemplateProviderInterface;
use Mublo\Plugin\EmailNotify\Service\EmailNotifyService;

/**
 * EmailNotify 템플릿 공급자 — 코어 이메일 게이트웨이에 내용물을 공급한다.
 *
 * 발송(전송로)은 코어 'core_email' 게이트웨이가 담당하고, 이 플러그인은
 * 템플릿 목록·렌더링(치환·URL 절대화·미치환 검증·발신자 설정)과
 * 발송 이력 기록을 맡는다.
 */
class EmailTemplateProvider implements EmailTemplateProviderInterface
{
    public function __construct(private EmailNotifyService $service)
    {
    }

    public function getTemplates(int $domainId): array
    {
        return $this->service->getActiveTemplateOptions($domainId);
    }

    public function render(int $domainId, string $templateCode, array $fieldValues): ?array
    {
        return $this->service->renderTemplate($domainId, $templateCode, $fieldValues);
    }

    public function onSent(
        int $domainId,
        string $templateCode,
        string $recipient,
        string $subject,
        bool $success,
        string $message
    ): void {
        $this->service->logSendResult($domainId, $templateCode, $recipient, $subject, $success, $message);
    }
}
