<?php

namespace Mublo\Tools;

/** 운영체제별 구분자 차이를 제거해 검사 대상의 루트 상대 경로를 계산한다. */
final class ExtensionApiPath
{
    public static function relativeTo(string $path, string $basePath): string
    {
        $path = str_replace('\\', '/', $path);
        $basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        $prefix = $basePath . '/';

        $windowsPath = preg_match('#^[A-Za-z]:/#', $path) === 1
            || str_starts_with($path, '//');
        $matchesBase = $windowsPath
            ? str_starts_with(strtolower($path), strtolower($prefix))
            : str_starts_with($path, $prefix);

        return $matchesBase ? substr($path, strlen($prefix)) : $path;
    }
}
