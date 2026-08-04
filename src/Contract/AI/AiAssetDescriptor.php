<?php
declare(strict_types=1);
namespace Mublo\Contract\AI;

/** 저장 경로를 노출하지 않는 AI 자산 메타데이터. */
final readonly class AiAssetDescriptor
{
    public function __construct(
        private int $id,
        private ?int $parentId,
        private string $kind,
        private string $title,
        private string $originalName,
        private string $extension,
        private string $mimeType,
        private int $size,
        private array $metadata,
        private ?string $createdAt,
    ) {
    }

    public function getId(): int { return $this->id; }
    public function getParentId(): ?int { return $this->parentId; }
    public function getKind(): string { return $this->kind; }
    public function getTitle(): string { return $this->title; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getExtension(): string { return $this->extension; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSize(): int { return $this->size; }
    public function getMetadata(): array { return $this->metadata; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
}
