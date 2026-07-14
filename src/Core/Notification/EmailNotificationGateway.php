<?php

namespace Mublo\Core\Notification;

use Mublo\Contract\Notification\EmailTemplateProviderInterface;
use Mublo\Contract\Notification\NotificationGatewayInterface;
use Mublo\Contract\Notification\NotificationSendResult;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Infrastructure\Mail\Mailer;
use Mublo\Infrastructure\Mail\MailMessage;

/**
 * Core 이메일 알림 게이트웨이 — 이메일 채널의 유일 발송 통로.
 *
 * 역할 분담:
 *  - 코어(이 클래스): 전송로. Mailer(서버 메일/SMTP)로 실제 발송하며
 *    ContractRegistry 에 'core_email' 게이트웨이로 항상 등록된다.
 *  - 확장(EmailTemplateProviderInterface): 내용물. 템플릿 목록·렌더링을
 *    공급하고 onSent 로 발송 결과를 통지받아 자체 이력을 기록한다.
 *
 * 템플릿 코드가 없는 발송은 fieldValues 의 subject/body 를 그대로 쓴다
 * (소비자가 본문을 직접 구성하는 Mshop 주문 알림, AutoForm 이메일 자동응답 등).
 */
class EmailNotificationGateway implements NotificationGatewayInterface
{
    public function __construct(
        private Mailer $mailer,
        private ?ContractRegistry $registry = null,
        private ?\Closure $domainIdResolver = null,
        private ?\Closure $emailPolicyResolver = null,
    ) {
    }

    public function send(string $channel, string $templateCode, string $recipient, array $fieldValues): NotificationSendResult
    {
        if ($channel !== 'email') {
            return new NotificationSendResult(false, "Unsupported channel: {$channel}");
        }

        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return new NotificationSendResult(false, "수신 이메일이 올바르지 않습니다: {$recipient}");
        }

        // 도메인 문맥이 없으면 발송하지 않는다 — domain 1 로 추측 폴백하면
        // 다른 도메인의 템플릿·발신자·전송 정책을 쓸 수 있다 (재처리·CLI 등)
        $domainId = $this->resolveDomainId($fieldValues);
        if ($domainId <= 0) {
            return new NotificationSendResult(false, '도메인 문맥을 해석할 수 없어 이메일을 발송하지 않습니다.');
        }

        // 도메인 이메일 전송 정책 — 운영자가 채널을 끄면 템플릿 유무와 무관하게 전체 중단
        if (!$this->isEmailChannelEnabled($domainId)) {
            return new NotificationSendResult(false, '이메일 발송이 비활성화되어 있습니다 (도메인 설정).');
        }

        // 1) 템플릿 코드가 있으면 공급자 계약에 렌더 위임
        if ($templateCode !== '') {
            return $this->sendTemplated($domainId, $templateCode, $recipient, $fieldValues);
        }

        // 2) 템플릿 없는 발송 — 소비자가 구성한 subject/body 그대로
        $subject = (string) ($fieldValues['subject'] ?? '알림');
        $body = (string) ($fieldValues['body'] ?? $this->buildDefaultBody($fieldValues));

        return $this->transport($recipient, $subject, $body);
    }

    public function getSupportedChannels(): array
    {
        return ['email' => '이메일'];
    }

    /**
     * 등록된 템플릿 공급자들의 목록을 병합해 노출한다.
     * 공급자가 없으면 템플릿 없는 채널(수기 subject/body 발송 전용)로 보인다.
     */
    public function getChannelTree(int $domainId): array
    {
        $templates = [];
        $seenCodes = [];
        foreach ($this->templateProviders() as $providerKey => $provider) {
            try {
                foreach ($provider->getTemplates($domainId) as $tpl) {
                    $code = (string) ($tpl['code'] ?? '');
                    if ($code === '') {
                        continue;
                    }
                    // 공급자 간 코드 충돌: 발송(sendTemplated)이 먼저 등록된 공급자
                    // 우선이므로 트리도 first-wins 로 통일하고 진단만 남긴다
                    if (isset($seenCodes[$code])) {
                        error_log(sprintf(
                            '[EmailGateway] duplicate template code "%s" — kept provider "%s", ignored "%s"',
                            $code,
                            $seenCodes[$code],
                            (string) $providerKey
                        ));
                        continue;
                    }
                    $seenCodes[$code] = (string) $providerKey;
                    $templates[] = ['code' => $code, 'name' => (string) ($tpl['name'] ?? $code)];
                }
            } catch (\Throwable) {
                continue; // 공급자 하나의 실패가 트리 전체를 막지 않는다
            }
        }

        return [
            'email' => [
                'label' => '이메일',
                'channels' => $templates === [] ? [] : [
                    ['id' => 0, 'name' => '이메일', 'templates' => $templates],
                ],
            ],
        ];
    }

    private function sendTemplated(int $domainId, string $templateCode, string $recipient, array $fieldValues): NotificationSendResult
    {
        foreach ($this->templateProviders() as $provider) {
            try {
                $rendered = $provider->render($domainId, $templateCode, $fieldValues);
            } catch (\Throwable $e) {
                $this->notifySent($provider, $domainId, $templateCode, $recipient, '', false, $e->getMessage());
                return new NotificationSendResult(false, $e->getMessage());
            }

            if ($rendered === null) {
                continue; // 이 공급자의 템플릿이 아님 — 다음 공급자
            }

            $subject = (string) ($rendered['subject'] ?? '알림');
            $result = $this->transport(
                $recipient,
                $subject,
                (string) ($rendered['body'] ?? ''),
                isset($rendered['from_email']) ? (string) $rendered['from_email'] : null,
                isset($rendered['from_name']) ? (string) $rendered['from_name'] : null,
            );

            $this->notifySent($provider, $domainId, $templateCode, $recipient, $subject, $result->success, $result->message);

            return $result;
        }

        return new NotificationSendResult(false, "이메일 템플릿을 찾을 수 없습니다: {$templateCode}");
    }

    private function transport(
        string $recipient,
        string $subject,
        string $body,
        ?string $fromEmail = null,
        ?string $fromName = null,
    ): NotificationSendResult {
        try {
            $message = (new MailMessage())
                ->to($recipient)
                ->subject($subject)
                ->html($body);

            if ($fromEmail !== null && $fromEmail !== '') {
                $message->from($fromEmail, $fromName !== null && $fromName !== '' ? $fromName : null);
            }

            $sent = $this->mailer->send($message);

            return new NotificationSendResult($sent, $sent ? 'Email sent' : 'Email sending failed');
        } catch (\Throwable $e) {
            return new NotificationSendResult(false, $e->getMessage());
        }
    }

    private function notifySent(
        EmailTemplateProviderInterface $provider,
        int $domainId,
        string $templateCode,
        string $recipient,
        string $subject,
        bool $success,
        string $message,
    ): void {
        try {
            $provider->onSent($domainId, $templateCode, $recipient, $subject, $success, $message);
        } catch (\Throwable) {
            // 이력 기록 실패가 발송 결과를 바꾸지 않는다
        }
    }

    /**
     * @return array<string, EmailTemplateProviderInterface> 등록 키 => 공급자 (등록 순서 유지)
     */
    private function templateProviders(): array
    {
        if ($this->registry === null) {
            return [];
        }

        $providers = [];
        try {
            foreach ($this->registry->keys(EmailTemplateProviderInterface::class) as $key) {
                $providers[(string) $key] = $this->registry->get(EmailTemplateProviderInterface::class, $key);
            }
        } catch (\Throwable) {
            // 레지스트리 미구성 환경 — 템플릿 공급 없음
        }

        return $providers;
    }

    /**
     * 게이트웨이 계약 send()에는 domainId 가 없다 — 소비자가 fieldValues 에
     * 실어 보낸 domain_id 를 우선하고, 없으면 요청 컨텍스트에서 해석한다.
     * 둘 다 없으면 0 — 호출부가 발송을 거부한다 (추측 폴백 금지).
     */
    private function resolveDomainId(array $fieldValues): int
    {
        $fromValues = (int) ($fieldValues['domain_id'] ?? 0);
        if ($fromValues > 0) {
            return $fromValues;
        }

        try {
            $id = $this->domainIdResolver !== null ? ($this->domainIdResolver)() : null;
            return (int) ($id ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 도메인 알림 이메일 전송 정책 (site_config.email_channel_enabled).
     *
     * 리졸버 미주입(하위 호환·테스트 환경)은 허용하지만, 리졸버가 있는
     * 운영 환경에서 정책 조회가 실패하면 발송을 거부한다 — 운영자가 꺼 둔
     * 채널로 발송되는 쪽이 발송 누락보다 위험하다.
     *
     * 비밀번호 재설정 등 계정 필수 메일은 이 게이트웨이가 아니라 코어
     * Mailer 직접 경로를 쓰므로 이 정책의 대상이 아니다 (의도된 예외).
     */
    private function isEmailChannelEnabled(int $domainId): bool
    {
        if ($this->emailPolicyResolver === null) {
            return true;
        }

        try {
            return (bool) ($this->emailPolicyResolver)($domainId);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[EmailGateway] email policy lookup failed for domain %d — refusing to send: %s',
                $domainId,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * 템플릿 없을 때 fieldValues를 HTML 테이블로 변환
     */
    private function buildDefaultBody(array $fieldValues): string
    {
        $rows = '';
        foreach ($fieldValues as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $label = htmlspecialchars((string) $key);
            $val = htmlspecialchars((string) $value);
            $rows .= '<tr><td style="padding:6px 12px;font-weight:bold;border:1px solid #ddd">' . $label . '</td>'
                . '<td style="padding:6px 12px;border:1px solid #ddd">' . $val . '</td></tr>';
        }

        return '<table style="border-collapse:collapse">' . $rows . '</table>';
    }
}
