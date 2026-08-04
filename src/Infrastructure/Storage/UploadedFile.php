<?php
declare(strict_types=1);
namespace Mublo\Infrastructure\Storage;

/**
 * UploadedFile
 *
 * $_FILES 배열을 래핑하는 Value Object
 * - 파일 정보 접근
 * - 유효성 검사 메서드
 */
class UploadedFile
{
    private string $name;
    private string $type;
    private string $tmpName;
    private int $error;
    private int $size;

    public function __construct(array $file)
    {
        $this->name = $file['name'] ?? '';
        $this->type = $file['type'] ?? '';
        $this->tmpName = $file['tmp_name'] ?? '';
        $this->error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $this->size = $file['size'] ?? 0;
    }

    /**
     * $_FILES 배열에서 UploadedFile 객체 생성
     */
    public static function fromGlobal(string $key): ?self
    {
        if (!isset($_FILES[$key]) || $_FILES[$key]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return new self($_FILES[$key]);
    }

    /**
     * $_FILES 배열에서 다중 파일 UploadedFile 객체 배열 생성
     *
     * 지원 형식:
     * - files[] → fromGlobalMultiple('files')
     */
    public static function fromGlobalMultiple(string $key): array
    {
        if (!isset($_FILES[$key])) {
            return [];
        }

        $files = [];
        $fileData = $_FILES[$key];

        // 단일 파일인 경우
        if (!is_array($fileData['name'])) {
            if ($fileData['error'] !== UPLOAD_ERR_NO_FILE) {
                $files[] = new self($fileData);
            }
            return $files;
        }

        // 다중 파일인 경우
        $count = count($fileData['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($fileData['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = new self([
                'name' => $fileData['name'][$i],
                'type' => $fileData['type'][$i],
                'tmp_name' => $fileData['tmp_name'][$i],
                'error' => $fileData['error'][$i],
                'size' => $fileData['size'][$i],
            ]);
        }

        return $files;
    }

    /**
     * $_FILES 중첩 배열에서 다중 파일 UploadedFile 객체 배열 생성
     *
     * 지원 형식:
     * - fileData[fieldName][] → fromGlobalNested('fileData', 'fieldName')
     * - formData[files][] → fromGlobalNested('formData', 'files')
     *
     * @param string $key 최상위 키 (예: 'fileData')
     * @param string $field 중첩 키 (예: 'filename')
     * @return array<UploadedFile>
     */
    public static function fromGlobalNested(string $key, string $field): array
    {
        if (!isset($_FILES[$key])) {
            return [];
        }

        $fileData = $_FILES[$key];

        // 해당 필드가 없는 경우
        if (!isset($fileData['name'][$field])) {
            return [];
        }

        $files = [];
        $names = $fileData['name'][$field];

        // 단일 파일인 경우 (fileData[fieldName] - 배열이 아닌 경우)
        if (!is_array($names)) {
            if (($fileData['error'][$field] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $files[] = new self([
                    'name' => $names,
                    'type' => $fileData['type'][$field] ?? '',
                    'tmp_name' => $fileData['tmp_name'][$field] ?? '',
                    'error' => $fileData['error'][$field] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $fileData['size'][$field] ?? 0,
                ]);
            }
            return $files;
        }

        // 다중 파일인 경우 (fileData[fieldName][])
        // 빈 슬롯은 건너뛰되 원본 인덱스를 보존 — 호출자가 form 인덱스로
        // 매칭(예: 상품 이미지의 idx-별 새 파일/기존 URL 분기)할 수 있도록 함.
        foreach ($names as $i => $name) {
            if (($fileData['error'][$field][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[$i] = new self([
                'name' => $name,
                'type' => $fileData['type'][$field][$i] ?? '',
                'tmp_name' => $fileData['tmp_name'][$field][$i] ?? '',
                'error' => $fileData['error'][$field][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $fileData['size'][$field][$i] ?? 0,
            ]);
        }

        return $files;
    }

    /**
     * $_FILES에서 모든 중첩 필드의 파일들을 추출
     *
     * 지원 형식:
     * - fileData[field1][], fileData[field2][] → fromGlobalNestedAll('fileData')
     *
     * @param string $key 최상위 키 (예: 'fileData')
     * @return array<string, array<UploadedFile>> 필드명 => 파일 배열
     */
    public static function fromGlobalNestedAll(string $key): array
    {
        if (!isset($_FILES[$key])) {
            return [];
        }

        $fileData = $_FILES[$key];
        $result = [];

        // name 배열의 키들이 필드명
        if (!is_array($fileData['name'])) {
            return [];
        }

        foreach ($fileData['name'] as $field => $names) {
            $result[$field] = self::fromGlobalNested($key, $field);
        }

        return $result;
    }

    // === Getters ===

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTmpName(): string
    {
        return $this->tmpName;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * 확장자 반환 (소문자)
     */
    public function getExtension(): string
    {
        return strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }

    /**
     * 확장자 없는 파일명 반환
     */
    public function getBaseName(): string
    {
        return pathinfo($this->name, PATHINFO_FILENAME);
    }

    /**
     * 실제 MIME 타입 반환 (finfo 사용)
     */
    public function getMimeType(): string
    {
        if (!$this->isValid()) {
            return '';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $this->tmpName);
        finfo_close($finfo);

        return $mimeType ?: 'application/octet-stream';
    }

    // === Validation ===

    /**
     * 업로드 에러 없이 유효한지 확인
     */
    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_uploaded_file($this->tmpName);
    }

    /**
     * 업로드 에러 메시지 반환
     */
    public function getErrorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK => '',
            UPLOAD_ERR_INI_SIZE => '파일이 php.ini의 upload_max_filesize를 초과했습니다.',
            UPLOAD_ERR_FORM_SIZE => '파일이 폼의 MAX_FILE_SIZE를 초과했습니다.',
            UPLOAD_ERR_PARTIAL => '파일이 일부만 업로드되었습니다.',
            UPLOAD_ERR_NO_FILE => '파일이 업로드되지 않았습니다.',
            UPLOAD_ERR_NO_TMP_DIR => '임시 폴더가 없습니다.',
            UPLOAD_ERR_CANT_WRITE => '디스크에 쓸 수 없습니다.',
            UPLOAD_ERR_EXTENSION => 'PHP 확장에 의해 업로드가 중지되었습니다.',
            default => '알 수 없는 업로드 오류가 발생했습니다.',
        };
    }

    /**
     * 이미지 파일인지 확인
     */
    public function isImage(): bool
    {
        // svg는 이미지로 취급하지 않는다(인라인 XSS 위험 + getimagesize 미지원). UploadPolicy와 일관.
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        return in_array($this->getExtension(), $imageExtensions, true);
    }

    /**
     * 이미지 정보 반환 (이미지 파일인 경우)
     */
    public function getImageInfo(): ?array
    {
        if (!$this->isValid() || !$this->isImage()) {
            return null;
        }

        $info = @getimagesize($this->tmpName);
        if (!$info) {
            return null;
        }

        return [
            'width' => $info[0],
            'height' => $info[1],
            'type' => $info[2],
            'mime' => $info['mime'],
        ];
    }
}
