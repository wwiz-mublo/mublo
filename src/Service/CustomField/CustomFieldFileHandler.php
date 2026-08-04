<?php
declare(strict_types=1);

namespace Mublo\Service\CustomField;

use Mublo\Infrastructure\Storage\SecureFileService;
use Mublo\Infrastructure\Storage\SecureStoredFile;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Infrastructure\Storage\UploadResult;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Core\Result\Result;

/**
 * CustomFieldFileHandler
 *
 * 커스텀 필드 파일 업로드/이동/삭제 처리
 * 회원 필드, 주문 필드 등 모든 커스텀 필드 시스템에서 공용 사용
 *
 * 보안 파일은 storage/files/ (웹 접근 불가)에 저장되며,
 * HMAC 토큰 기반 다운로드 URL로 접근한다.
 *
 * 파일 흐름:
 *   1. 사용자가 파일 선택 → AJAX로 uploadTemp() → 임시 경로 반환
 *   2. 폼 제출 시 processFileValue() → 임시→최종 이동 + JSON 메타 반환
 *   3. 수정/삭제 시 deleteFileByMeta() → 디스크 파일 삭제
 */
class CustomFieldFileHandler
{
    private SecureFileService $secureFile;
    private ?FileUploader $publicUploader;

    public function __construct(SecureFileService $secureFile, ?FileUploader $publicUploader = null)
    {
        $this->secureFile = $secureFile;
        // public 저장(아바타 등)은 FileUploader 경유 (호출 측에서 DI 주입).
        $this->publicUploader = $publicUploader;
    }

    /**
     * 파일을 임시 경로에 업로드
     *
     * @param UploadedFile $file 업로드된 파일
     * @param int $domainId 도메인 ID
     * @param array|string $fieldConfig 필드 설정 (JSON 문자열 또는 배열)
     *   - max_size: 최대 크기 (MB)
     *   - allowed_ext: 허용 확장자 (콤마 구분)
     */
    public function uploadTemp(UploadedFile $file, int $domainId, array|string $fieldConfig): UploadResult
    {
        $config = is_string($fieldConfig)
            ? (json_decode($fieldConfig, true) ?: [])
            : $fieldConfig;

        return $this->secureFile->uploadTemp($file, $domainId, $config);
    }

    /**
     * 임시 업로드 결과를 프론트 응답용 배열로 변환
     */
    public function buildTempResponse(UploadResult $result): array
    {
        return [
            'temp_path' => $result->getRelativePath() . '/' . $result->getStoredName(),
            'filename' => $result->getOriginalName(),
            'size' => $result->getSize(),
            'extension' => $result->getExtension(),
            'mime_type' => $result->getMimeType(),
        ];
    }

    /**
     * 파일 필드 값 처리 (저장 시)
     *
     * @param mixed $value 필드 값 (__delete__, 빈값, JSON 메타)
     * @param int $domainId 도메인 ID
     * @param string $category 저장 카테고리 (예: 'member-fields', 'order-fields')
     * @param string $entityId 엔티티 식별자 (예: memberId, orderNo)
     * @param bool $public true이면 secure가 아닌 공개 저장소(/storage)에 저장 (아바타 등 공개 이미지)
     * @return Result success data: ['action' => 'delete'|'skip'|'save', 'meta_json' => string|null]
     */
    public function processFileValue(mixed $value, int $domainId, string $category, string $entityId, bool $public = false): Result
    {
        // 삭제 요청
        if ($value === '__delete__') {
            return Result::success('삭제 요청', ['action' => 'delete', 'meta_json' => null]);
        }

        // 빈값 → 기존 값 유지
        if ($value === null || $value === '') {
            return Result::success('변경 없음', ['action' => 'skip', 'meta_json' => null]);
        }

        // JSON 파싱
        $fileMeta = is_string($value) ? json_decode($value, true) : (is_array($value) ? $value : null);

        if (!$fileMeta || empty($fileMeta['temp_path'])) {
            return Result::success('변경 없음', ['action' => 'skip', 'meta_json' => null]);
        }

        // 공개 저장(아바타 등): secure temp → public/storage 로 이동, 만료 없는 직링크 사용
        if ($public) {
            return $this->processPublicValue($fileMeta, $domainId);
        }

        try {
            // SecureFileService로 최종 이동
            $stored = $this->secureFile->moveFinal(
                $fileMeta['temp_path'],
                $domainId,
                $category,
                $entityId,
            );
        } catch (\Throwable $e) {
            return Result::failure('파일 이동에 실패했습니다: ' . $e->getMessage());
        }

        // 원본 파일명 보존하여 메타 생성
        $metaJson = json_encode([
            'disk'          => 'secure',
            'relative_path' => $stored->relativePath,
            'stored_name'   => $stored->storedName,
            'original_name' => $fileMeta['filename'] ?? $stored->storedName,
            'size'          => $fileMeta['size'] ?? $stored->size,
            'mime_type'     => $fileMeta['mime_type'] ?? $stored->mimeType,
            'extension'     => $fileMeta['extension'] ?? $stored->extension,
        ], JSON_UNESCAPED_UNICODE);

        return Result::success('파일이 저장되었습니다.', ['action' => 'save', 'meta_json' => $metaJson]);
    }

    /**
     * 공개 이미지(아바타) 저장: secure temp 파일을 public 저장소로 편입
     */
    private function processPublicValue(array $fileMeta, int $domainId): Result
    {
        if (!$this->publicUploader) {
            return Result::failure('공개 저장소가 구성되지 않았습니다.');
        }

        try {
            // 사용자 제출 temp_path 를 도메인 소유로 검증한 뒤 해석 (moveFinal 과 동일한 IDOR 방어).
            $sourceFull = $this->secureFile->resolveOwnedTempPath($fileMeta['temp_path'], $domainId);
            $uploaded = $this->publicUploader->adoptFile($sourceFull, $domainId, [
                'subdirectory'  => 'avatar',
                'original_name' => $fileMeta['filename'] ?? null,
                'extension'     => $fileMeta['extension'] ?? null,
                'mime_type'     => $fileMeta['mime_type'] ?? '',
            ]);
        } catch (\Throwable $e) {
            return Result::failure('파일 이동에 실패했습니다: ' . $e->getMessage());
        }

        if ($uploaded->isFailure()) {
            return Result::failure($uploaded->getMessage());
        }

        // 공개 파일: disk=public, relative_path/stored_name 분리 (parseFileMeta가 /storage 직링크 생성)
        $metaJson = json_encode([
            'disk'          => 'public',
            'relative_path' => $uploaded->getRelativePath(),
            'stored_name'   => $uploaded->getStoredName(),
            'original_name' => $fileMeta['filename'] ?? $uploaded->getStoredName(),
            'size'          => $fileMeta['size'] ?? $uploaded->getSize(),
            'mime_type'     => $fileMeta['mime_type'] ?? $uploaded->getMimeType(),
            'extension'     => $fileMeta['extension'] ?? $uploaded->getExtension(),
        ], JSON_UNESCAPED_UNICODE);

        return Result::success('파일이 저장되었습니다.', ['action' => 'save', 'meta_json' => $metaJson]);
    }

    /**
     * 저장된 파일 메타 JSON으로 디스크 파일 삭제
     *
     * disk=secure → SecureFileService, disk=public → FileUploader 로 분기 삭제.
     */
    public function deleteFileByMeta(?string $fieldValue): Result
    {
        if (empty($fieldValue)) {
            return Result::success('삭제할 파일이 없습니다.');
        }

        try {
            $meta = json_decode($fieldValue, true);
            $disk = is_array($meta) ? ($meta['disk'] ?? 'public') : 'public';

            if ($disk === 'secure') {
                $this->secureFile->deleteByMeta($fieldValue);
            } elseif ($this->publicUploader && is_array($meta) && !empty($meta['relative_path']) && !empty($meta['stored_name'])) {
                $this->publicUploader->delete($meta['relative_path'], $meta['stored_name']);
            }

            return Result::success('파일이 삭제되었습니다.');
        } catch (\Throwable $e) {
            return Result::failure('파일 삭제에 실패했습니다: ' . $e->getMessage());
        }
    }

    /**
     * DB에 저장된 파일 메타 JSON을 기본 파싱 (URL 미포함)
     *
     * 파일명/크기 등 기본 정보만 필요할 때 사용.
     * 레거시(public) 파일은 url 포함, secure 파일은 url = null.
     *
     * @param string|null $fieldValue DB의 field_value (JSON 문자열)
     * @return array|null 파싱된 메타 정보, 실패 시 null
     */
    public static function parseFileMeta(?string $fieldValue): ?array
    {
        if (empty($fieldValue)) {
            return null;
        }

        $data = json_decode($fieldValue, true);
        if (!$data || empty($data['stored_name'])) {
            return null;
        }

        $isSecure = ($data['disk'] ?? 'public') === 'secure';

        return [
            'filename'  => $data['original_name'] ?? $data['stored_name'],
            'size'      => $data['size'] ?? 0,
            'mime_type' => $data['mime_type'] ?? '',
            'extension' => $data['extension'] ?? '',
            'disk'      => $data['disk'] ?? 'public',
            'url'       => $isSecure ? null : '/storage/' . ($data['relative_path'] ?? '') . '/' . ($data['stored_name'] ?? ''),
        ];
    }

    /**
     * DB에 저장된 파일 메타 JSON을 파싱하고 HMAC 다운로드 URL 추가
     *
     * secure 파일의 다운로드 링크가 필요할 때 사용.
     * SecureFileService 인스턴스가 필요하므로 인스턴스 메서드.
     *
     * @param string|null $fieldValue DB의 field_value (JSON 문자열)
     * @return array|null 파싱된 메타 + url 키에 다운로드 URL, 실패 시 null
     */
    public function parseFileMetaWithUrl(?string $fieldValue): ?array
    {
        return $this->secureFile->parseMetaWithUrl($fieldValue);
    }
}
