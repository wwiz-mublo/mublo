<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Support;

final class InputSetDigest
{
    /** @param list<string> $customerIds */
    public static function customers(array $customerIds): string
    {
        $ids = array_values(array_unique($customerIds));
        sort($ids, SORT_STRING);
        return hash('sha256', implode("\n", $ids));
    }

    /** @param list<array<string, mixed>> $rows */
    public static function interactions(array $rows): string
    {
        $items = array_map(
            static fn(array $row): string => (string) $row['interaction_id'] . ':' . (string) $row['content_sha256'],
            $rows
        );
        sort($items, SORT_STRING);
        return hash('sha256', implode("\n", $items));
    }
}
