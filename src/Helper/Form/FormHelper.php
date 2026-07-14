<?php

namespace Mublo\Helper\Form;

use Mublo\Helper\String\StringHelper;
use Mublo\Helper\Security\HtmlSanitizer;

/**
 * FormHelper
 *
 * 폼 데이터 정제 및 파일 처리 유틸리티
 * - 외부 의존성 최소화 (StringHelper만 사용)
 * - 모든 메서드는 static
 *
 * 사용:
 * FormHelper::normalizeFormData($formData, $schema);
 * FormHelper::normalizeFileData($fileData);
 * FormHelper::organizeUploadedFiles($_FILES);
 */
class FormHelper
{
    /**
     * 폼 데이터 정제 (formData[필드명] 형식)
     *
     * @param array $formData 폼 데이터 배열
     * @param array $schema 필드 타입 스키마
     *   - numeric: 숫자 필드 목록 ['field1', 'field2']
     *   - date: 날짜 필드 목록 ['field1', 'field2']
     *   - bool: 불린 필드 목록 ['field1', 'field2']
     *   - json: JSON 필드 목록 ['field1', 'field2']
     *   - html: HTML 필드 목록 (sanitize 제외, 태그 유지) ['field1', 'field2']
     *   - required_string: 필수 문자열 (NULL 불가) ['field1', 'field2']
     *   - enum: ENUM 필드 ['field' => ['values' => [...], 'default' => '...']]
     * @return array 정제된 데이터
     *
     * @example
     * $normalized = FormHelper::normalizeFormData($formData, [
     *     'numeric' => ['member_id', 'storage_limit'],
     *     'date' => ['start_date', 'end_date'],
     *     'required_string' => ['domain'],
     *     'enum' => [
     *         'status' => ['values' => ['active', 'inactive'], 'default' => 'active'],
     *     ],
     * ]);
     */
    public static function normalizeFormData(array $formData, array $schema = []): array
    {
        if (empty($formData)) {
            return [];
        }

        $result = [];

        // 스키마 정의 추출
        $numericFields = $schema['numeric'] ?? [];
        $dateFields = $schema['date'] ?? [];
        $boolFields = $schema['bool'] ?? [];
        $enumFields = $schema['enum'] ?? [];
        $requiredStringFields = $schema['required_string'] ?? [];
        $jsonFields = $schema['json'] ?? [];
        $htmlFields = $schema['html'] ?? [];

        foreach ($formData as $key => $val) {
            // 배열은 그대로 (체크박스, 다중값 등)
            if (is_array($val)) {
                $result[$key] = $val;
                continue;
            }

            // 문자열 기준 처리
            $val = trim((string)$val);

            // ------------------------
            // 날짜 필드
            // ------------------------
            if (in_array($key, $dateFields, true)) {
                if ($val === '') {
                    $result[$key] = null;
                } else {
                    $timestamp = strtotime($val);
                    $result[$key] = ($timestamp !== false) ? $val : null;
                }
                continue;
            }

            // ------------------------
            // 숫자 필드
            // ------------------------
            if (in_array($key, $numericFields, true)) {
                $result[$key] = StringHelper::pickNumber($val);
                continue;
            }

            // ------------------------
            // 불린 필드
            // ------------------------
            if (in_array($key, $boolFields, true)) {
                $result[$key] = in_array($val, ['1', 'true', 'Y', 'yes', 'on'], true) ? 1 : 0;
                continue;
            }

            // ------------------------
            // JSON 필드
            // ------------------------
            if (in_array($key, $jsonFields, true)) {
                if ($val === '') {
                    $result[$key] = null;
                } else {
                    $decoded = json_decode($val, true);
                    $result[$key] = (json_last_error() === JSON_ERROR_NONE) ? $val : null;
                }
                continue;
            }

            // ------------------------
            // HTML 필드 (에디터 등, 허용된 태그만 유지 + XSS 방지)
            // ------------------------
            if (in_array($key, $htmlFields, true)) {
                // HTMLPurifier 기반 정화 (basic 프로파일 — iframe 불가)
                $result[$key] = ($val === '') ? null : HtmlSanitizer::sanitizeBasic($val);
                continue;
            }

            // ------------------------
            // ENUM 필드
            // ------------------------
            if (isset($enumFields[$key])) {
                $allowed = $enumFields[$key]['values'] ?? [];
                $default = $enumFields[$key]['default'] ?? null;

                if ($val === '' || !in_array($val, $allowed, true)) {
                    $result[$key] = $default;
                } else {
                    $result[$key] = $val;
                }
                continue;
            }

            // ------------------------
            // 필수 문자열 (NOT NULL)
            // ------------------------
            if (in_array($key, $requiredStringFields, true)) {
                $result[$key] = StringHelper::sanitize($val);
                continue;
            }

            // ------------------------
            // 일반 문자열 (NULL 허용)
            // ------------------------
            $result[$key] = ($val === '') ? null : StringHelper::sanitize($val);
        }

        // formData에 없지만 bool 스키마에 정의된 필드 → 0 (체크박스 해제 시 키 미전송 대응)
        foreach ($boolFields as $fieldName) {
            if (!isset($result[$fieldName])) {
                $result[$fieldName] = 0;
            }
        }

        // formData에 없지만 enum 스키마에 정의된 필드의 기본값 자동 설정
        foreach ($enumFields as $fieldName => $config) {
            if (!isset($result[$fieldName])) {
                $result[$fieldName] = $config['default'] ?? null;
            }
        }

        return $result;
    }

    /**
     * 파일 데이터 정제 (fileData[필드명] 형식)
     *
     * $_FILES 배열을 필드명 기준으로 정리하여 반환
     *
     * @param array $fileData $_FILES['fileData'] 또는 유사 구조
     * @return array 정제된 파일 배열 [필드명 => [파일정보...]]
     *
     * @example
     * // 입력: $_FILES['fileData']
     * // 출력: ['logo' => ['name' => ..., 'tmp_name' => ...], 'favicon' => [...]]
     */
    public static function normalizeFileData(array $fileData): array
    {
        if (empty($fileData)) {
            return [];
        }

        $result = [];

        // $_FILES 구조 확인: name, type, tmp_name, error, size가 배열인 경우
        if (isset($fileData['name']) && is_array($fileData['name'])) {
            foreach ($fileData['name'] as $fieldName => $fileName) {
                // 파일이 실제로 업로드되었는지 확인
                if (empty($fileName) || $fileData['error'][$fieldName] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                $result[$fieldName] = [
                    'name' => $fileName,
                    'type' => $fileData['type'][$fieldName] ?? '',
                    'tmp_name' => $fileData['tmp_name'][$fieldName] ?? '',
                    'error' => $fileData['error'][$fieldName] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $fileData['size'][$fieldName] ?? 0,
                ];
            }
        } else {
            // 단일 파일 또는 이미 정리된 구조
            $result = $fileData;
        }

        return $result;
    }

    /**
     * $_FILES 전체를 정리된 배열로 재구성
     *
     * PHP의 $_FILES 배열 구조를 사용하기 쉬운 형태로 변환
     * 다중 파일 업로드도 지원
     *
     * @param array $files $_FILES 배열
     * @return array 재구성된 파일 배열
     *
     * @example
     * // 입력: $_FILES (PHP 기본 구조)
     * // 출력: [
     * //   'formData' => ['logo' => [파일정보], 'favicon' => [파일정보]],
     * //   'fileData' => ['attachment' => [[파일1], [파일2], ...]],
     * // ]
     */
    public static function organizeUploadedFiles(array $files): array
    {
        if (empty($files)) {
            return [];
        }

        $result = [];

        foreach ($files as $inputName => $fileInfo) {
            // name이 배열인 경우 (다중 필드 또는 다중 파일)
            if (is_array($fileInfo['name'])) {
                $result[$inputName] = self::reorganizeMultipleFiles($fileInfo);
            } else {
                // 단일 파일
                if ($fileInfo['error'] !== UPLOAD_ERR_NO_FILE && !empty($fileInfo['name'])) {
                    $result[$inputName] = $fileInfo;
                }
            }
        }

        return $result;
    }

    /**
     * 다중 파일 배열 재구성
     *
     * @param array $fileInfo $_FILES의 단일 input에 대한 정보
     * @return array 재구성된 배열
     */
    private static function reorganizeMultipleFiles(array $fileInfo): array
    {
        $result = [];

        foreach ($fileInfo['name'] as $key => $name) {
            // 파일이 업로드되지 않은 경우 스킵
            if (empty($name) || $fileInfo['error'][$key] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            // 중첩 배열인 경우 (다중 파일 업로드: input[field][])
            if (is_array($name)) {
                $result[$key] = [];
                foreach ($name as $idx => $fileName) {
                    if (empty($fileName) || $fileInfo['error'][$key][$idx] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $result[$key][] = [
                        'name' => $fileName,
                        'type' => $fileInfo['type'][$key][$idx] ?? '',
                        'tmp_name' => $fileInfo['tmp_name'][$key][$idx] ?? '',
                        'error' => $fileInfo['error'][$key][$idx] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $fileInfo['size'][$key][$idx] ?? 0,
                    ];
                }
            } else {
                // 단일 레벨 (formData[field] 형식)
                $result[$key] = [
                    'name' => $name,
                    'type' => $fileInfo['type'][$key] ?? '',
                    'tmp_name' => $fileInfo['tmp_name'][$key] ?? '',
                    'error' => $fileInfo['error'][$key] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $fileInfo['size'][$key] ?? 0,
                ];
            }
        }

        return $result;
    }

    /**
     * 업로드된 파일의 유효성 검증
     *
     * @param array $file 단일 파일 정보 배열
     * @param array $options 검증 옵션
     *   - maxSize: 최대 파일 크기 (bytes)
     *   - allowedTypes: 허용 MIME 타입 배열
     *   - allowedExtensions: 허용 확장자 배열
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateUploadedFile(array $file, array $options = []): array
    {
        // 업로드 에러 체크
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [
                'valid' => false,
                'error' => self::getUploadErrorMessage($file['error']),
            ];
        }

        // 파일 크기 체크
        if (isset($options['maxSize']) && $file['size'] > $options['maxSize']) {
            $maxSizeMB = round($options['maxSize'] / 1024 / 1024, 2);
            return [
                'valid' => false,
                'error' => "파일 크기가 {$maxSizeMB}MB를 초과합니다.",
            ];
        }

        // MIME 타입 체크
        if (!empty($options['allowedTypes'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $options['allowedTypes'], true)) {
                return [
                    'valid' => false,
                    'error' => '허용되지 않는 파일 형식입니다.',
                ];
            }
        }

        // 확장자 체크
        if (!empty($options['allowedExtensions'])) {
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, $options['allowedExtensions'], true)) {
                return [
                    'valid' => false,
                    'error' => '허용되지 않는 파일 확장자입니다.',
                ];
            }
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * 업로드 에러 코드에 대한 메시지 반환
     *
     * @param int $errorCode PHP 업로드 에러 코드
     * @return string 에러 메시지
     */
    public static function getUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE => '파일이 서버 설정 최대 크기를 초과합니다.',
            UPLOAD_ERR_FORM_SIZE => '파일이 폼 설정 최대 크기를 초과합니다.',
            UPLOAD_ERR_PARTIAL => '파일이 일부만 업로드되었습니다.',
            UPLOAD_ERR_NO_FILE => '업로드된 파일이 없습니다.',
            UPLOAD_ERR_NO_TMP_DIR => '임시 폴더가 없습니다.',
            UPLOAD_ERR_CANT_WRITE => '디스크에 파일을 쓸 수 없습니다.',
            UPLOAD_ERR_EXTENSION => 'PHP 확장에 의해 업로드가 중지되었습니다.',
            default => '알 수 없는 업로드 에러가 발생했습니다.',
        };
    }

}
