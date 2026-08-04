<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Helper\Directory\DirectoryHelper;

/**
 * BlockSkinService
 *
 * 블록 스킨 관리 서비스
 * - 콘텐츠 타입별 스킨 목록 조회
 * - 스킨 경로 반환
 * - 스킨 유효성 검사
 *
 * Plugin/Package가 BlockRegistry에 skinBasePath를 등록하면
 * 해당 경로에서 스킨을 조회합니다.
 */
class BlockSkinService
{
    /**
     * 스킨 기본 경로 (상대 경로)
     */
    private const SKIN_BASE_PATH = 'views/Block';

    /**
     * 스킨이 필요 없는 콘텐츠 타입
     */
    private const NO_SKIN_TYPES = ['html', 'include'];

    /**
     * 콘텐츠 타입별 스킨 목록 조회
     *
     * BlockRegistry에 skinBasePath가 등록된 타입은 해당 절대 경로에서 스캔,
     * 미등록 타입은 기본 views/Block/{type}/ 경로에서 스캔
     *
     * @param string $contentType 콘텐츠 타입 (board, menu, outlogin, banner 등)
     * @param bool $includeSuperOnly SUPER 도메인만 참 — `super_` 스킨 관례
     *                               (ThemeSkinPolicy::isSuperOnly). 코어·
     *                               플러그인·패키지 스킨 경로에 똑같이 적용된다
     * @return array 스킨 목록 [{value: 'basic', label: 'basic'}, ...]
     */
    public function getSkinList(string $contentType, bool $includeSuperOnly = false): array
    {
        if (in_array($contentType, self::NO_SKIN_TYPES)) {
            return [];
        }

        // BlockRegistry에 커스텀 경로가 등록되어 있는지 확인
        $customBasePath = BlockRegistry::getSkinBasePath($contentType);

        if ($customBasePath !== null) {
            // 플러그인/패키지가 등록한 절대 경로에서 직접 스캔
            $directories = $this->scanSkinDirectories($customBasePath . '/' . $contentType, 'basic');
        } else {
            // 기본 경로: DirectoryHelper 활용 (캐시 + 정렬 지원)
            $relativePath = self::SKIN_BASE_PATH . '/' . $contentType;
            $directories = DirectoryHelper::getSubdirectories($relativePath, 'basic');
        }

        if (!$includeSuperOnly) {
            $directories = array_values(array_filter(
                $directories,
                static fn (string $dir): bool => !\Mublo\Core\Rendering\ThemeSkinPolicy::isSuperOnly($dir)
            ));
        }

        $skins = [];
        foreach ($directories as $dir) {
            if ($this->isValidSkin($contentType, $dir)) {
                $meta = $this->readSkinMeta($contentType, $dir);

                $skin = [
                    'value' => $dir,
                    'label' => is_string($meta['label'] ?? null) && $meta['label'] !== '' ? $meta['label'] : $dir,
                ];

                // 스킨 권장 1줄 출력갯수 — 편집기가 스킨 변경 시 자동 반영한다
                $rec = $meta['recommended_cols'] ?? null;
                if (is_array($rec)) {
                    $pc = (int) ($rec['pc'] ?? 0);
                    $mo = (int) ($rec['mo'] ?? 0);
                    if ($pc > 0 || $mo > 0) {
                        $skin['recommended_cols'] = array_filter(['pc' => $pc, 'mo' => $mo]);
                    }
                }

                $skins[] = $skin;
            }
        }

        return $skins;
    }

    /**
     * 스킨 메타 조회 — 스킨 폴더의 skin.json (선택 사항)
     *
     * 지원 키:
     *   label            string  스킨 표시명 (없으면 폴더명)
     *   recommended_cols {pc, mo} 권장 1줄 출력갯수 — 편집기에서 스킨 변경 시 자동 반영
     *
     * 파일이 없거나 파싱 실패 시 빈 배열 (기존 동작 유지).
     */
    private function readSkinMeta(string $contentType, string $skinName): array
    {
        $file = $this->getSkinBasePath($contentType) . '/' . $skinName . '/skin.json';
        if (!is_file($file)) {
            return [];
        }

        $meta = json_decode((string) file_get_contents($file), true);
        return is_array($meta) ? $meta : [];
    }

    /**
     * 모든 콘텐츠 타입의 스킨 목록 조회
     *
     * BlockRegistry에 등록된 모든 타입에서 스킨 목록을 조회
     *
     * @return array [contentType => [{value, label}, ...], ...]
     */
    public function getAllSkinLists(bool $includeSuperOnly = false): array
    {
        $contentTypes = BlockRegistry::getContentTypes();
        $result = [];

        foreach ($contentTypes as $type => $info) {
            $skinList = $this->getSkinList($type, $includeSuperOnly);
            if (!empty($skinList)) {
                $result[$type] = $skinList;
            }
        }

        return $result;
    }

    /**
     * 스킨 유효성 검사
     *
     * @param string $contentType 콘텐츠 타입
     * @param string $skinName 스킨명
     * @return bool
     */
    public function isValidSkin(string $contentType, string $skinName): bool
    {
        if (in_array($contentType, self::NO_SKIN_TYPES)) {
            return true;
        }

        $skinFile = $this->getSkinFilePath($contentType, $skinName);
        return file_exists($skinFile);
    }

    /**
     * 스킨 기본 경로 반환 (절대 경로)
     *
     * BlockRegistry에 skinBasePath가 등록된 타입은 해당 경로 사용,
     * 미등록 타입은 기본 views/Block/{type} 경로 사용
     *
     * @param string $contentType 콘텐츠 타입
     * @return string 스킨 디렉토리 절대 경로
     */
    public function getSkinBasePath(string $contentType): string
    {
        $customBasePath = BlockRegistry::getSkinBasePath($contentType);

        if ($customBasePath !== null) {
            return $customBasePath . '/' . $contentType;
        }

        return $this->getBasePath() . '/' . self::SKIN_BASE_PATH . '/' . $contentType;
    }

    /**
     * 프로젝트 베이스 경로 반환
     */
    private function getBasePath(): string
    {
        if (defined('MUBLO_ROOT_PATH')) {
            return MUBLO_ROOT_PATH;
        }
        return dirname(__DIR__, 3);
    }

    /**
     * 스킨 파일 경로 반환
     *
     * @param string $contentType 콘텐츠 타입
     * @param string $skinName 스킨명
     * @return string 스킨 PHP 파일 경로
     */
    public function getSkinFilePath(string $contentType, string $skinName): string
    {
        return $this->getSkinBasePath($contentType) . '/' . $skinName . '/' . $skinName . '.php';
    }

    /**
     * 스킨 CSS 경로 반환 (웹 경로)
     *
     * @param string $contentType 콘텐츠 타입
     * @param string $skinName 스킨명
     * @return string|null CSS 웹 경로 (없으면 null)
     */
    public function getSkinCssPath(string $contentType, string $skinName): ?string
    {
        $cssFile = $this->getSkinBasePath($contentType) . '/' . $skinName . '/style.css';

        if (file_exists($cssFile)) {
            return '/' . self::SKIN_BASE_PATH . '/' . $contentType . '/' . $skinName . '/style.css';
        }

        return null;
    }

    /**
     * 스킨 JS 경로 반환 (웹 경로)
     *
     * @param string $contentType 콘텐츠 타입
     * @param string $skinName 스킨명
     * @return string|null JS 웹 경로 (없으면 null)
     */
    public function getSkinJsPath(string $contentType, string $skinName): ?string
    {
        $jsFile = $this->getSkinBasePath($contentType) . '/' . $skinName . '/script.js';

        if (file_exists($jsFile)) {
            return '/' . self::SKIN_BASE_PATH . '/' . $contentType . '/' . $skinName . '/script.js';
        }

        return null;
    }

    /**
     * 스킨 정보 반환
     *
     * @param string $contentType 콘텐츠 타입
     * @param string $skinName 스킨명
     * @return array|null 스킨 정보 (없으면 null)
     */
    public function getSkinInfo(string $contentType, string $skinName): ?array
    {
        if (!$this->isValidSkin($contentType, $skinName)) {
            return null;
        }

        return [
            'name' => $skinName,
            'content_type' => $contentType,
            'file_path' => $this->getSkinFilePath($contentType, $skinName),
            'css_path' => $this->getSkinCssPath($contentType, $skinName),
            'js_path' => $this->getSkinJsPath($contentType, $skinName),
            'skin_path' => '/' . self::SKIN_BASE_PATH . '/' . $contentType . '/' . $skinName
        ];
    }

    /**
     * 기본 스킨명 반환
     *
     * @param string $contentType 콘텐츠 타입
     * @return string 기본 스킨명
     */
    public function getDefaultSkinName(string $contentType): string
    {
        return 'basic';
    }

    /**
     * 스킨이 필요한 콘텐츠 타입인지 확인
     *
     * @param string $contentType 콘텐츠 타입
     * @return bool
     */
    public function requiresSkin(string $contentType): bool
    {
        return !in_array($contentType, self::NO_SKIN_TYPES);
    }

    /**
     * 절대 경로 디렉토리 스캔
     *
     * Plugin/Package가 등록한 커스텀 스킨 경로를 스캔할 때 사용
     * DirectoryHelper와 동일한 로직이지만 절대 경로를 직접 받음
     *
     * @param string $absolutePath 절대 경로
     * @param string $default 디렉토리가 없을 때 기본값
     * @return array 디렉토리명 목록
     */
    private function scanSkinDirectories(string $absolutePath, string $default = 'basic'): array
    {
        if (!is_dir($absolutePath)) {
            return [$default];
        }

        $dirs = [];

        try {
            $iterator = new \DirectoryIterator($absolutePath);

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
}
