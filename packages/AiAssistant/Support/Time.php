<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Support;

final class Time
{
    public static function database(?int $timestamp = null): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp ?? time());
    }

    public static function api(?string $databaseValue): ?string
    {
        if ($databaseValue === null || $databaseValue === '') {
            return null;
        }

        $timestamp = strtotime($databaseValue . ' UTC');
        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
