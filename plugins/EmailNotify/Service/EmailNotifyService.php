<?php
declare(strict_types=1);

namespace Mublo\Plugin\EmailNotify\Service;

use Mublo\Contract\Notification\NotificationGatewayInterface;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Mail\Mailer;
use Mublo\Infrastructure\Mail\MailMessage;
use Mublo\Plugin\EmailNotify\Repository\EmailConfigRepository;
use Mublo\Plugin\EmailNotify\Repository\EmailLogRepository;
use Mublo\Plugin\EmailNotify\Repository\EmailTemplateRepository;
use Mublo\Contract\Site\DomainQueryInterface;

/**
 * EmailNotifyService
 *
 * 코어 Mailer(서버 메일/SMTP)를 이용한 이메일 알림 발송 + 템플릿 관리.
 * 외부 API 인증이 없으므로 센드온 대비 단순하다(채널/발신번호/예약 없음).
 */
class EmailNotifyService
{
    /**
     * 코어가 도메인 설정에서 채워주는 공통(사이트) 변수.
     * 픽커 광고(컨트롤러)와 발송 시 값 주입(send)이 이 목록을 공유한다.
     * 키 => 픽커에 표시할 라벨.
     */
    /** 코어 이메일 게이트웨이 등록 키 (ServiceProvider 의 'core_email' 고정 키).
     *  메타 채널 매칭으로 "첫 이메일 게이트웨이"를 찾으면 다른 이메일 게이트웨이가
     *  먼저 등록됐을 때 이 플러그인의 템플릿 계약을 모르는 게이트웨이로 전달될 수
     *  있으므로 정확히 이 키를 조회한다. */
    public const CORE_EMAIL_GATEWAY_KEY = 'core_email';

    public const SITE_VARIABLE_LABELS = [
        'logo'         => '로고 (이미지 URL)',
        'site_title'   => '사이트 제목',
        'site_url'     => '사이트 주소',
        'company_name' => '회사명',
        'today'        => '오늘 날짜',
    ];

    public function __construct(
        private EmailConfigRepository $configRepository,
        private EmailTemplateRepository $templateRepository,
        private EmailLogRepository $logRepository,
        private Mailer $mailer,
        private DomainQueryInterface $domainRepository,
        private ?ContractRegistry $contractRegistry = null
    ) {
    }

    // === 발신 설정 ===

    public function getConfig(int $domainId): array
    {
        $defaults = [
            'from_name' => '',
            'from_email' => '',
            'is_active' => 1,
        ];

        return array_merge($defaults, $this->configRepository->findByDomainId($domainId) ?? []);
    }

    public function saveConfig(int $domainId, array $data): Result
    {
        $fromEmail = trim((string) ($data['from_email'] ?? ''));

        if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return Result::failure('발신 이메일 형식이 올바르지 않습니다.');
        }

        $this->configRepository->upsert($domainId, [
            'from_name' => trim((string) ($data['from_name'] ?? '')),
            'from_email' => $fromEmail,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ]);

        return Result::success('발신 설정이 저장되었습니다.');
    }

    // === 템플릿 관리 ===

    public function getTemplates(int $domainId, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $totalItems = $this->templateRepository->countByDomain($domainId);

        return [
            'items' => $this->templateRepository->getList($domainId, $perPage, $offset),
            'pagination' => [
                'totalItems' => $totalItems,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => max(1, (int) ceil($totalItems / $perPage)),
            ],
        ];
    }

    public function getTemplate(int $domainId, int $templateId): ?array
    {
        return $this->templateRepository->findById($domainId, $templateId);
    }

    public function saveTemplate(int $domainId, array $data): Result
    {
        $templateId = (int) ($data['template_id'] ?? 0);
        $templateCode = trim((string) ($data['template_code'] ?? ''));
        $templateName = trim((string) ($data['template_name'] ?? ''));
        $subject = trim((string) ($data['subject'] ?? ''));

        if ($templateCode === '') {
            return Result::failure('템플릿 코드는 필수 항목입니다.');
        }

        if ($templateName === '') {
            return Result::failure('템플릿명은 필수 항목입니다.');
        }

        if ($subject === '') {
            return Result::failure('메일 제목은 필수 항목입니다.');
        }

        if ($this->templateRepository->existsByCode($domainId, $templateCode, $templateId)) {
            return Result::failure('이미 사용 중인 템플릿 코드입니다.');
        }

        $payload = [
            'template_code' => $templateCode,
            'template_name' => $templateName,
            'subject' => $subject,
            'body' => (string) ($data['body'] ?? ''),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];

        if ($templateId > 0) {
            $updated = $this->templateRepository->update($domainId, $templateId, $payload);
            if (!$updated) {
                return Result::failure('템플릿 수정에 실패했습니다.');
            }
            return Result::success('템플릿이 수정되었습니다.', ['template_id' => $templateId]);
        }

        $newId = $this->templateRepository->create($domainId, $payload);
        if ($newId <= 0) {
            return Result::failure('템플릿 생성에 실패했습니다.');
        }

        return Result::success('템플릿이 저장되었습니다.', ['template_id' => $newId]);
    }

    public function deleteTemplate(int $domainId, int $templateId): Result
    {
        $deleted = $this->templateRepository->delete($domainId, $templateId);

        return $deleted
            ? Result::success('템플릿이 삭제되었습니다.')
            : Result::failure('템플릿 삭제에 실패했습니다.');
    }

    // === 템플릿 렌더링 (코어 이메일 게이트웨이의 공급자 계약이 소비) ===

    /**
     * 채널 트리 노출용 활성 템플릿 목록.
     *
     * 플러그인 전체(is_active)가 꺼져 있으면 빈 목록 — 렌더 단계에서 어차피
     * 실패하는 템플릿을 관리자 채널 목록에 노출하지 않는다 ("실제 사용
     * 가능한 것만 노출" 원칙과 일치).
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function getActiveTemplateOptions(int $domainId): array
    {
        $config = $this->getConfig($domainId);
        if (empty($config['is_active'])) {
            return [];
        }

        $options = [];
        foreach ($this->templateRepository->getList($domainId, 200, 0) as $tpl) {
            if (empty($tpl['is_active'])) {
                continue;
            }
            $options[] = [
                'code' => (string) ($tpl['template_code'] ?? ''),
                'name' => (string) ($tpl['template_name'] ?? ''),
            ];
        }

        return $options;
    }

    /**
     * 템플릿 렌더링 — 치환·URL 절대화·미치환 검증까지 끝낸 발송 준비물 반환.
     *
     * 모르는 코드면 null(다음 공급자로), 렌더 불가면 RuntimeException.
     *
     * @return array{subject: string, body: string, from_email?: string, from_name?: string}|null
     * @throws \RuntimeException
     */
    public function renderTemplate(int $domainId, string $templateCode, array $fieldValues): ?array
    {
        $template = $this->templateRepository->findByCode($domainId, $templateCode);
        if ($template === null) {
            return null; // 이 공급자의 템플릿이 아님
        }

        $config = $this->getConfig($domainId);
        if (empty($config['is_active'])) {
            throw new \RuntimeException('이메일 발송이 비활성화되어 있습니다.');
        }

        if (empty($template['is_active'])) {
            throw new \RuntimeException("비활성 템플릿입니다: {$templateCode}");
        }

        // 공통(사이트) 변수를 먼저 깔고, 호출자가 준 값이 같은 키면 우선한다.
        $fieldValues = array_merge($this->siteFieldValues($domainId), $fieldValues);

        $subject = $this->substitute((string) ($template['subject'] ?? ''), $fieldValues);
        $body = $this->substitute((string) ($template['body'] ?? ''), $fieldValues);

        // 본문의 루트상대 URL(/storage/...)을 발송 시점의 현재 도메인으로 절대화.
        // 본문엔 상대경로로 저장되므로 도메인이 바뀌어도 깨지지 않는다.
        $body = $this->absolutizeUrls($body, $this->domainBaseUrl($domainId));

        // 미치환 토큰 감지
        $unresolved = $this->detectUnsubstitutedTokens($subject . ' ' . $body);
        if (!empty($unresolved)) {
            throw new \RuntimeException('미치환 변수가 있습니다: ' . implode(', ', $unresolved));
        }

        $rendered = ['subject' => $subject, 'body' => $body];

        if (!empty($config['from_email'])) {
            $rendered['from_email'] = (string) $config['from_email'];
            if ($config['from_name'] !== '') {
                $rendered['from_name'] = (string) $config['from_name'];
            }
        }

        return $rendered;
    }

    /**
     * 발송 결과 이력 기록 (코어 게이트웨이의 onSent 통지가 소비).
     */
    public function logSendResult(int $domainId, string $templateCode, string $recipient, string $subject, bool $success, string $message): void
    {
        $this->logRepository->create($domainId, [
            'template_code' => $templateCode,
            'recipient' => $recipient,
            'subject' => $subject,
            'result_code' => $success ? 'OK' : 'FAIL',
            'result_message' => $success ? '발송 성공' : $message,
            'request_payload' => json_encode(['subject' => $subject], JSON_UNESCAPED_UNICODE),
        ]);
    }

    // === 메일 발송 (관리자 테스트 발송 전용 — 계약 통로 발송은 코어 게이트웨이 담당) ===

    public function send(int $domainId, string $templateCode, string $recipient, array $fieldValues): Result
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return Result::failure("수신 이메일 형식이 올바르지 않습니다: {$recipient}");
        }

        // 코어 이메일 게이트웨이(유일 전송로) 경유 — 도메인 알림 이메일 정책이
        // 적용되고, 렌더는 공급자 계약으로 이 서비스에 되돌아오며, 발송 이력은
        // onSent 통지로 기록된다. 관리자 테스트 발송도 실발송과 같은 경로를 탄다.
        //
        // fail-closed: 레지스트리가 주입된 운영 환경에서 코어 게이트웨이 조회·생성이
        // 실패하면 직접 전송으로 우회하지 않고 명시적으로 실패한다 — 우회하면
        // 알림 이메일 정책이 뚫린다. 직접 전송 폴백은 레지스트리 자체가 주입되지
        // 않은 환경(테스트·부분 구성)에만 허용된다.
        if ($this->contractRegistry !== null) {
            try {
                $gateway = $this->contractRegistry->get(NotificationGatewayInterface::class, self::CORE_EMAIL_GATEWAY_KEY);
            } catch (\Throwable $e) {
                return Result::failure('코어 이메일 게이트웨이를 사용할 수 없어 발송하지 않습니다: ' . $e->getMessage());
            }

            if (!$gateway instanceof NotificationGatewayInterface) {
                return Result::failure('코어 이메일 게이트웨이(core_email)가 등록되어 있지 않습니다.');
            }

            $result = $gateway->send('email', $templateCode, $recipient, ['domain_id' => $domainId] + $fieldValues);

            return $result->success
                ? Result::success('메일이 발송되었습니다.')
                : Result::failure($result->message !== '' ? $result->message : '메일 발송에 실패했습니다.');
        }

        // 레지스트리 미주입 폴백 (테스트·부분 구성 환경) — 직접 전송
        try {
            $rendered = $this->renderTemplate($domainId, $templateCode, $fieldValues);
        } catch (\RuntimeException $e) {
            return $this->fail($domainId, $templateCode, $recipient, '', 'RENDER_FAIL', $e->getMessage());
        }

        if ($rendered === null) {
            return $this->fail($domainId, $templateCode, $recipient, '', 'NO_TEMPLATE', "템플릿을 찾을 수 없습니다: {$templateCode}");
        }

        $subject = $rendered['subject'];

        $message = (new MailMessage())
            ->to($recipient)
            ->subject($subject)
            ->html($rendered['body']);

        if (!empty($rendered['from_email'])) {
            $message->from($rendered['from_email'], $rendered['from_name'] ?? null);
        }

        try {
            $sent = $this->mailer->send($message);
        } catch (\Throwable $e) {
            return $this->fail($domainId, $templateCode, $recipient, $subject, 'EXCEPTION', $e->getMessage());
        }

        if (!$sent) {
            return $this->fail($domainId, $templateCode, $recipient, $subject, 'FAIL', '메일 발송에 실패했습니다.');
        }

        $this->logSendResult($domainId, $templateCode, $recipient, $subject, true, '발송 성공');

        return Result::success('메일이 발송되었습니다.');
    }

    // === 발송 이력 ===

    public function getLogs(int $domainId, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $totalItems = $this->logRepository->countByDomain($domainId);

        return [
            'items' => $this->logRepository->getList($domainId, $perPage, $offset),
            'pagination' => [
                'totalItems' => $totalItems,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => max(1, (int) ceil($totalItems / $perPage)),
            ],
        ];
    }

    // === 내부 헬퍼 ===

    private function fail(int $domainId, string $templateCode, string $recipient, string $subject, string $code, string $message): Result
    {
        $this->logRepository->create($domainId, [
            'template_code' => $templateCode,
            'recipient' => $recipient,
            'subject' => $subject,
            'result_code' => $code,
            'result_message' => $message,
            'request_payload' => json_encode(['subject' => $subject], JSON_UNESCAPED_UNICODE),
        ]);

        return Result::failure($message);
    }

    /**
     * 공통(사이트) 변수 값을 도메인 설정에서 채운다.
     *
     * SITE_VARIABLE_LABELS의 모든 키를 반드시 포함한다(미설정이면 빈 문자열).
     * 키가 빠지면 미치환 토큰 가드에 걸려 발송이 실패하므로 항상 채운다.
     */
    private function siteFieldValues(int $domainId): array
    {
        $values = [
            'logo'         => '',
            'site_title'   => '',
            'site_url'     => '',
            'company_name' => '',
            'today'        => date('Y-m-d'),
        ];

        $domain = $this->domainRepository->find($domainId);
        if ($domain === null) {
            return $values;
        }

        $baseUrl = $this->domainBaseUrl($domainId);

        $values['site_title'] = $domain->siteTitle;
        $values['site_url'] = $baseUrl;

        $company = $domain->companyConfig;
        $values['company_name'] = (string) ($company['name'] ?? '');

        // 로고는 이메일에서 보이도록 절대 URL로 변환 (상대경로면 도메인 prefix)
        $seo = $domain->seoConfig;
        $logo = (string) ($seo['logo_pc'] ?? '');
        if ($logo !== '' && !preg_match('#^https?://#i', $logo)) {
            $logo = $baseUrl . '/' . ltrim($logo, '/');
        }
        $values['logo'] = $logo;

        return $values;
    }

    /**
     * 도메인의 기준 URL (https://{host}). 미확인 시 빈 문자열.
     */
    private function domainBaseUrl(int $domainId): string
    {
        $domain = $this->domainRepository->find($domainId);
        if ($domain === null) {
            return '';
        }
        $host = $domain->hostname;
        return $host !== '' ? 'https://' . $host : '';
    }

    /**
     * 본문 내 루트상대 URL(src="/...", href="/...")을 절대 URL로 변환.
     * 프로토콜상대(//), 절대(http(s)://)는 건드리지 않는다.
     */
    private function absolutizeUrls(string $html, string $baseUrl): string
    {
        if ($html === '' || $baseUrl === '') {
            return $html;
        }

        return preg_replace_callback(
            '/((?:src|href)\s*=\s*["\'])\/(?!\/)/i',
            fn($m) => $m[1] . $baseUrl . '/',
            $html
        ) ?? $html;
    }

    /**
     * 해당 파일명을 본문에서 참조하는 템플릿 목록 (이미지 삭제 안전 검사용).
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function findTemplatesUsingImage(int $domainId, string $filename): array
    {
        if ($filename === '') {
            return [];
        }

        $used = [];
        foreach ($this->templateRepository->getList($domainId, 1000, 0) as $tpl) {
            if (str_contains((string) ($tpl['body'] ?? ''), $filename)) {
                $used[] = [
                    'code' => (string) ($tpl['template_code'] ?? ''),
                    'name' => (string) ($tpl['template_name'] ?? ''),
                ];
            }
        }

        return $used;
    }

    /**
     * 본문/제목의 #{field} 토큰을 fieldValues로 치환한다.
     */
    public function substitute(string $text, array $fieldValues): string
    {
        if ($text === '' || empty($fieldValues)) {
            return $text;
        }

        $replacements = [];
        foreach ($fieldValues as $key => $value) {
            $replacements['#{' . $key . '}'] = (string) $value;
        }

        return strtr($text, $replacements);
    }

    private function detectUnsubstitutedTokens(string $text): array
    {
        if ($text === '') {
            return [];
        }

        if (preg_match_all('/#{[^}]+}/', $text, $matches)) {
            return array_values(array_unique($matches[0]));
        }

        return [];
    }
}
