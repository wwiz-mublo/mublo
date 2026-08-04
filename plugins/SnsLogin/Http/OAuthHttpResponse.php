<?php
declare(strict_types=1);
namespace Mublo\Plugin\SnsLogin\Http;

final class OAuthHttpResponse
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $body,
    ) {}

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
