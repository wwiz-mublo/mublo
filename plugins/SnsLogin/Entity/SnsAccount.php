<?php
namespace Mublo\Plugin\SnsLogin\Entity;

class SnsAccount
{
    public function __construct(
        private int     $id,
        private int     $domainId,
        private int     $memberId,
        private string  $provider,
        private string  $providerUid,
        private ?string $providerEmail,
        private string  $linkedAt,
        private ?string $accessToken = null,
        private ?string $refreshToken = null,
        private ?string $tokenExpiresAt = null,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id:            (int) $row['id'],
            domainId:      (int) $row['domain_id'],
            memberId:      (int) $row['member_id'],
            provider:      $row['provider'],
            providerUid:   $row['provider_uid'],
            providerEmail: $row['provider_email'] ?? null,
            linkedAt:      $row['linked_at'],
            accessToken:   $row['access_token'] ?? null,
            refreshToken:  $row['refresh_token'] ?? null,
            tokenExpiresAt: $row['token_expires_at'] ?? null,
        );
    }

    public function getId(): int          { return $this->id; }
    public function getDomainId(): int    { return $this->domainId; }
    public function getMemberId(): int    { return $this->memberId; }
    public function getProvider(): string { return $this->provider; }
    public function getProviderUid(): string { return $this->providerUid; }
    public function getAccessToken(): ?string { return $this->accessToken; }
    public function getRefreshToken(): ?string { return $this->refreshToken; }
    public function getTokenExpiresAt(): ?string { return $this->tokenExpiresAt; }
}
