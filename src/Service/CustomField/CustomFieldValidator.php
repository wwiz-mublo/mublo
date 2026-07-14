<?php

namespace Mublo\Service\CustomField;

use Mublo\Core\Result\Result;

/**
 * CustomFieldValidator
 *
 * 커스텀 필드 타입별 값 검증 유틸리티
 * 회원 필드, 주문 필드 등 모든 커스텀 필드 시스템에서 공용 사용
 */
class CustomFieldValidator
{
    /**
     * file 계열(파일 업로드 파이프라인을 타는) 타입 여부.
     * avatar는 이미지 전용 file 타입으로 file과 동일한 저장/조회/검증 경로를 사용한다.
     */
    public static function isFileType(string $fieldType): bool
    {
        return $fieldType === 'file' || $fieldType === 'avatar';
    }

    /**
     * 필드 타입별 값 검증
     */
    public static function validateByType(string $fieldType, mixed $value, string $fieldLabel = '필드'): Result
    {
        switch ($fieldType) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return Result::failure("{$fieldLabel}이(가) 올바른 이메일 형식이 아닙니다.");
                }
                break;

            case 'tel':
                $telPattern = '/^(0[0-9]{1,2})-?([0-9]{3,4})-?([0-9]{4})$/';
                if (!preg_match($telPattern, $value)) {
                    return Result::failure("{$fieldLabel}이(가) 올바른 전화번호 형식이 아닙니다.");
                }
                break;

            case 'number':
                if (!is_numeric($value)) {
                    return Result::failure("{$fieldLabel}은(는) 숫자만 입력 가능합니다.");
                }
                break;

            case 'date':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    return Result::failure("{$fieldLabel}은(는) YYYY-MM-DD 형식이어야 합니다.");
                }
                $date = \DateTime::createFromFormat('Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    return Result::failure("{$fieldLabel}이(가) 유효한 날짜가 아닙니다.");
                }
                break;

            default:
                break;
        }

        return Result::success('유효한 값입니다.');
    }

    /**
     * 정규식 패턴 검증
     */
    public static function validateRegex(mixed $value, ?string $pattern, string $fieldLabel = '필드'): Result
    {
        if (empty($pattern)) {
            return Result::success('');
        }

        $p = $pattern;
        if ($p[0] !== '/' && $p[0] !== '#') {
            $p = '/' . $p . '/';
        }

        if (@preg_match($p, '') === false) {
            return Result::failure("{$fieldLabel}의 검증 패턴이 올바르지 않습니다.");
        }

        if (!preg_match($p, (string) $value)) {
            return Result::failure("{$fieldLabel}의 형식이 올바르지 않습니다.");
        }

        return Result::success('');
    }

    /**
     * 필드 타입에 따른 빈값 판단
     *
     * - file: null, '', '__delete__' 이면 빈값
     * - address: zipcode/address1/address2 모두 비었으면 빈값
     * - checkbox: 배열이면 implode 후 판단
     * - 기타: null, '', 빈 배열이면 빈값
     */
    public static function isEmpty(string $fieldType, mixed $value): bool
    {
        if (self::isFileType($fieldType)) {
            return $value === null || $value === '' || $value === '__delete__';
        }

        if ($fieldType === 'address' && is_array($value)) {
            $joined = trim(($value['zipcode'] ?? '') . ($value['address1'] ?? '') . ($value['address2'] ?? ''));
            return $joined === '';
        }

        if ($fieldType === 'checkbox' && is_array($value)) {
            return empty($value);
        }

        return $value === null || $value === '' || (is_array($value) && empty($value));
    }

    /**
     * 복합 타입 값 정규화 (저장 전 처리용)
     *
     * - address 배열 → JSON 문자열
     * - checkbox 배열 → 콤마 구분 문자열
     */
    public static function normalizeForStorage(string $fieldType, mixed $value): mixed
    {
        if ($fieldType === 'address' && is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if ($fieldType === 'checkbox' && is_array($value)) {
            return implode(',', array_map('strval', $value));
        }

        return $value;
    }
}
