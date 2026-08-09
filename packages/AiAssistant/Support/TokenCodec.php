<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Support;

final class TokenCodec
{
    public static function generate(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
