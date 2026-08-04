<?php
declare(strict_types=1);
namespace Mublo\Entity\Block;

use DateTimeImmutable;

/**
 * Class BlockKit
 *
 * 보관된 블록 킷 엔티티
 *
 * 책임:
 * - block_kits 테이블의 데이터를 객체로 표현
 *
 * 금지:
 * - DB 직접 접근
 * - 비즈니스 로직 (Service 담당)
 *
 * kitJson 은 **목록에서 로드하지 않는다.** 블록 킷 하나가 2 MiB 까지 가므로, 목록 화면이
 * 스무 개를 한 번에 읽으면 40 MiB 다. 리포지토리가 목록용 조회에서 그 컬럼을 빼고
 * 여기서는 null 로 둔다. 본문이 필요한 곳은 findWithJson() 을 쓴다.
 */
class BlockKit
{
    public const TARGET_POSITION = 'position';
    public const TARGET_PAGE = 'page';
    public const TARGET_SCREEN = 'screen';

    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_EXPORT = 'export';
    /** 저장소에 번들된 시작 킷 (설치 시더가 등록) */
    public const SOURCE_BUNDLED = 'bundled';

    protected int $kitId;
    protected int $domainId;

    // 목록/검색용 사본 (kit_json 에서 뽑아 둔 값)
    protected string $kitName;
    protected string $kitDescription;
    protected string $kitVersion;
    protected string $kitAuthor;
    protected string $kitAuthorUrl;

    // 대상
    protected string $targetKind;
    protected ?string $targetPosition;
    protected ?string $targetMenuCode;
    protected ?string $targetPageCode;

    // 배지/경고용
    protected string $exportMode;
    protected bool $containsScript;
    protected int $rowCount;
    protected int $columnCount;

    /** 목록 조회에서는 null 이다. 로드되지 않았다는 뜻이지 "빈 블록 킷" 이 아니다. */
    protected ?string $kitJson;

    protected ?string $screenshotPath;
    protected string $sourceType;

    protected bool $isDeleted;
    protected ?DateTimeImmutable $createdAt;
    protected ?DateTimeImmutable $updatedAt;

    public static function fromArray(array $data): self
    {
        $kit = new self();

        $kit->kitId = (int) ($data['kit_id'] ?? 0);
        $kit->domainId = (int) ($data['domain_id'] ?? 0);

        $kit->kitName = (string) ($data['kit_name'] ?? '');
        $kit->kitDescription = (string) ($data['kit_description'] ?? '');
        $kit->kitVersion = (string) ($data['kit_version'] ?? '1.0.0');
        $kit->kitAuthor = (string) ($data['kit_author'] ?? '');
        $kit->kitAuthorUrl = (string) ($data['kit_author_url'] ?? '');

        $kit->targetKind = (string) ($data['target_kind'] ?? self::TARGET_POSITION);
        $kit->targetPosition = self::nullableString($data['target_position'] ?? null);
        $kit->targetMenuCode = self::nullableString($data['target_menu_code'] ?? null);
        $kit->targetPageCode = self::nullableString($data['target_page_code'] ?? null);

        $kit->exportMode = (string) ($data['export_mode'] ?? 'distribution');
        $kit->containsScript = (bool) ($data['contains_script'] ?? false);
        $kit->rowCount = (int) ($data['row_count'] ?? 0);
        $kit->columnCount = (int) ($data['column_count'] ?? 0);

        // 컬럼이 아예 없으면(목록 조회) null. 있으면 문자열.
        $kit->kitJson = array_key_exists('kit_json', $data) ? self::nullableString($data['kit_json']) : null;

        $kit->screenshotPath = self::nullableString($data['screenshot_path'] ?? null);
        $kit->sourceType = (string) ($data['source_type'] ?? self::SOURCE_UPLOAD);

        $kit->isDeleted = (bool) ($data['is_deleted'] ?? false);
        $kit->createdAt = self::parseDate($data['created_at'] ?? null);
        $kit->updatedAt = self::parseDate($data['updated_at'] ?? null);

        return $kit;
    }

    public function toArray(): array
    {
        return [
            'kit_id' => $this->kitId,
            'domain_id' => $this->domainId,
            'kit_name' => $this->kitName,
            'kit_description' => $this->kitDescription,
            'kit_version' => $this->kitVersion,
            'kit_author' => $this->kitAuthor,
            'kit_author_url' => $this->kitAuthorUrl,
            'target_kind' => $this->targetKind,
            'target_position' => $this->targetPosition,
            'target_menu_code' => $this->targetMenuCode,
            'target_page_code' => $this->targetPageCode,
            'export_mode' => $this->exportMode,
            'contains_script' => $this->containsScript,
            'row_count' => $this->rowCount,
            'column_count' => $this->columnCount,
            'kit_json' => $this->kitJson,
            'screenshot_path' => $this->screenshotPath,
            'source_type' => $this->sourceType,
            'is_deleted' => $this->isDeleted,
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }

    public function getKitId(): int
    {
        return $this->kitId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getKitName(): string
    {
        return $this->kitName;
    }

    public function getKitDescription(): string
    {
        return $this->kitDescription;
    }

    public function getKitVersion(): string
    {
        return $this->kitVersion;
    }

    public function getKitAuthor(): string
    {
        return $this->kitAuthor;
    }

    public function getKitAuthorUrl(): string
    {
        return $this->kitAuthorUrl;
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

    public function getExportMode(): string
    {
        return $this->exportMode;
    }

    public function containsScript(): bool
    {
        return $this->containsScript;
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }

    public function getColumnCount(): int
    {
        return $this->columnCount;
    }

    public function getScreenshotPath(): ?string
    {
        return $this->screenshotPath;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function isDeleted(): bool
    {
        return $this->isDeleted;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * 블록 킷 JSON 원본. 목록 조회로 만든 엔티티에서는 null 이다.
     *
     * 호출자가 "본문이 없는 블록 킷" 과 "본문을 안 읽은 블록 킷" 을 혼동하지 않도록,
     * 본문이 필요하면 hasJson() 으로 먼저 확인하거나 findWithJson() 을 쓴다.
     */
    public function getKitJson(): ?string
    {
        return $this->kitJson;
    }

    public function hasJson(): bool
    {
        return $this->kitJson !== null;
    }

    /**
     * 블록 킷 JSON 을 배열로 디코드한다. 로드되지 않았거나 깨졌으면 null.
     *
     * 보관 시점에 검증된 JSON 이지만, DB 를 손으로 고칠 수도 있으므로 방어한다.
     */
    public function decodeJson(): ?array
    {
        if ($this->kitJson === null) {
            return null;
        }

        $decoded = json_decode($this->kitJson, true);

        return is_array($decoded) ? $decoded : null;
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
        if (!is_string($value) || $value === '') {
            return null;
        }

        // MySQL zero date. DateTimeImmutable 은 이걸 예외 없이 받아 기원전 -0001-11-30 을
        // 만든다. 예외에만 기대면 화면에 "0001년" 이 찍힌다.
        if (str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
