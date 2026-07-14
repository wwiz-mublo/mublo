<?php

namespace Mublo\Service\Block;

/**
 * 저장 전 권한 오류를 빠르게 반환하는 HTTP preflight 정책.
 *
 * BlockColumnPayloadNormalizer가 최종 방어선이며, 이 guard는 기존 API의
 * 오류 우선순위(include 후 raw JS)를 보존한다.
 */
final class BlockColumnWriteGuard
{
    public function firstViolation(array $columns, BlockColumnWriteContext $context): ?string
    {
        if (!$context->allowInclude && $this->containsInclude($columns)) {
            return 'Include 블록은 최고관리자만 사용할 수 있습니다.';
        }

        if (!$context->allowRawJs && $this->containsRawJs($columns)) {
            // 편집 신뢰 정책상 인터랙티브 경로는 항상 허용 — 이 분기는
            // raw JS 를 막는 특수 컨텍스트(외부 유입 등)에서만 도달한다.
            return '이 저장 경로에서는 직접 입력 JS를 사용할 수 없습니다.';
        }

        return null;
    }

    private function containsInclude(array $columns): bool
    {
        foreach ($columns as $column) {
            if (($column['content_type'] ?? '') === 'include') {
                return true;
            }
        }

        return false;
    }

    private function containsRawJs(array $columns): bool
    {
        foreach ($columns as $column) {
            $config = $column['content_config'] ?? [];
            if (is_array($config) && trim((string) ($config['js'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
