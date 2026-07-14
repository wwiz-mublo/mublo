<?php
namespace Mublo\Core\Extension;

/**
 * PluginHostTrait — PluginHostInterface 의 표준 규약 구현
 *
 * packages/{Pkg}/Plugins/{Name}/ 디렉토리 규약으로 플러그인을 발견한다.
 * 패키지 이름은 Provider 의 네임스페이스(Mublo\Packages\{Pkg}\...)에서 얻는다.
 */
trait PluginHostTrait
{
    /** @see PluginHostInterface::discoverPlugins() */
    public function discoverPlugins(): array
    {
        $namespace = explode('\\', static::class);
        // Mublo\Packages\{Pkg}\{Pkg}Provider
        $package = $namespace[2] ?? '';
        if ($package === '') {
            return [];
        }

        $base = MUBLO_PACKAGE_PATH . '/' . $package . '/' . NestedPlugin::SUBDIR;
        $plugins = [];

        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (!is_file($dir . '/manifest.json')) {
                continue;
            }
            $plugins[$name] = [
                'dir' => $dir,
                'providerClass' => "Mublo\\Packages\\{$package}\\" . NestedPlugin::SUBDIR . "\\{$name}\\{$name}Provider",
            ];
        }

        return $plugins;
    }
}
