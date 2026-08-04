<?php
declare(strict_types=1);

namespace Mublo\Entity\Block;

use DateTimeImmutable;
use Mublo\Enum\Block\BlockContentKind;
use Mublo\Enum\Block\BlockContentType;

/**
 * BlockColumnContent — 스택 칸(content_mode=stack)의 하위 콘텐츠 한 개.
 *
 * BlockColumn 의 콘텐츠 필드(제목·타입·종류·스킨·설정·항목)와 동일한 의미의
 * 필드를 독립 레코드로 가진다. DB 접근이나 렌더링을 하지 않는 데이터 표현
 * 전용이다 — 렌더링은 원본 칸의 레이아웃과 이 콘텐츠를 합친 렌더 전용
 * BlockColumn view 를 만들어 기존 Renderer 계약으로 수행한다(계획 7.1).
 */
class BlockColumnContent
{
    protected int $contentId;
    protected int $columnId;
    protected int $domainId;
    protected int $sortOrder;

    protected ?array $titleConfig;
    protected ?BlockContentType $contentType;
    protected ?string $contentTypeRaw;
    protected BlockContentKind $contentKind;
    protected ?string $contentSkin;
    protected ?array $contentConfig;
    protected ?array $contentItems;

    protected bool $isActive;
    protected DateTimeImmutable $createdAt;
    protected DateTimeImmutable $updatedAt;

    /**
     * DB 로우 데이터로부터 생성
     */
    public static function fromArray(array $data): self
    {
        $content = new self();

        $content->contentId = (int) ($data['content_id'] ?? 0);
        $content->columnId = (int) ($data['column_id'] ?? 0);
        $content->domainId = (int) ($data['domain_id'] ?? 0);
        $content->sortOrder = (int) ($data['sort_order'] ?? 0);

        $content->titleConfig = self::parseJsonArray($data['title_config'] ?? null);
        $content->contentTypeRaw = $data['content_type'] ?? null;
        $content->contentType = isset($data['content_type']) ? BlockContentType::tryFrom($data['content_type']) : null;
        $content->contentKind = BlockContentKind::tryFrom($data['content_kind'] ?? 'CORE') ?? BlockContentKind::CORE;
        $content->contentSkin = $data['content_skin'] ?? null;
        $content->contentConfig = self::parseJsonArray($data['content_config'] ?? null);
        $content->contentItems = self::parseJsonArray($data['content_items'] ?? null);

        $content->isActive = (bool) ($data['is_active'] ?? true);
        $content->createdAt = self::parseDateTime($data['created_at'] ?? null);
        $content->updatedAt = self::parseDateTime($data['updated_at'] ?? null);

        return $content;
    }

    public function toArray(): array
    {
        return [
            'content_id' => $this->contentId,
            'column_id' => $this->columnId,
            'domain_id' => $this->domainId,
            'sort_order' => $this->sortOrder,
            'title_config' => $this->titleConfig,
            'content_type' => $this->contentTypeRaw,
            'content_kind' => $this->contentKind->value,
            'content_skin' => $this->contentSkin,
            'content_config' => $this->contentConfig,
            'content_items' => $this->contentItems,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    public function getContentId(): int
    {
        return $this->contentId;
    }

    public function getColumnId(): int
    {
        return $this->columnId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function getTitleConfig(): ?array
    {
        return $this->titleConfig;
    }

    public function getContentType(): ?BlockContentType
    {
        return $this->contentType;
    }

    public function getContentTypeString(): ?string
    {
        return $this->contentTypeRaw;
    }

    public function getContentKind(): BlockContentKind
    {
        return $this->contentKind;
    }

    public function getContentSkin(): ?string
    {
        return $this->contentSkin;
    }

    public function getContentConfig(): ?array
    {
        return $this->contentConfig;
    }

    public function getContentItems(): ?array
    {
        return $this->contentItems;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function parseJsonArray($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private static function parseDateTime(?string $datetime): DateTimeImmutable
    {
        if (empty($datetime)) {
            return new DateTimeImmutable();
        }

        try {
            return new DateTimeImmutable($datetime);
        } catch (\Exception) {
            return new DateTimeImmutable();
        }
    }
}
