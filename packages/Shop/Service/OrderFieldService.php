<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Contract\CustomField\CustomFieldFileManagerInterface;
use Mublo\Contract\CustomField\CustomFieldValueValidatorInterface;
use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Mublo\Packages\Shop\Repository\OrderFieldRepository;

/**
 * OrderFieldService
 *
 * 주문 추가 필드 CRUD + 값 검증/저장/조회
 * CustomField 헬퍼 시스템 재사용
 */
class OrderFieldService
{
    private OrderFieldRepository $repository;
    private SensitiveValueCodecInterface $encryptionService;
    private CustomFieldValueValidatorInterface $validator;
    private ?CustomFieldFileManagerInterface $fileHandler;

    /**
     * 주문서 파일 필드 처리기.
     *
     * SecureFileService 가 없는 설치에서는 null 이다.
     */
    public function getFileHandler(): ?CustomFieldFileManagerInterface
    {
        return $this->fileHandler;
    }

    public function __construct(
        OrderFieldRepository $repository,
        SensitiveValueCodecInterface $encryptionService,
        CustomFieldValueValidatorInterface $validator,
        ?CustomFieldFileManagerInterface $fileHandler = null
    ) {
        $this->repository = $repository;
        $this->encryptionService = $encryptionService;
        $this->validator = $validator;
        $this->fileHandler = $fileHandler;
    }

    // ═══════════════════════════════════════
    // Admin: 필드 정의 CRUD
    // ═══════════════════════════════════════

    /**
     * 도메인별 전체 필드 목록 (관리자)
     */
    public function getFields(int $domainId): array
    {
        return $this->repository->findByDomain($domainId);
    }

    /**
     * 필드 단건 조회
     */
    public function getField(int $domainId, int $fieldId, bool $frontOnly = false): ?array
    {
        return $this->repository->findFieldInDomain($domainId, $fieldId, $frontOnly);
    }

    /**
     * 활성 필드 목록 (Front 체크아웃용)
     */
    public function getActiveFields(int $domainId): array
    {
        return $this->repository->findActiveByDomain($domainId);
    }

    /**
     * 필드 생성/수정
     */
    public function saveField(int $domainId, array $data): Result
    {
        $fieldId = (int) ($data['field_id'] ?? 0);
        $fieldName = trim($data['field_name'] ?? '');
        $fieldLabel = trim($data['field_label'] ?? '');
        $fieldType = $data['field_type'] ?? 'text';

        if ($fieldName === '' || $fieldLabel === '') {
            return Result::failure('필드명과 라벨을 입력해주세요.');
        }

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $fieldName)) {
            return Result::failure('필드명은 영문 소문자, 숫자, 언더스코어만 사용 가능합니다.');
        }

        if ($fieldId > 0 && $this->repository->findFieldInDomain($domainId, $fieldId) === null) {
            return Result::failure('필드를 찾을 수 없습니다.');
        }

        // 중복 체크
        if ($this->repository->existsByDomainAndName($domainId, $fieldName, $fieldId ?: null)) {
            return Result::failure("'{$fieldName}' 필드명이 이미 존재합니다.");
        }

        // options: select/radio/checkbox → JSON 변환
        $fieldOptions = $data['field_options'] ?? null;
        if (is_array($fieldOptions)) {
            $fieldOptions = json_encode(array_values(array_filter($fieldOptions, fn($v) => $v !== '')), JSON_UNESCAPED_UNICODE);
        }

        // config: file → JSON 변환
        $fieldConfig = $data['field_config'] ?? null;
        if (is_array($fieldConfig)) {
            $fieldConfig = json_encode($fieldConfig, JSON_UNESCAPED_UNICODE);
        }

        $saveData = [
            'domain_id' => $domainId,
            'field_name' => $fieldName,
            'field_label' => $fieldLabel,
            'field_type' => $fieldType,
            'field_options' => $fieldOptions,
            'field_config' => $fieldConfig,
            'placeholder' => trim($data['placeholder'] ?? ''),
            'is_encrypted' => (int) ($data['is_encrypted'] ?? 0),
            'is_required' => (int) ($data['is_required'] ?? 0),
            'is_active' => (int) ($data['is_active'] ?? 1),
            'is_admin_only' => (int) ($data['is_admin_only'] ?? 0),
        ];

        if ($fieldId > 0) {
            // 기존 소유권을 확인했고 UPDATE WHERE에도 domain_id를 반복한다.
            // 동일값 저장은 DB가 0행을 반환할 수 있으므로 실패로 취급하지 않는다.
            $this->repository->updateFieldInDomain($domainId, $fieldId, $saveData);
            return Result::success('필드가 수정되었습니다.', ['field_id' => $fieldId]);
        }

        $saveData['sort_order'] = $this->repository->getMaxSortOrder($domainId) + 1;
        $newId = $this->repository->create($saveData);

        return Result::success('필드가 추가되었습니다.', ['field_id' => $newId]);
    }

    /**
     * 필드 삭제
     */
    public function deleteField(int $domainId, int $fieldId): Result
    {
        $field = $this->repository->findFieldInDomain($domainId, $fieldId);
        if (!$field) {
            return Result::failure('필드를 찾을 수 없습니다.');
        }

        if ($this->repository->deleteFieldInDomain($domainId, $fieldId) < 1) {
            return Result::failure('필드를 삭제하지 못했습니다.');
        }
        return Result::success('필드가 삭제되었습니다.');
    }

    /**
     * 순서 변경
     */
    public function updateOrder(int $domainId, array $fieldIds): Result
    {
        foreach ($fieldIds as $i => $fieldId) {
            $this->repository->updateSortOrder((int) $fieldId, $domainId, $i + 1);
        }

        return Result::success('순서가 변경되었습니다.');
    }

    // ═══════════════════════════════════════
    // Front: 값 검증
    // ═══════════════════════════════════════

    /**
     * 체크아웃 필드 값 검증
     */
    public function validateValues(int $domainId, array $values): Result
    {
        $fields = $this->repository->findActiveByDomain($domainId);
        $errors = [];
        $fieldMap = [];

        foreach ($fields as $field) {
            $fieldMap[(int) $field['field_id']] = $field;
        }

        foreach (array_keys($values) as $submittedFieldId) {
            $normalizedId = filter_var($submittedFieldId, FILTER_VALIDATE_INT);
            if ($normalizedId === false || !isset($fieldMap[(int) $normalizedId])) {
                $errors[] = '유효하지 않은 주문 추가 필드가 포함되어 있습니다.';
                break;
            }
        }

        foreach ($fields as $field) {
            $fieldId = (int) $field['field_id'];
            $fieldType = $field['field_type'];
            $fieldLabel = $field['field_label'];
            $value = $values[$fieldId] ?? null;

            if ($this->validator->isEmpty($fieldType, $value)) {
                if ($field['is_required']) {
                    $errors[] = "{$fieldLabel}은(는) 필수 입력입니다.";
                }
                continue;
            }

            // file 타입은 별도 검증 불필요 (업로드 시 검증 완료)
            if ($fieldType === 'file') {
                continue;
            }

            $typeResult = $this->validator->validateType($fieldType, $value, $fieldLabel);
            if ($typeResult->isFailure()) {
                $errors[] = $typeResult->getMessage();
            }
        }

        if (!empty($errors)) {
            return Result::failure(implode("\n", $errors));
        }

        return Result::success('검증을 통과했습니다.');
    }

    // ═══════════════════════════════════════
    // Front: 값 저장
    // ═══════════════════════════════════════

    /**
     * 주문 필드 값 저장 (암호화 + 파일 처리)
     */
    public function saveValues(string $orderNo, int $domainId, array $values): Result
    {
        // 컨트롤러 사전 검증을 신뢰하지 않고 최종 쓰기 경계에서 다시 검증한다.
        $validation = $this->validateValues($domainId, $values);
        if ($validation->isFailure()) {
            return $validation;
        }

        $fields = $this->repository->findActiveByDomain($domainId);
        $fieldMap = [];
        foreach ($fields as $f) {
            $fieldMap[(int) $f['field_id']] = $f;
        }

        foreach ($values as $fieldId => $value) {
            $fieldId = (int) $fieldId;
            $field = $fieldMap[$fieldId];

            $fieldType = $field['field_type'];

            // file 타입: 파일 관리 계약으로 처리
            if ($fieldType === 'file') {
                $this->saveFileFieldValue($orderNo, (int) $fieldId, $value, $domainId);
                continue;
            }

            // 빈값 스킵
            if ($this->validator->isEmpty($fieldType, $value)) {
                continue;
            }

            // 복합 타입 정규화
            $value = $this->validator->normalizeForStorage($fieldType, $value);

            // 암호화
            if ($field['is_encrypted']) {
                $processed = $this->encryptionService->processFieldValue((string) $value, true, false);
                $value = $processed['field_value'];
            }

            $this->repository->saveValue($orderNo, (int) $fieldId, (string) $value);
        }

        return Result::success('주문 필드 값이 저장되었습니다.');
    }

    /**
     * file 타입 필드 값 저장
     */
    private function saveFileFieldValue(string $orderNo, int $fieldId, mixed $value, int $domainId): void
    {
        if ($this->fileHandler === null) {
            return;
        }

        $result = $this->fileHandler->processFileValue($value, $domainId, 'order-fields', $orderNo);

        if ($result->isFailure() || $result->get('action') === 'skip') {
            return;
        }

        // 기존 파일 삭제
        $existing = $this->repository->getFieldValue($orderNo, $fieldId);
        if ($existing) {
            $this->fileHandler->deleteFileByMeta($existing['field_value'] ?? null);
            $this->repository->deleteFieldValue($orderNo, $fieldId);
        }

        if ($result->get('action') === 'save') {
            $this->repository->saveValue($orderNo, $fieldId, $result->get('meta_json'));
        }
    }

    // ═══════════════════════════════════════
    // 조회 (관리자 주문 상세)
    // ═══════════════════════════════════════

    /**
     * 주문의 필드 값 목록 (복호화 + 파일 메타 포함)
     */
    public function getOrderFieldValues(string $orderNo): array
    {
        $rows = $this->repository->getValues($orderNo);
        $result = [];

        foreach ($rows as $row) {
            $fieldType = $row['field_type'];
            $value = $row['field_value'] ?? '';
            $displayValue = $value;

            // 암호화 필드 복호화
            if ($row['is_encrypted'] && $value !== '') {
                $displayValue = $this->encryptionService->readFieldValue($value, true) ?? '[복호화 실패]';
            }

            $entry = [
                'field_id' => $row['field_id'],
                'field_name' => $row['field_name'],
                'field_label' => $row['field_label'],
                'field_type' => $fieldType,
                'field_value' => $displayValue,
                'display_value' => $displayValue,
            ];

            // file: JSON 메타 파싱 + 다운로드 URL
            if ($fieldType === 'file' && $value !== '') {
                $meta = $this->fileHandler?->parseFileMetaWithUrl($value);
                if ($meta) {
                    $entry['filename'] = $meta['filename'] ?? '';
                    $entry['download_url'] = $meta['url'] ?? '';
                    $entry['display_value'] = $meta['filename'] ?? '';
                }
            }

            // address: JSON → 표시용 문자열
            if ($fieldType === 'address' && $displayValue !== '') {
                $addrData = json_decode($displayValue, true);
                if (is_array($addrData)) {
                    $entry['display_value'] = '[' . ($addrData['zipcode'] ?? '') . '] '
                        . ($addrData['address1'] ?? '') . ' ' . ($addrData['address2'] ?? '');
                }
            }

            // checkbox: 콤마 구분 → 표시
            if ($fieldType === 'checkbox' && $displayValue !== '') {
                $entry['display_value'] = str_replace(',', ', ', $displayValue);
            }

            $result[] = $entry;
        }

        return $result;
    }
}
