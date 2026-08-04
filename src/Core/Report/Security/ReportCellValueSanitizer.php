<?php
declare(strict_types=1);

namespace Mublo\Core\Report\Security;

/**
 * Spreadsheet formula injection boundary for report cell values.
 *
 * CSV consumers such as Excel may evaluate strings whose first meaningful
 * character is =, +, -, or @. XLSX writers can mark those values explicitly
 * as strings; CSV has no type metadata, so it uses an apostrophe prefix.
 */
final class ReportCellValueSanitizer
{
    private const DANGEROUS_PREFIX_PATTERN =
        '/^(?:\xEF\xBB\xBF|[\x00-\x20\x7F]|\xC2\xA0)*[=+\-@]/';

    public static function isFormulaLike(mixed $value): bool
    {
        return is_string($value)
            && preg_match(self::DANGEROUS_PREFIX_PATTERN, $value) === 1;
    }

    public static function forCsv(mixed $value): mixed
    {
        return self::isFormulaLike($value) ? "'" . $value : $value;
    }

    /**
     * @param array<int,mixed> $row
     * @return array<int,mixed>
     */
    public static function csvRow(array $row): array
    {
        return array_map(self::forCsv(...), $row);
    }
}
