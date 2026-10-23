<?php
declare(strict_types=1);

namespace Mublo\Service\Member;

use Mublo\Entity\Domain\Domain;

/**
 * 가입 등급 판정 — 가입 때 고른 추가필드 값으로 기본 등급 대신 다른 등급을 준다.
 *
 * 사이트 설정(기본 설정 > 회원가입)의 '등급 배정 필드'(선택·라디오형 추가필드)와
 * '선택지 → 등급' 매핑을 쓴다. 매핑이 없거나 값이 맞지 않으면 기본 등급 그대로다.
 *
 * 매핑한 등급이 사라졌거나 관리자·최고관리자 등급이면 무시한다 — 가입 요청의 값으로
 * 관리자 권한을 얻는 일이 없게, 설정 저장 때와 별개로 가입 시점에 다시 막는다.
 */
class JoinLevelResolver
{
    public function __construct(
        private MemberLevelService $levelService,
        private MemberFieldService $fieldService,
    ) {
    }

    /**
     * @param array<int|string, mixed> $fields 가입 폼의 추가필드 값 (field_id => 값)
     */
    public function resolve(?Domain $domain, array $fields, int $defaultLevel): int
    {
        if ($domain === null) {
            return $defaultLevel;
        }

        $fieldId = $domain->getJoinLevelFieldId();
        $map = $domain->getJoinLevelMap();
        if ($fieldId <= 0 || $map === []) {
            return $defaultLevel;
        }

        $value = $fields[$fieldId] ?? $fields[(string) $fieldId] ?? null;
        if (!is_string($value) && !is_int($value)) {
            return $defaultLevel;
        }

        // 설정 뒤 필드가 숨김·관리자 전용·다른 유형으로 바뀌거나 선택지가 삭제될 수 있다.
        // 가입 시점의 현재 정의를 다시 확인해 예전 설정이나 직접 만든 요청을 적용하지 않는다.
        $field = $this->fieldService->getField($fieldId, $domain->getDomainId());
        if ($field === null
            || !in_array($field['field_type'] ?? '', ['select', 'radio'], true)
            || empty($field['is_visible_signup'])
            || !empty($field['is_admin_only'])
            || !in_array((string) $value, $this->optionValues($field['field_options'] ?? null), true)
        ) {
            return $defaultLevel;
        }

        $levelValue = $map[(string) $value] ?? null;
        if ($levelValue === null || $levelValue <= 0) {
            return $defaultLevel;
        }

        $level = $this->levelService->findByValue($levelValue);
        if ($level === null || $level->isAdmin() || $level->isSuper()) {
            return $defaultLevel;
        }

        return $levelValue;
    }

    /** @return list<string> */
    private function optionValues(mixed $rawOptions): array
    {
        $options = is_string($rawOptions) ? json_decode($rawOptions, true) : $rawOptions;
        if (!is_array($options)) {
            return [];
        }

        $values = [];
        foreach ($options as $option) {
            $value = is_array($option) ? ($option['value'] ?? null) : $option;
            if (is_string($value) || is_int($value)) {
                $values[] = (string) $value;
            }
        }
        return $values;
    }
}
