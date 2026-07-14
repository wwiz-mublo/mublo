<?php
namespace Mublo\Infrastructure\Storage;

/**
 * UploadResult
 *
 * 파일 업로드 결과를 담는 Value Object
 */
class UploadResult
{
    private bool $success;
    private string $message;
    private ?string $storedName;
    private ?string $relativePath;
    private ?string $fullPath;
    private ?string $originalName;
    private ?string $extension;
    private ?string $mimeType;
    private ?int $size;
    private ?int $imageWidth;
    private ?int $imageHeight;

    private function __construct(
        bool $success,
        string $message = '',
        ?string $storedName = null,
        ?string $relativePath = null,
        ?string $fullPath = null,
        ?string $originalName = null,
        ?string $extension = null,
        ?string $mimeType = null,
        ?int $size = null,
        ?int $imageWidth = null,
        ?int $imageHeight = null
    ) {
        $this->success = $success;
        $this->message = $message;
        $this->storedName = $storedName;
        $this->relativePath = $relativePath;
        $this->fullPath = $fullPath;
        $this->originalName = $originalName;
        $this->extension = $extension;
        $this->mimeType = $mimeType;
        $this->size = $size;
        $this->imageWidth = $imageWidth;
        $this->imageHeight = $imageHeight;
    }

    /**
     * 성공 결과 생성
     */
    public static function success(array $data): self
    {
        return new self(
            success: true,
            message: $data['message'] ?? '파일이 업로드되었습니다.',
            storedName: $data['stored_name'] ?? null,
            relativePath: $data['relative_path'] ?? null,
            fullPath: $data['full_path'] ?? null,
            originalName: $data['original_name'] ?? null,
            extension: $data['extension'] ?? null,
            mimeType: $data['mime_type'] ?? null,
            size: $data['size'] ?? null,
            imageWidth: $data['image_width'] ?? null,
            imageHeight: $data['image_height'] ?? null
        );
    }

    /**
     * 실패 결과 생성
     */
    public static function failure(string $message): self
    {
        return new self(success: false, message: $message);
    }

    // === Getters ===

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return !$this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getStoredName(): ?string
    {
        return $this->storedName;
    }

    public function getRelativePath(): ?string
    {
        return $this->relativePath;
    }

    public function getFullPath(): ?string
    {
        return $this->fullPath;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function getExtension(): ?string
    {
        return $this->extension;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getImageWidth(): ?int
    {
        return $this->imageWidth;
    }

    public function getImageHeight(): ?int
    {
        return $this->imageHeight;
    }

    public function isImage(): bool
    {
        return $this->imageWidth !== null && $this->imageHeight !== null;
    }

    /**
     * DB 저장용 배열 반환
     */
    public function toArray(): array
    {
        return [
            'stored_name' => $this->storedName,
            'relative_path' => $this->relativePath,
            'original_name' => $this->originalName,
            'extension' => $this->extension,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'image_width' => $this->imageWidth,
            'image_height' => $this->imageHeight,
            'is_image' => $this->isImage(),
        ];
    }
}
