<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Packages\Shop\Action\ActionHandlerInterface;

/**
 * ActionTypeRegistry
 *
 * 주문 상태별 액션 타입 핸들러 등록/조회/검증 레지스트리
 *
 * 내장 타입: notification, point, webhook
 * Plugin 확장: Provider.boot()에서 register() 호출
 */
class ActionTypeRegistry
{
    /** @var array<string, ActionHandlerInterface> */
    private array $handlers = [];

    /**
     * 액션 타입 핸들러 등록
     */
    public function register(ActionHandlerInterface $handler): void
    {
        $type = trim($handler->getType());
        if ($type === '') {
            throw new \LogicException('액션 타입은 비어 있을 수 없습니다.');
        }
        if (isset($this->handlers[$type])) {
            throw new \LogicException("이미 등록된 액션 타입입니다: {$type}");
        }

        $this->handlers[$type] = $handler;
    }

    /**
     * 핸들러 조회
     *
     * @throws \RuntimeException 미등록 타입
     */
    public function getHandler(string $type): ActionHandlerInterface
    {
        if (!isset($this->handlers[$type])) {
            throw new \RuntimeException("등록되지 않은 액션 타입: {$type}");
        }

        return $this->handlers[$type];
    }

    /**
     * 핸들러 존재 여부
     */
    public function hasHandler(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /**
     * 등록된 모든 타입 목록 (UI 드롭다운용)
     *
     * @return array<string, string> [type => label]
     */
    public function getRegisteredTypes(): array
    {
        $types = [];
        foreach ($this->handlers as $type => $handler) {
            $types[$type] = $handler->getLabel();
        }
        return $types;
    }

    /**
     * 등록된 모든 타입의 스키마 (UI 폼 필드 동적 생성용)
     *
     * @return array<string, array> [type => schema]
     */
    public function getAllSchemas(): array
    {
        $schemas = [];
        foreach ($this->handlers as $type => $handler) {
            $schemas[$type] = $handler->getSchema();
        }
        return $schemas;
    }

    /**
     * 등록된 타입별 설명 (관리자 UI 가이드용)
     *
     * @return array<string, string> {type => description}
     */
    public function getAllDescriptions(): array
    {
        $descriptions = [];
        foreach ($this->handlers as $type => $handler) {
            $descriptions[$type] = $handler->getDescription();
        }
        return $descriptions;
    }

    /**
     * 타입별 중복 등록 허용 여부 (관리자 UI 전달용)
     *
     * @return array<string, bool> {type => allowDuplicate}
     */
    public function getAllowDuplicates(): array
    {
        $result = [];
        foreach ($this->handlers as $type => $handler) {
            $result[$type] = $handler->allowDuplicate();
        }
        return $result;
    }

    /**
     * 상태 내 액션 중복 등록 검증
     *
     * allowDuplicate()가 false인 타입이 2개 이상 등록되면 에러
     *
     * @param array $actions 단일 상태의 액션 배열
     * @return array 에러 메시지 배열 (빈 배열 = 통과)
     */
    public function validateDuplicates(array $actions): array
    {
        $errors = [];
        $typeCounts = [];

        foreach ($actions as $action) {
            $type = $action['type'] ?? '';
            if (!$this->hasHandler($type)) {
                continue;
            }

            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;

            if ($typeCounts[$type] > 1 && !$this->handlers[$type]->allowDuplicate()) {
                $label = $this->handlers[$type]->getLabel();
                $errors[] = "'{$label}' 액션은 하나만 등록할 수 있습니다.";
            }
        }

        return $errors;
    }

    /**
     * 액션 Config 검증 (타입별 필수 파라미터 체크)
     *
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public function validateAction(array $actionConfig): array
    {
        $type = $actionConfig['type'] ?? '';

        if (empty($type)) {
            return ['valid' => false, 'errors' => ['액션 타입이 지정되지 않았습니다.']];
        }

        if (!isset($this->handlers[$type])) {
            return ['valid' => false, 'errors' => ["등록되지 않은 액션 타입: '{$type}'"]];
        }

        $schema = $this->handlers[$type]->getSchema();
        $errors = [];

        foreach ($schema['required'] ?? [] as $field) {
            if (!array_key_exists($field, $actionConfig)
                || $actionConfig[$field] === null
                || (is_string($actionConfig[$field]) && trim($actionConfig[$field]) === '')
            ) {
                $fieldLabel = $schema['fields'][$field]['label'] ?? $field;
                $errors[] = "'{$fieldLabel}' 항목은 필수입니다.";
            }
        }

        foreach ($schema['fields'] ?? [] as $field => $definition) {
            if (!array_key_exists($field, $actionConfig) || $actionConfig[$field] === '') {
                continue;
            }

            $value = $actionConfig[$field];
            $fieldLabel = $definition['label'] ?? $field;
            $fieldType = $definition['type'] ?? 'text';

            if ($fieldType === 'number') {
                if (!is_numeric($value)) {
                    $errors[] = "'{$fieldLabel}' 항목은 숫자여야 합니다.";
                    continue;
                }
                if (isset($definition['min']) && (float) $value < (float) $definition['min']) {
                    $errors[] = "'{$fieldLabel}' 항목은 {$definition['min']} 이상이어야 합니다.";
                }
                if (isset($definition['max']) && (float) $value > (float) $definition['max']) {
                    $errors[] = "'{$fieldLabel}' 항목은 {$definition['max']} 이하여야 합니다.";
                }
            }

            if ($fieldType === 'select') {
                $options = array_keys((array) ($definition['options'] ?? []));
                if (!in_array((string) $value, $options, true)) {
                    $errors[] = "'{$fieldLabel}' 항목의 선택값이 올바르지 않습니다.";
                }
            }

            if ($fieldType === 'url' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                $errors[] = "'{$fieldLabel}' 항목은 유효한 URL이어야 합니다.";
            } elseif ($fieldType === 'url' && !empty($definition['schemes'])) {
                $scheme = strtolower((string) parse_url((string) $value, PHP_URL_SCHEME));
                if (!in_array($scheme, (array) $definition['schemes'], true)) {
                    $errors[] = "'{$fieldLabel}' 항목은 " . implode(', ', $definition['schemes']) . ' 형식만 허용합니다.';
                }
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }
}
