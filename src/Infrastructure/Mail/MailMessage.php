<?php
namespace Mublo\Infrastructure\Mail;

/**
 * MailMessage
 *
 * 이메일 메시지 Value Object
 * - Fluent Interface로 메일 구성
 */
class MailMessage
{
    private array $to = [];
    private array $cc = [];
    private array $bcc = [];
    private ?string $from = null;
    private ?string $fromName = null;
    private ?string $replyTo = null;
    private string $subject = '';
    private string $body = '';
    private bool $isHtml = true;
    private array $attachments = [];
    private array $headers = [];

    /**
     * 수신자 추가
     */
    public function to(string $email, ?string $name = null): self
    {
        $this->to[] = ['email' => $email, 'name' => $name];
        return $this;
    }

    /**
     * CC 추가
     */
    public function cc(string $email, ?string $name = null): self
    {
        $this->cc[] = ['email' => $email, 'name' => $name];
        return $this;
    }

    /**
     * BCC 추가
     */
    public function bcc(string $email, ?string $name = null): self
    {
        $this->bcc[] = ['email' => $email, 'name' => $name];
        return $this;
    }

    /**
     * 발신자 설정
     */
    public function from(string $email, ?string $name = null): self
    {
        $this->from = $email;
        $this->fromName = $name;
        return $this;
    }

    /**
     * 회신 주소 설정
     */
    public function replyTo(string $email): self
    {
        $this->replyTo = $email;
        return $this;
    }

    /**
     * 제목 설정
     */
    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * HTML 본문 설정
     */
    public function html(string $body): self
    {
        $this->body = $body;
        $this->isHtml = true;
        return $this;
    }

    /**
     * 텍스트 본문 설정
     */
    public function text(string $body): self
    {
        $this->body = $body;
        $this->isHtml = false;
        return $this;
    }

    /**
     * 첨부파일 추가
     */
    public function attach(string $path, ?string $name = null, ?string $mimeType = null): self
    {
        $this->attachments[] = [
            'path' => $path,
            'name' => $name ?? basename($path),
            'mime' => $mimeType,
        ];
        return $this;
    }

    /**
     * 문자열 데이터를 첨부파일로 추가
     */
    public function attachData(string $data, string $name, ?string $mimeType = null): self
    {
        $this->attachments[] = [
            'data' => $data,
            'name' => $name,
            'mime' => $mimeType ?? 'application/octet-stream',
        ];
        return $this;
    }

    /**
     * 커스텀 헤더 추가
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    // === Getters ===

    public function getTo(): array
    {
        return $this->to;
    }

    public function getCc(): array
    {
        return $this->cc;
    }

    public function getBcc(): array
    {
        return $this->bcc;
    }

    public function getFrom(): ?string
    {
        return $this->from;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isHtml(): bool
    {
        return $this->isHtml;
    }

    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasAttachments(): bool
    {
        return !empty($this->attachments);
    }
}
