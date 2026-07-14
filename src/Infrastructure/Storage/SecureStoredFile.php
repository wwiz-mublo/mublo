<?php

namespace Mublo\Infrastructure\Storage;

/**
 * SecureStoredFile
 *
 * 보안 파일 저장 결과 DTO
 * moveFinal() 반환형으로 사용, DB 메타 JSON 변환 지원
 */
class SecureStoredFile
{
    public function __construct(
        public readonly string $storedName,
        public readonly string $relativePath,
        public readonly string $originalName,
        public readonly int    $size,
        public readonly string $mimeType,
        public readonly string $extension,
    ) {}

    /**
     * DB 저장용 JSON 메타 생성
     */
    public function toMetaJson(): string
    {
        return json_encode([
            'disk'          => 'secure',
            'relative_path' => $this->relativePath,
            'stored_name'   => $this->storedName,
            'original_name' => $this->originalName,
            'size'          => $this->size,
            'mime_type'     => $this->mimeType,
            'extension'     => $this->extension,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * DB 메타 JSON → SecureStoredFile 복원
     */
    public static function fromMetaJson(?string $json): ?self
    {
        if (empty($json)) {
            return null;
        }

        $data = json_decode($json, true);
        if (!$data || empty($data['stored_name']) || empty($data['relative_path'])) {
            return null;
        }

        return new self(
            storedName:   $data['stored_name'],
            relativePath: $data['relative_path'],
            originalName: $data['original_name'] ?? $data['stored_name'],
            size:         $data['size'] ?? 0,
            mimeType:     $data['mime_type'] ?? '',
            extension:    $data['extension'] ?? '',
        );
    }
}
