<?php
declare(strict_types=1);
namespace Mublo\Service\Member;

use Mublo\Repository\Member\MemberFieldRepository;
use Mublo\Core\Result\Result;
use Mublo\Service\CustomField\CustomFieldValidator;

/**
 * MemberFieldService
 *
 * 회원 추가 필드 관리 서비스
 * - 필드 CRUD 비즈니스 로직
 * - 필드 정렬
 */
class MemberFieldService
{
    private MemberFieldRepository $fieldRepository;

    public function __construct(MemberFieldRepository $fieldRepository)
    {
        $this->fieldRepository = $fieldRepository;
    }

    /**
     * 도메인별 필드 목록 조회
     */
    public function getFields(int $domainId): array
    {
        return $this->fieldRepository->findByDomain($domainId);
    }

    /**
     * 목록 표시 필드 조회 (is_visible_list = 1)
     */
    public function getListVisibleFields(int $domainId): array
    {
        return $this->fieldRepository->findListVisibleByDomain($domainId);
    }

    /**
     * 검색 가능 필드 조회 (is_searched = 1)
     *
     * @return array [field_name => ['field_id' => int, 'field_label' => string, 'is_encrypted' => bool], ...]
     */
    public function getSearchableFields(int $domainId): array
    {
        $fields = $this->fieldRepository->findSearchableByDomain($domainId);

        $result = [];
        foreach ($fields as $field) {
            $result[$field['field_name']] = [
                'field_id' => (int) $field['field_id'],
                'field_label' => $field['field_label'],
                'is_encrypted' => (bool) $field['is_encrypted'],
            ];
        }

        return $result;
    }

    /**
     * 필드 단건 조회
     *
     * @param int|null $domainId 지정 시 해당 도메인 소유 필드만 반환 (멀티도메인 IDOR 방지)
     */
    public function getField(int $fieldId, ?int $domainId = null): ?array
    {
        $field = $this->fieldRepository->find($fieldId);
        if (!$field) {
            return null;
        }

        $field = (array) $field;

        if ($domainId !== null && (int) ($field['domain_id'] ?? 0) !== $domainId) {
            return null;
        }

        return $field;
    }

    /**
     * 필드 생성
     */
    public function createField(int $domainId, array $data): Result
    {
        $fieldType = $data['field_type'] ?? 'text';

        // 아바타는 도메인당 1개 예약 필드: 필드명을 'avatar'로 고정.
        // (필드명 도메인 내 유일성으로 자연히 singleton 보장 — 두 번째 생성 시 중복 거부)
        if ($fieldType === 'avatar') {
            $data['field_name'] = 'avatar';
        }

        // 필수 값 검증
        if (empty($data['field_name'])) {
            return Result::failure('필드명은 필수입니다.');
        }

        if (empty($data['field_label'])) {
            return Result::failure('필드 라벨은 필수입니다.');
        }

        // 필드명 형식 검증 (영문, 숫자, 언더스코어만)
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $data['field_name'])) {
            return Result::failure('필드명은 영문 소문자로 시작하고, 영문 소문자, 숫자, 언더스코어만 사용할 수 있습니다.');
        }

        // 필드명 중복 검사
        if ($this->fieldRepository->existsByDomainAndName($domainId, $data['field_name'])) {
            return Result::failure($fieldType === 'avatar'
                ? '이미 아바타 필드가 존재합니다. 아바타는 도메인당 하나만 만들 수 있습니다.'
                : '이미 존재하는 필드명입니다.');
        }

        // 마지막 정렬 순서
        $maxOrder = $this->fieldRepository->getMaxSortOrder($domainId);

        // file/avatar 타입은 암호화/검색/유일성 비활성
        if (CustomFieldValidator::isFileType($fieldType)) {
            $data['is_encrypted'] = 0;
            $data['is_searched'] = 0;
            $data['is_unique'] = 0;
        }

        $insertData = [
            'domain_id' => $domainId,
            'field_name' => $data['field_name'],
            'field_label' => $data['field_label'],
            'field_type' => $fieldType,
            'field_options' => !empty($data['field_options']) ? json_encode($data['field_options']) : null,
            'field_config' => !empty($data['field_config']) ? json_encode($data['field_config']) : null,
            'is_encrypted' => (int) ($data['is_encrypted'] ?? 0),
            'is_required' => (int) ($data['is_required'] ?? 0),
            'validation_rule' => $data['validation_rule'] ?? null,
            'sort_order' => $maxOrder + 1,
            'is_visible_signup' => (int) ($data['is_visible_signup'] ?? 1),
            'is_visible_profile' => (int) ($data['is_visible_profile'] ?? 1),
            'is_visible_list' => (int) ($data['is_visible_list'] ?? 0),
            'is_admin_only' => (int) ($data['is_admin_only'] ?? 0),
            'is_searched' => (int) ($data['is_searched'] ?? 0),
            'is_unique' => (int) ($data['is_unique'] ?? 0),
        ];

        $fieldId = $this->fieldRepository->create($insertData);

        if (!$fieldId) {
            return Result::failure('필드 생성에 실패했습니다.');
        }

        return Result::success('필드가 생성되었습니다.', ['field_id' => $fieldId]);
    }

    /**
     * 필드 수정
     */
    public function updateField(int $fieldId, array $data, ?int $domainId = null): Result
    {
        $field = $this->getField($fieldId, $domainId);
        if (!$field) {
            return Result::failure('필드를 찾을 수 없습니다.');
        }

        // 아바타(예약 필드)는 이름을 'avatar'로 고정 — 변경 시도 무시
        $fieldType = $data['field_type'] ?? $field['field_type'] ?? 'text';
        if ($fieldType === 'avatar') {
            $data['field_name'] = 'avatar';
        }

        // 필드명 변경 시 중복 검사
        if (!empty($data['field_name']) && $data['field_name'] !== $field['field_name']) {
            // 필드명 형식 검증
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $data['field_name'])) {
                return Result::failure('필드명은 영문 소문자로 시작하고, 영문 소문자, 숫자, 언더스코어만 사용할 수 있습니다.');
            }

            if ($this->fieldRepository->existsByDomainAndName($field['domain_id'], $data['field_name'], $fieldId)) {
                return Result::failure('이미 존재하는 필드명입니다.');
            }
        }

        $updateData = [];

        // file/avatar 타입은 암호화/검색/유일성 비활성
        if (CustomFieldValidator::isFileType($fieldType)) {
            $data['is_encrypted'] = 0;
            $data['is_searched'] = 0;
            $data['is_unique'] = 0;
        }

        // 업데이트 가능한 필드들
        $allowedFields = [
            'field_name', 'field_label', 'field_type', 'is_encrypted',
            'is_required', 'is_unique', 'validation_rule', 'is_visible_signup',
            'is_visible_profile', 'is_visible_list', 'is_admin_only', 'is_searched',
        ];

        foreach ($allowedFields as $key) {
            if (array_key_exists($key, $data)) {
                if (in_array($key, ['is_encrypted', 'is_required', 'is_unique', 'is_visible_signup', 'is_visible_profile', 'is_visible_list', 'is_admin_only', 'is_searched'])) {
                    $updateData[$key] = (int) $data[$key];
                } else {
                    $updateData[$key] = $data[$key];
                }
            }
        }

        // field_options 처리
        if (array_key_exists('field_options', $data)) {
            $updateData['field_options'] = !empty($data['field_options']) ? json_encode($data['field_options']) : null;
        }

        // field_config 처리
        if (array_key_exists('field_config', $data)) {
            $updateData['field_config'] = !empty($data['field_config']) ? json_encode($data['field_config']) : null;
        }

        if (empty($updateData)) {
            return Result::failure('수정할 데이터가 없습니다.');
        }

        $this->fieldRepository->update($fieldId, $updateData);

        return Result::success('필드가 수정되었습니다.');
    }

    /**
     * 필드 삭제
     */
    public function deleteField(int $fieldId, ?int $domainId = null): Result
    {
        $field = $this->getField($fieldId, $domainId);
        if (!$field) {
            return Result::failure('필드를 찾을 수 없습니다.');
        }

        // 필드 삭제 (FK CASCADE로 member_field_values도 자동 삭제)
        $this->fieldRepository->delete($fieldId);

        return Result::success('필드가 삭제되었습니다.');
    }

    /**
     * 필드 정렬 순서 변경
     */
    public function updateOrder(int $domainId, array $fieldIds): Result
    {
        $order = 1;
        foreach ($fieldIds as $fieldId) {
            $this->fieldRepository->updateSortOrder($fieldId, $domainId, $order);
            $order++;
        }

        return Result::success('정렬 순서가 변경되었습니다.');
    }

    /**
     * 필드 타입 옵션 목록
     */
    public function getFieldTypeOptions(): array
    {
        return [
            'text' => '텍스트',
            'email' => '이메일',
            'tel' => '전화번호',
            'number' => '숫자',
            'date' => '날짜',
            'textarea' => '여러 줄 텍스트',
            'select' => '선택 (드롭다운)',
            'radio' => '라디오 버튼',
            'checkbox' => '체크박스',
            'address' => '주소 (우편번호 검색)',
            'file' => '첨부파일',
            'avatar' => '아바타 (이미지)',
        ];
    }
}
