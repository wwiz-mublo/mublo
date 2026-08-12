<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Support;

final class CursorCodec
{
    public static function encode(int $sequence): string
    {
        return rtrim(strtr(base64_encode('v1:' . max(0, $sequence)), '+/', '-_'), '=');
    }

    public static function decode(string $cursor): ?int
    {
        if ($cursor === '') {
            return null;
        }

        $padding = (4 - strlen($cursor) % 4) % 4;
        $decoded = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', $padding), true);
        if (!is_string($decoded) || preg_match('/^v1:(0|[1-9][0-9]*)$/', $decoded) !== 1) {
            return null;
        }

        $value = substr($decoded, 3);
        if (strlen($value) > 18) {
            return null;
        }

        return (int) $value;
    }
}
