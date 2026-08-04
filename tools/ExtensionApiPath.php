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

    /**
     * 확장 경로를 baseline 소유권과 검사 제외 정보로 한 번에 분류한다.
     *
     * Package 안에 포함된 Plugin은 부모 Package와 같은 소비자 레인에서 변경되므로
     * 부모 Package가 baseline을 소유한다. 다만 중첩 Plugin의 tests 디렉터리는
     * 배포 운영 코드가 아니므로 일반 Package/Plugin 테스트와 동일하게 제외한다.
     *
     * @return array{
     *     owner: string,
     *     baselineFile: string,
     *     nestedPlugin: ?string,
     *     test: bool
     * }|null
     */
    public static function classify(string $relativePath): ?array
    {
        $path = ltrim(str_replace('\\', '/', $relativePath), '/');

        if (preg_match('#^packages/([^/]+)(?:/(.*))?$#', $path, $parts) === 1) {
            $package = $parts[1];
            $remainder = $parts[2] ?? '';
            $nestedPlugin = null;
            $isTest = preg_match('#^tests(?:/|$)#', $remainder) === 1;

            if (preg_match('#^Plugins/([^/]+)(?:/(.*))?$#', $remainder, $pluginParts) === 1) {
                $nestedPlugin = $pluginParts[1];
                $pluginRemainder = $pluginParts[2] ?? '';
                $isTest = preg_match('#^tests(?:/|$)#', $pluginRemainder) === 1;
            }

            $owner = 'package-' . $package;
            return [
                'owner' => $owner,
                'baselineFile' => $owner . '.json',
                'nestedPlugin' => $nestedPlugin,
                'test' => $isTest,
            ];
        }

        if (preg_match('#^plugins/([^/]+)(?:/(.*))?$#', $path, $parts) === 1) {
            $plugin = $parts[1];
            $remainder = $parts[2] ?? '';
            $owner = 'plugin-' . $plugin;

            return [
                'owner' => $owner,
                'baselineFile' => $owner . '.json',
                'nestedPlugin' => null,
                'test' => preg_match('#^tests(?:/|$)#', $remainder) === 1,
            ];
        }

        return null;
    }

    public static function ownerOf(string $relativePath): ?string
    {
        return self::classify($relativePath)['owner'] ?? null;
    }

    public static function isTestPath(string $relativePath): bool
    {
        return self::classify($relativePath)['test'] ?? false;
    }

    public static function baselineFileFor(string $relativePath): ?string
    {
        return self::classify($relativePath)['baselineFile'] ?? null;
    }

    public static function ownerDirectory(string $owner, string $basePath): ?string
    {
        if (str_starts_with($owner, 'package-')) {
            $name = substr($owner, strlen('package-'));
            return $name !== '' ? rtrim($basePath, '/\\') . '/packages/' . $name : null;
        }

        if (str_starts_with($owner, 'plugin-')) {
            $name = substr($owner, strlen('plugin-'));
            return $name !== '' ? rtrim($basePath, '/\\') . '/plugins/' . $name : null;
        }

        return null;
    }
}
