<?php
declare(strict_types=1);

namespace Mublo\Contract\Site;

/** 자원 fingerprint에 사용하는 결정적 SHA-256 직렬화. */
final class SiteMutationFingerprint
{
    public static function hash(mixed $value): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_scalar($value) || $value === null) {
                return $value;
            }
            throw new \InvalidArgumentException('Site mutation fingerprints only support arrays and scalar JSON values.');
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
