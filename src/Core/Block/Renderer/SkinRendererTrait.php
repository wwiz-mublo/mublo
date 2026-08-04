<?php
declare(strict_types=1);
namespace Mublo\Core\Block\Renderer;

use Mublo\Core\Rendering\AssetManager;
use Mublo\Core\Rendering\FrontViewRuntime;
use Mublo\Core\Rendering\ViewContext;
use Mublo\Contract\Block\BlockColumnView;
use Mublo\Core\Block\BlockRegistry;

/**
 * SkinRendererTrait
 *
 * 블록 렌더러의 스킨 기반 렌더링 공통 기능
 *
 * 스킨에 전달되는 표준 변수:
 * - $titleConfig: 타이틀 설정 (show, text, position, color, size, more_url, copytext 등)
 * - $titlePartial: 타이틀 파셜 경로 (스킨 오버라이드 또는 공유 파셜)
 * - $contentConfig: 콘텐츠 설정 (렌더러별 다름)
 * - $column: BlockColumnView (렌더러가 읽을 수 있는 칸 표면)
 * - $assets: AssetManager (CSS/JS 수집, null이면 미사용)
 * - 각 렌더러별 추가 데이터
 */
trait SkinRendererTrait
{
    public ?AssetManager $assetManager = null;
    public ?FrontViewRuntime $frontViewRuntime = null;
    /**
     * 스킨 타입 (하위 클래스에서 정의)
     * 예: 'board', 'image', 'menu'
     */
    abstract protected function getSkinType(): string;

    /**
     * 스킨으로 렌더링
     *
     * @param BlockColumnView $column 렌더러가 읽을 수 있는 칸 표면
     * @param string $skin 스킨명 (basic, gallery 등)
     * @param array $data 스킨에 전달할 추가 데이터
     * @return string 렌더링된 HTML
     */
    protected function renderSkin(BlockColumnView $column, string $skin, array $data = []): string
    {
        $skinPath = $this->getSkinPath($skin);

        if (!is_file($skinPath)) {
            return $this->renderSkinNotFound($skin);
        }

        // 타이틀 설정 추출
        $titleConfig = $this->extractTitleConfig($column);

        // 타이틀 파셜 경로 결정 (스킨 오버라이드 우선)
        $titlePartial = $this->resolveTitlePartial($skin);

        // 스킨에 전달할 데이터 준비
        $skinData = array_merge([
            'column' => $column,
            'titleConfig' => $titleConfig,
            'titlePartial' => $titlePartial,
            'contentConfig' => $column->getContentConfig() ?? [],
            'skinDir' => dirname($skinPath),
            'assets' => $this->assetManager,
        ], $data);

        $viewerAware = BlockRegistry::isNoCache($this->getSkinType());

        if ($this->frontViewRuntime?->isInitialized()) {
            return $this->frontViewRuntime->render($skinPath, $skinData, $viewerAware);
        }

        // 관리자 미리보기처럼 Front 파이프라인 밖에서 렌더할 때도 같은 계약을 제공한다.
        $viewContext = new ViewContext('front');
        if ($this->assetManager !== null) {
            $viewContext->setHelper('assets', $this->assetManager);
        }

        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $viewContext->render($skinPath, [
                'mublo' => FrontViewRuntime::emptyMublo($viewerAware),
            ] + $skinData);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    /**
     * 스킨 기본 경로 반환
     *
     * 기본값: views/Block/ (Core 블록)
     * Plugin 렌더러에서 오버라이드하여 플러그인 내부 경로 지정 가능
     *
     * 예: return MUBLO_PLUGIN_PATH . '/Banner/views/Block/';
     */
    protected function getSkinBasePath(): string
    {
        return MUBLO_VIEW_PATH . '/Block/';
    }

    /**
     * 스킨 경로 반환
     */
    protected function getSkinPath(string $skin): string
    {
        $type = $this->getSkinType();
        return $this->getSkinBasePath() . $type . '/' . $skin . '/' . $skin . '.php';
    }

    /**
     * 타이틀 파셜 경로 결정
     *
     * 1. 스킨 디렉토리에 title.php가 있으면 사용 (오버라이드)
     * 2. 없으면 공유 파셜 사용
     */
    protected function resolveTitlePartial(string $skin): string
    {
        $type = $this->getSkinType();

        // 1. 스킨 오버라이드 (플러그인 or Core)
        $skinTitle = $this->getSkinBasePath() . $type . '/' . $skin . '/title.php';
        if (is_file($skinTitle)) {
            return $skinTitle;
        }

        // 2. 공유 파셜: views/Block/_shared/title.php (항상 Core)
        return MUBLO_VIEW_PATH . '/Block/_shared/title.php';
    }

    /**
     * 스킨을 찾을 수 없을 때 렌더링
     */
    protected function renderSkinNotFound(string $skin): string
    {
        $type = $this->getSkinType();
        if (!is_editor_preview()) {
            error_log("Block skin not found: {$type}/{$skin}");
            return '';
        }

        return <<<HTML
<!-- block: skin not found ({$type}/{$skin}) -->
<div class="block-placeholder block-placeholder--error">
    <i class="bi bi-exclamation-triangle block-placeholder__icon"></i>
    <span>스킨을 찾을 수 없습니다.</span>
</div>
HTML;
    }

    /**
     * 타이틀 설정 추출
     *
     * 투영 자체는 칸이 소유한다(BlockColumnView::toTitleView). 그래야 렌더러 계약이
     * 제목 게터 16개를 떠안지 않는다. 여기는 호출 지점을 유지하기 위한 얇은 위임이다.
     */
    protected function extractTitleConfig(BlockColumnView $column): array
    {
        return $column->toTitleView();
    }

    /**
     * 빈 콘텐츠 렌더링
     */
    protected function renderEmptyContent(string $message = '등록된 콘텐츠가 없습니다.'): string
    {
        if (!is_editor_preview()) {
            return '';
        }

        return <<<HTML
<div class="block-empty">
    <p>{$message}</p>
</div>
HTML;
    }
}
