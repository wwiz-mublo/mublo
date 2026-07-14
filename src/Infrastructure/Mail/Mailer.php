<?php
namespace Mublo\Infrastructure\Mail;

use Mublo\Infrastructure\Log\Logger;

/**
 * Mailer
 *
 * 이메일 전송 인프라 클래스
 * - PHP mail() 함수 기반 (기본)
 * - SMTP 지원 (fsockopen)
 * - 템플릿 기반 메일 발송
 *
 * 설정:
 * config/mail.php 또는 도메인별 설정
 */
class Mailer
{
    private array $config;
    private ?Logger $logger;

    public function __construct(?array $config = null, ?Logger $logger = null)
    {
        $this->config = $config ?? $this->loadConfig();
        $this->logger = $logger;
    }

    /**
     * 설정 파일 로드
     */
    private function loadConfig(): array
    {
        $configPath = defined('MUBLO_CONFIG_PATH') ? MUBLO_CONFIG_PATH . '/mail.php' : 'config/mail.php';

        if (file_exists($configPath)) {
            return require $configPath;
        }

        return $this->getDefaultConfig();
    }

    /**
     * 기본 설정
     */
    private function getDefaultConfig(): array
    {
        return [
            'driver' => 'mail',  // mail, smtp
            'from' => [
                'address' => 'noreply@example.com',
                'name' => 'Mublo Framework',
            ],
            'smtp' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',  // tls, ssl, null
                'username' => '',
                'password' => '',
                'timeout' => 30,
            ],
        ];
    }

    /**
     * 메일 전송
     *
     * @param MailMessage $message 메일 메시지
     * @return bool
     */
    public function send(MailMessage $message): bool
    {
        // 발신자 기본값 설정
        if (!$message->getFrom()) {
            $message->from(
                $this->config['from']['address'] ?? 'noreply@example.com',
                $this->config['from']['name'] ?? null
            );
        }

        $driver = $this->config['driver'] ?? 'mail';

        try {
            $result = match ($driver) {
                'smtp' => $this->sendViaSMTP($message),
                default => $this->sendViaMail($message),
            };

            if ($result && $this->logger) {
                $this->logger->info('Mail sent', [
                    'to' => array_column($message->getTo(), 'email'),
                    'subject' => $message->getSubject(),
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Mail failed: ' . $e->getMessage(), [
                    'to' => array_column($message->getTo(), 'email'),
                    'subject' => $message->getSubject(),
                ]);
            }
            return false;
        }
    }

    /**
     * 간편 전송
     */
    public function sendTo(string $to, string $subject, string $body, bool $isHtml = true): bool
    {
        $message = new MailMessage();
        $message->to($to)->subject($subject);

        if ($isHtml) {
            $message->html($body);
        } else {
            $message->text($body);
        }

        return $this->send($message);
    }

    /**
     * 템플릿 기반 전송
     *
     * @param string $to 수신자
     * @param string $subject 제목
     * @param string $template 템플릿 경로 (views/ 기준)
     * @param array $data 템플릿 데이터
     * @return bool
     */
    public function sendTemplate(string $to, string $subject, string $template, array $data = []): bool
    {
        $body = $this->renderTemplate($template, $data);

        if ($body === null) {
            return false;
        }

        return $this->sendTo($to, $subject, $body, true);
    }

    /**
     * 대량 메일 전송
     *
     * @param array $recipients [['email' => '...', 'name' => '...', 'data' => [...]], ...]
     * @param string $subject 제목
     * @param string $template 템플릿 경로
     * @param array $commonData 공통 데이터
     * @return array ['success' => int, 'failed' => int, 'errors' => [...]]
     */
    public function sendBulk(array $recipients, string $subject, string $template, array $commonData = []): array
    {
        $result = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($recipients as $recipient) {
            $email = $recipient['email'] ?? null;
            if (!$email) {
                continue;
            }

            // 개별 데이터와 공통 데이터 병합
            $data = array_merge($commonData, $recipient['data'] ?? []);
            $data['recipient_name'] = $recipient['name'] ?? '';
            $data['recipient_email'] = $email;

            if ($this->sendTemplate($email, $subject, $template, $data)) {
                $result['success']++;
            } else {
                $result['failed']++;
                $result['errors'][] = $email;
            }

            // Rate limiting (100ms 간격)
            usleep(100000);
        }

        return $result;
    }

    /**
     * PHP mail() 함수로 전송
     */
    private function sendViaMail(MailMessage $message): bool
    {
        $to = $this->formatAddressList($message->getTo());
        $subject = $this->encodeHeader($message->getSubject());
        $body = $message->getBody();

        // 헤더 구성
        $headers = $this->buildHeaders($message);

        return mail($to, $subject, $body, implode("\r\n", $headers));
    }

    /**
     * SMTP로 전송
     */
    private function sendViaSMTP(MailMessage $message): bool
    {
        $smtp = $this->config['smtp'] ?? [];
        $host = $smtp['host'] ?? '';
        $port = $smtp['port'] ?? 587;
        $encryption = $smtp['encryption'] ?? 'tls';
        $username = $smtp['username'] ?? '';
        $password = $smtp['password'] ?? '';
        $timeout = $smtp['timeout'] ?? 30;

        // SSL 연결
        $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
        $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, $timeout);

        if (!$socket) {
            throw new \RuntimeException("SMTP connection failed: {$errstr}");
        }

        try {
            $this->smtpRead($socket);

            // EHLO
            $this->smtpCommand($socket, 'EHLO ' . gethostname());

            // STARTTLS (for TLS encryption)
            if ($encryption === 'tls') {
                $this->smtpCommand($socket, 'STARTTLS');
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->smtpCommand($socket, 'EHLO ' . gethostname());
            }

            // 인증
            if ($username && $password) {
                $this->smtpCommand($socket, 'AUTH LOGIN');
                $this->smtpCommand($socket, base64_encode($username));
                $this->smtpCommand($socket, base64_encode($password));
            }

            // 발신자
            $from = $this->stripCrlf($message->getFrom() ?? $this->config['from']['address']);
            $this->smtpCommand($socket, "MAIL FROM:<{$from}>");

            // 수신자
            foreach ($message->getTo() as $recipient) {
                $rcpt = $this->stripCrlf($recipient['email']);
                $this->smtpCommand($socket, "RCPT TO:<{$rcpt}>");
            }
            foreach ($message->getCc() as $recipient) {
                $rcpt = $this->stripCrlf($recipient['email']);
                $this->smtpCommand($socket, "RCPT TO:<{$rcpt}>");
            }
            foreach ($message->getBcc() as $recipient) {
                $rcpt = $this->stripCrlf($recipient['email']);
                $this->smtpCommand($socket, "RCPT TO:<{$rcpt}>");
            }

            // 데이터 전송
            $this->smtpCommand($socket, 'DATA');

            // 메일 내용
            $headers = $this->buildHeaders($message);
            $data = implode("\r\n", $headers) . "\r\n\r\n" . $message->getBody() . "\r\n.";
            $this->smtpCommand($socket, $data);

            // 종료
            $this->smtpCommand($socket, 'QUIT');

            return true;
        } finally {
            fclose($socket);
        }
    }

    /**
     * SMTP 명령 전송
     */
    private function smtpCommand($socket, string $command): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->smtpRead($socket);
    }

    /**
     * SMTP 응답 읽기
     */
    private function smtpRead($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $response;
    }

    /**
     * 헤더 구성
     */
    private function buildHeaders(MailMessage $message): array
    {
        $headers = [];

        // From
        $from = $message->getFrom() ?? $this->config['from']['address'];
        $fromName = $message->getFromName() ?? $this->config['from']['name'] ?? null;
        $headers[] = 'From: ' . $this->formatAddress($from, $fromName);

        // Reply-To
        if ($message->getReplyTo()) {
            $headers[] = 'Reply-To: ' . $this->stripCrlf($message->getReplyTo());
        }

        // To
        $headers[] = 'To: ' . $this->formatAddressList($message->getTo());

        // CC
        if ($message->getCc()) {
            $headers[] = 'Cc: ' . $this->formatAddressList($message->getCc());
        }

        // Subject
        $headers[] = 'Subject: ' . $this->encodeHeader($message->getSubject());

        // MIME
        $headers[] = 'MIME-Version: 1.0';

        if ($message->hasAttachments()) {
            $boundary = md5(uniqid(time()));
            $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
        } else {
            $contentType = $message->isHtml() ? 'text/html' : 'text/plain';
            $headers[] = "Content-Type: {$contentType}; charset=UTF-8";
        }

        // 커스텀 헤더
        foreach ($message->getHeaders() as $name => $value) {
            $headers[] = $this->stripCrlf((string) $name) . ': ' . $this->stripCrlf((string) $value);
        }

        return $headers;
    }

    /**
     * 주소 포맷팅
     */
    private function formatAddress(string $email, ?string $name = null): string
    {
        $email = $this->stripCrlf($email);
        if ($name) {
            return $this->encodeHeader($name) . " <{$email}>";
        }
        return $email;
    }

    /**
     * 헤더/봉투 인젝션 방지: CR·LF 제거
     */
    private function stripCrlf(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    /**
     * 주소 목록 포맷팅
     */
    private function formatAddressList(array $addresses): string
    {
        return implode(', ', array_map(
            fn($addr) => $this->formatAddress($addr['email'], $addr['name'] ?? null),
            $addresses
        ));
    }

    /**
     * 헤더 인코딩 (UTF-8)
     */
    private function encodeHeader(string $value): string
    {
        $value = $this->stripCrlf($value);
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    /**
     * 템플릿 렌더링
     */
    private function renderTemplate(string $template, array $data): ?string
    {
        // 템플릿 경로 검색
        $basePath = defined('MUBLO_VIEWS_PATH') ? MUBLO_VIEWS_PATH : 'views';
        $paths = [
            $basePath . '/Mail/' . $template . '.php',
            $basePath . '/mail/' . $template . '.php',
            $basePath . '/' . $template . '.php',
        ];

        $templatePath = null;
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $templatePath = $path;
                break;
            }
        }

        if (!$templatePath) {
            return null;
        }

        // 템플릿 렌더링
        extract($data, EXTR_SKIP);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }
}
