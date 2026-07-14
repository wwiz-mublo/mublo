<?php
namespace Mublo\Helper\Directory;

/**
 * 디렉토리 스캔 헬퍼
 *
 * 범용 디렉토리 스캔 기능 제공
 * - 스킨 목록 조회 (테마, 게시판, 블록 등)
 * - 파일 목록 조회
 * - 디렉토리 존재 확인
 */
class DirectoryHelper
{
    /**
     * 캐시된 결과
     */
    private static array $cache = [];

    /**
     * 하위 디렉토리 목록 조회
     *
     * @param string $relativePath 상대 경로 (예: 'views/Front/Header')
     * @param string $default 디렉토리가 없을 때 기본값
     * @param bool $useCache 캐시 사용 여부
     * @return array 디렉토리명 목록 ['basic', 'modern', ...]
     */
    public static function getSubdirectories(
        string $relativePath,
        string $default = 'basic',
        bool $useCache = true
    ): array {
        $cacheKey = 'dirs:' . $relativePath;

        // 캐시 확인
        if ($useCache && isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $basePath = self::getBasePath();
        $fullPath = $basePath . '/' . ltrim($relativePath, '/');

        $result = self::scanDirectories($fullPath, $default);

        // 캐시 저장
        if ($useCache) {
            self::$cache[$cacheKey] = $result;
        }

        return $result;
    }

    /**
     * 하위 파일 목록 조회
     *
     * @param string $relativePath 상대 경로
     * @param string|array|null $extensions 필터링할 확장자 (예: 'php', ['php', 'html'])
     * @param bool $useCache 캐시 사용 여부
     * @return array 파일명 목록 ['index.php', 'config.php', ...]
     */
    public static function getFiles(
        string $relativePath,
        string|array|null $extensions = null,
        bool $useCache = true
    ): array {
        $cacheKey = 'files:' . $relativePath . ':' . json_encode($extensions);

        // 캐시 확인
        if ($useCache && isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $basePath = self::getBasePath();
        $fullPath = $basePath . '/' . ltrim($relativePath, '/');

        $result = self::scanFiles($fullPath, $extensions);

        // 캐시 저장
        if ($useCache) {
            self::$cache[$cacheKey] = $result;
        }

        return $result;
    }

    /**
     * 디렉토리 존재 확인
     *
     * @param string $relativePath 상대 경로
     * @return bool
     */
    public static function exists(string $relativePath): bool
    {
        $basePath = self::getBasePath();
        $fullPath = $basePath . '/' . ltrim($relativePath, '/');
        return is_dir($fullPath);
    }

    /**
     * 특정 디렉토리가 하위에 존재하는지 확인
     *
     * @param string $relativePath 상대 경로
     * @param string $subdir 확인할 하위 디렉토리명
     * @return bool
     */
    public static function hasSubdirectory(string $relativePath, string $subdir): bool
    {
        $subdirs = self::getSubdirectories($relativePath);
        return in_array($subdir, $subdirs, true);
    }

    /**
     * select 옵션용 배열 반환
     *
     * @param string $relativePath 상대 경로
     * @param string $default 기본값
     * @return array ['basic' => 'basic', 'modern' => 'modern', ...]
     */
    public static function getSelectOptions(string $relativePath, string $default = 'basic'): array
    {
        $dirs = self::getSubdirectories($relativePath, $default);
        return array_combine($dirs, $dirs);
    }

    /**
     * 캐시 초기화
     *
     * @param string|null $key 특정 키만 초기화 (null이면 전체)
     */
    public static function clearCache(?string $key = null): void
    {
        if ($key === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$key]);
        }
    }

    /**
     * 프로젝트 베이스 경로 반환
     */
    private static function getBasePath(): string
    {
        if (defined('MUBLO_ROOT_PATH')) {
            return MUBLO_ROOT_PATH;
        }
        return dirname(__DIR__, 3);
    }

    /**
     * 디렉토리 스캔
     */
    private static function scanDirectories(string $path, string $default): array
    {
        $dirs = [];

        if (!is_dir($path)) {
            return [$default];
        }

        try {
            $iterator = new \DirectoryIterator($path);

            foreach ($iterator as $item) {
                if (!$item->isDir() || $item->isDot()) {
                    continue;
                }

                $name = $item->getFilename();

                // 숨김 폴더, _ 시작 폴더 제외
                if ($name[0] === '.' || $name[0] === '_') {
                    continue;
                }

                $dirs[] = $name;
            }
        } catch (\Exception $e) {
            return [$default];
        }

        if (empty($dirs)) {
            return [$default];
        }

        // 정렬 (default가 맨 앞에 오도록)
        sort($dirs);
        if (($key = array_search($default, $dirs)) !== false) {
            unset($dirs[$key]);
            array_unshift($dirs, $default);
        }

        return array_values($dirs);
    }

    /**
     * 파일 스캔
     */
    private static function scanFiles(string $path, string|array|null $extensions): array
    {
        $files = [];

        if (!is_dir($path)) {
            return [];
        }

        // 확장자 배열로 정규화
        if (is_string($extensions)) {
            $extensions = [$extensions];
        }

        try {
            $iterator = new \DirectoryIterator($path);

            foreach ($iterator as $item) {
                if (!$item->isFile()) {
                    continue;
                }

                $name = $item->getFilename();

                // 숨김 파일 제외
                if ($name[0] === '.') {
                    continue;
                }

                // 확장자 필터링
                if ($extensions !== null) {
                    $ext = strtolower($item->getExtension());
                    if (!in_array($ext, $extensions, true)) {
                        continue;
                    }
                }

                $files[] = $name;
            }
        } catch (\Exception $e) {
            return [];
        }

        sort($files);
        return $files;
    }
}
