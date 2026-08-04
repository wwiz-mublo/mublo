<?php
declare(strict_types=1);
namespace Mublo\Core\Block\Renderer;

use Mublo\Core\Rendering\FrontViewRuntime;
use Mublo\Core\Rendering\ViewContext;
use Mublo\Contract\Block\BlockColumnView;

/**
 * IncludeRenderer
 *
 * PHP 파일 포함 콘텐츠 렌더러
 *
 * 파일 위치: views/Block/include/ 디렉토리
 * content_config['file'] 또는 content_config['include_path']로 파일명 지정
 *
 * include 파일에서 사용 가능한 변수:
 * - $mublo: 모든 Front 스킨과 동일한 사이트/회원/요청/메뉴 계약
 * - $contentConfig: 블록 설정 배열
 * - $params: content_config['params'] 배열
 *
 * 보안:
 * - views/Block/include/ 내 파일만 포함 가능
 * - 상위 디렉토리 이동 (..) 차단
 * - .php 확장자만 허용
 */
class IncludeRenderer implements RendererInterface
{
    use SkinRendererTrait;

    /**
     * 허용된 include 기본 경로
     */
    private const ALLOWED_BASE_PATH = 'views/Block/include/';

    /**
     * 스킨 타입 반환
     */
    protected function getSkinType(): string
    {
        return 'include';
    }

    /**
     * {@inheritdoc}
     */
    public function render(BlockColumnView $column): string
    {
        $config = $column->getContentConfig() ?? [];
        $file = $config['file'] ?? $config['include_path'] ?? null;
        $params = $config['params'] ?? [];

        if (!$file) {
            return $this->renderEmptyContent('포함할 파일이 지정되지 않았습니다.');
        }

        // 파일명만 추출 (경로 포함 시 basename)
        $file = basename($file);

        // 보안 검증
        if (!$this->isAllowedFile($file)) {
            return $this->renderEmptyContent('허용되지 않은 파일입니다.');
        }

        $fullPath = $this->getIncludePath($file);

        if (!file_exists($fullPath)) {
            return $this->renderEmptyContent("파일을 찾을 수 없습니다: {$file}");
        }

        // realpath 검증 — 심볼릭 링크 우회 방지
        $realPath = realpath($fullPath);
        $allowedBase = realpath($this->getIncludeDir());
        if ($realPath === false || $allowedBase === false || !str_starts_with($realPath, $allowedBase)) {
            return $this->renderEmptyContent('허용되지 않은 경로입니다.');
        }

        // 파일 포함 및 출력 캡처
        $includeHtml = $this->includeFile($realPath, $params, $config);

        return $includeHtml;
    }

    /**
     * include 디렉토리 내 사용 가능한 파일 목록 반환
     *
     * @return string[] 파일명 배열
     */
    public static function getAvailableFiles(): array
    {
        $dir = dirname(__DIR__, 4) . '/' . self::ALLOWED_BASE_PATH;
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (!str_ends_with(strtolower($entry), '.php')) continue;
            if (is_file($dir . $entry)) {
                $files[] = $entry;
            }
        }

        sort($files);
        return $files;
    }

    /**
     * 파일명 허용 여부 검증
     */
    private function isAllowedFile(string $file): bool
    {
        if (str_contains($file, '..') || str_contains($file, '/') || str_contains($file, '\\')) {
            return false;
        }
        if (!str_ends_with(strtolower($file), '.php')) {
            return false;
        }
        return true;
    }

    /**
     * include 디렉토리 경로
     */
    private function getIncludeDir(): string
    {
        return dirname(__DIR__, 4) . '/' . self::ALLOWED_BASE_PATH;
    }

    /**
     * 전체 파일 경로 반환
     */
    private function getIncludePath(string $file): string
    {
        return $this->getIncludeDir() . $file;
    }

    /**
     * 파일 포함 및 출력 캡처
     *
     * 일반 스킨과 동일한 ViewContext/$mublo 계약으로 렌더링한다.
     */
    private function includeFile(string $path, array $params, array $contentConfig = []): string
    {
        $bufferLevel = ob_get_level();
        $data = [
            'params' => $params,
            'contentConfig' => $contentConfig,
        ];

        try {
            if ($this->frontViewRuntime?->isInitialized()) {
                return $this->frontViewRuntime->render($path, $data, true);
            }

            $viewContext = new ViewContext('front');
            if ($this->assetManager !== null) {
                $viewContext->setHelper('assets', $this->assetManager);
            }

            ob_start();
            $viewContext->render($path, [
                'mublo' => FrontViewRuntime::emptyMublo(true),
            ] + $data);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            error_log("Block include error [{$path}]: " . $e->getMessage());
            return '<!-- Include error -->';
        }
    }
}
