<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

/**
 * 단일 칸과 스택 칸 payload를 같은 규칙으로 횡단하는 도우미.
 *
 * stack의 scalar 콘텐츠 필드는 호환용 미러일 뿐이며 contents가 실제 원본이다.
 * 보안·호환성 판정은 contentNodes(), 레이아웃 필드까지 바꾸는 변환은 mapAll()을 쓴다.
 */
final class BlockContentTree
{
    /**
     * 칸에서 실제로 저장될 콘텐츠 노드를 반환한다.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public static function contentNodes(array $column): iterable
    {
        if (($column['content_mode'] ?? null) === 'stack' && is_array($column['contents'] ?? null)) {
            foreach ($column['contents'] as $content) {
                if (is_array($content)) {
                    yield from self::contentNodes($content);
                }
            }
            return;
        }

        yield $column;
    }

    /**
     * 칸 자체와 모든 하위 콘텐츠를 반환한다.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public static function allNodes(array $column): iterable
    {
        yield $column;

        if (!is_array($column['contents'] ?? null)) {
            return;
        }

        foreach ($column['contents'] as $content) {
            if (is_array($content)) {
                yield from self::allNodes($content);
            }
        }
    }

    /**
     * 칸 자체와 모든 하위 콘텐츠에 같은 변환을 적용한다.
     * 배열이 아닌 잘못된 contents 항목은 normalizer가 오류를 내도록 그대로 보존한다.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $mapper
     * @return array<string, mixed>
     */
    public static function mapAll(array $column, callable $mapper): array
    {
        if (is_array($column['contents'] ?? null)) {
            foreach ($column['contents'] as $index => $content) {
                if (is_array($content)) {
                    $column['contents'][$index] = self::mapAll($content, $mapper);
                }
            }
        }

        return $mapper($column);
    }
}
