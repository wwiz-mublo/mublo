<?php
namespace Mublo\Packages\Board\Entity;

/**
 * BoardAttachment Entity
 *
 * 첨부파일 엔티티
 *
 * 책임:
 * - board_attachments 테이블 데이터 표현
 * - 불변 객체 패턴
 */
final class BoardAttachment
{
    private int $attachmentId;
    private int $domainId;
    private int $boardId;
    private int $articleId;

    // 파일 정보
    private string $originalName;
    private string $storedName;
    private string $filePath;
    private int $fileSize;
    private string $fileExtension;
    private string $mimeType;

    // 이미지 정보
    private bool $isImage;
    private ?int $imageWidth;
    private ?int $imageHeight;
    private ?string $thumbnailPath;

    // 통계
    private int $downloadCount;

    // 시간
    private \DateTimeImmutable $createdAt;

    private function __construct() {}

    /**
     * 배열에서 Entity 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->attachmentId = (int) ($data['attachment_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->boardId = (int) ($data['board_id'] ?? 0);
        $entity->articleId = (int) ($data['article_id'] ?? 0);

        // 파일 정보
        $entity->originalName = $data['original_name'] ?? '';
        $entity->storedName = $data['stored_name'] ?? '';
        $entity->filePath = $data['file_path'] ?? '';
        $entity->fileSize = (int) ($data['file_size'] ?? 0);
        $entity->fileExtension = $data['file_extension'] ?? '';
        $entity->mimeType = $data['mime_type'] ?? '';

        // 이미지 정보
        $entity->isImage = (bool) ($data['is_image'] ?? false);
        $entity->imageWidth = isset($data['image_width']) ? (int) $data['image_width'] : null;
        $entity->imageHeight = isset($data['image_height']) ? (int) $data['image_height'] : null;
        $entity->thumbnailPath = $data['thumbnail_path'] ?? null;

        // 통계
        $entity->downloadCount = (int) ($data['download_count'] ?? 0);

        // 시간
        $entity->createdAt = self::parseDateTime($data['created_at'] ?? 'now');

        return $entity;
    }

    /**
     * Entity를 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'attachment_id' => $this->attachmentId,
            'domain_id' => $this->domainId,
            'board_id' => $this->boardId,
            'article_id' => $this->articleId,
            'original_name' => $this->originalName,
            'stored_name' => $this->storedName,
            'file_path' => $this->filePath,
            'file_size' => $this->fileSize,
            'file_extension' => $this->fileExtension,
            'mime_type' => $this->mimeType,
            'is_image' => $this->isImage,
            'image_width' => $this->imageWidth,
            'image_height' => $this->imageHeight,
            'thumbnail_path' => $this->thumbnailPath,
            'download_count' => $this->downloadCount,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    // === Getters ===

    public function getAttachmentId(): int
    {
        return $this->attachmentId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getBoardId(): int
    {
        return $this->boardId;
    }

    public function getArticleId(): int
    {
        return $this->articleId;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getStoredName(): string
    {
        return $this->storedName;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getFileExtension(): string
    {
        return $this->fileExtension;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function isImage(): bool
    {
        return $this->isImage;
    }

    public function getImageWidth(): ?int
    {
        return $this->imageWidth;
    }

    public function getImageHeight(): ?int
    {
        return $this->imageHeight;
    }

    public function getThumbnailPath(): ?string
    {
        return $this->thumbnailPath;
    }

    public function getDownloadCount(): int
    {
        return $this->downloadCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // === 헬퍼 메서드 ===

    /**
     * 파일 크기 포맷팅 (KB, MB 등)
     */
    public function getFormattedFileSize(): string
    {
        $bytes = $this->fileSize;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    /**
     * 이미지 크기 문자열
     */
    public function getImageDimensions(): ?string
    {
        if (!$this->isImage || $this->imageWidth === null || $this->imageHeight === null) {
            return null;
        }
        return $this->imageWidth . 'x' . $this->imageHeight;
    }

    /**
     * 썸네일 존재 여부
     */
    public function hasThumbnail(): bool
    {
        return $this->thumbnailPath !== null;
    }

    /**
     * 전체 파일 경로 반환
     */
    public function getFullPath(): string
    {
        return $this->filePath . '/' . $this->storedName;
    }

    /**
     * 전체 썸네일 경로 반환
     */
    public function getFullThumbnailPath(): ?string
    {
        if ($this->thumbnailPath === null) {
            return null;
        }
        return $this->thumbnailPath;
    }

    /**
     * DateTime 파싱 헬퍼
     */
    private static function parseDateTime(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime);
    }
}
