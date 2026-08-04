<?php
declare(strict_types=1);
namespace Mublo\Entity\Block;

use DateTimeImmutable;

/**
 * Class BlockKitApplication
 *
 * 블록 킷 적용 이력 엔티티 (block_kit_applications)
 *
 * 책임:
 * - 언제 어떤 블록 킷이 어디에 적용됐는지 표현
 * - 되돌리기용 site_config 스냅샷 보관
 *
 * 금지:
 * - DB 직접 접근
 * - 비즈니스 로직 (Service 담당)
 */
class BlockKitApplication
{
    protected int $applicationId;
    protected int $kitId;
    protected int $domainId;

    protected string $applyMode;
    protected string $targetKind;
    protected ?string $targetPosition;
    protected ?string $targetMenuCode;
    protected ?string $targetPageCode;

    protected int $createdRowCount;

    /** 블록 킷이 site_config 를 건드렸을 때만 채워진다. */
    protected ?string $siteConfigSnapshot;

    protected ?int $appliedBy;
    protected ?DateTimeImmutable $appliedAt;

    public static function fromArray(array $data): self
    {
        $application = new self();

        $application->applicationId = (int) ($data['application_id'] ?? 0);
        $application->kitId = (int) ($data['kit_id'] ?? 0);
        $application->domainId = (int) ($data['domain_id'] ?? 0);

        $application->applyMode = (string) ($data['apply_mode'] ?? 'append');
        $application->targetKind = (string) ($data['target_kind'] ?? BlockKit::TARGET_POSITION);
        $application->targetPosition = self::nullableString($data['target_position'] ?? null);
        $application->targetMenuCode = self::nullableString($data['target_menu_code'] ?? null);
        $application->targetPageCode = self::nullableString($data['target_page_code'] ?? null);

        $application->createdRowCount = (int) ($data['created_row_count'] ?? 0);
        $application->siteConfigSnapshot = self::nullableString($data['site_config_snapshot'] ?? null);

        $appliedBy = $data['applied_by'] ?? null;
        $application->appliedBy = $appliedBy === null ? null : (int) $appliedBy;

        $application->appliedAt = self::parseDate($data['applied_at'] ?? null);

        return $application;
    }

    public function getApplicationId(): int
    {
        return $this->applicationId;
    }

    public function getKitId(): int
    {
        return $this->kitId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getApplyMode(): string
    {
        return $this->applyMode;
    }

    public function getTargetKind(): string
    {
        return $this->targetKind;
    }

    public function getTargetPosition(): ?string
    {
        return $this->targetPosition;
    }

    public function getTargetMenuCode(): ?string
    {
        return $this->targetMenuCode;
    }

    public function getTargetPageCode(): ?string
    {
        return $this->targetPageCode;
    }

    public function getCreatedRowCount(): int
    {
        return $this->createdRowCount;
    }

    public function getAppliedBy(): ?int
    {
        return $this->appliedBy;
    }

    public function getAppliedAt(): ?DateTimeImmutable
    {
        return $this->appliedAt;
    }

    /**
     * 되돌릴 수 있는 적용인가.
     *
     * 스냅샷이 없으면 블록 킷이 site_config 를 건드리지 않은 것이다. 되돌릴 설정이 없다.
     * 블록 행은 되돌리지 않는다 — 적용 후 운영자가 편집했을 수 있고, 그것까지
     * 지워 버리면 "되돌리기" 가 아니라 파괴다.
     */
    public function canRollback(): bool
    {
        return $this->decodeSnapshot() !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodeSnapshot(): ?array
    {
        if ($this->siteConfigSnapshot === null) {
            return null;
        }

        $decoded = json_decode($this->siteConfigSnapshot, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function toArray(): array
    {
        return [
            'application_id' => $this->applicationId,
            'kit_id' => $this->kitId,
            'domain_id' => $this->domainId,
            'apply_mode' => $this->applyMode,
            'target_kind' => $this->targetKind,
            'target_position' => $this->targetPosition,
            'target_menu_code' => $this->targetMenuCode,
            'target_page_code' => $this->targetPageCode,
            'created_row_count' => $this->createdRowCount,
            'site_config_snapshot' => $this->siteConfigSnapshot,
            'applied_by' => $this->appliedBy,
            'applied_at' => $this->appliedAt?->format('Y-m-d H:i:s'),
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
