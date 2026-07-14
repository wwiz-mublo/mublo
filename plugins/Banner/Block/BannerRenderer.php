<?php
namespace Mublo\Plugin\Banner\Block;

use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Core\Block\Renderer\SkinRendererTrait;
use Mublo\Core\Event\Block\BlockContentFilterEvent;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Plugin\Banner\Service\BannerService;

/**
 * BannerRenderer
 *
 * 배너 블록 콘텐츠 렌더러
 *
 * content_items에서는 ID만 추출하고 현재 칼럼 도메인의 활성·기간 내 배너를
 * DB에서 재조회합니다. 레거시 객체 스냅샷의 표시 값은 신뢰하지 않습니다.
 * (레거시: ID 배열인 경우 DB 조회로 폴백)
 *
 * 스킨에 전달되는 변수:
 * - $titleConfig: 타이틀 설정 (SkinRendererTrait에서 추출)
 * - $titlePartial: 타이틀 파셜 경로
 * - $contentConfig: 콘텐츠 설정
 * - $column: BlockColumn 엔티티
 * - $items: 배너 배열 (title, pc_image_url, mo_image_url, link_url, link_target, extras)
 * - $config: content_config (effect, autoplay 등)
 */
class BannerRenderer implements RendererInterface
{
    use SkinRendererTrait;

    private BannerService $bannerService;
    private ?EventDispatcher $eventDispatcher;

    public function __construct(BannerService $bannerService, ?EventDispatcher $eventDispatcher = null)
    {
        $this->bannerService = $bannerService;
        $this->eventDispatcher = $eventDispatcher;
    }

    protected function getSkinType(): string
    {
        return 'banner';
    }

    /**
     * 플러그인 내부 스킨 경로
     */
    protected function getSkinBasePath(): string
    {
        return MUBLO_PLUGIN_PATH . '/Banner/views/Block/';
    }

    public function render(BlockColumn $column): string
    {
        $contentItems = $column->getContentItems() ?? [];

        if (empty($contentItems)) {
            return $this->renderEmpty($column, '표시할 배너가 없습니다.');
        }

        $items = $this->resolveItems($contentItems, $column->getDomainId());

        // 패키지 필터링 이벤트 발행
        if ($this->eventDispatcher && !empty($items)) {
            $filterEvent = new BlockContentFilterEvent('banner', $items);
            $this->eventDispatcher->dispatch($filterEvent);
            $items = $filterEvent->getItems();
        }

        if (empty($items)) {
            return $this->renderEmpty($column, '표시할 배너가 없습니다.');
        }

        $config = $column->getContentConfig() ?? [];
        $skin = $column->getContentSkin() ?: 'basic';

        return $this->renderSkin($column, $skin, [
            'items' => $items,
            'config' => $config,
        ]);
    }

    /**
     * 빈 상태 — 표시할 배너가 없어도 칸을 유지하고 점선 박스 안내(플러그인 소유).
     */
    private function renderEmpty(BlockColumn $column, string $message): string
    {
        if (!is_editor_preview()) {
            return '';
        }

        $skin = $column->getContentSkin() ?: 'basic';
        if ($this->assetManager) {
            $this->assetManager->addCss('/serve/plugin/Banner/views/Block/banner/' . $skin . '/style.css');
        }
        $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        return '<div class="block-banner-empty">'
             . '<i class="bi bi-inbox block-banner-empty__icon"></i>'
             . '<span>' . $msg . '</span></div>';
    }

    /**
     * content_items → 스킨용 배너 배열 변환
     *
     * ID 배열과 레거시 객체 배열 모두 ID만 추출한 뒤 현재 도메인 DB에서 다시 조회한다.
     * 저장된 title/image/link/extras 스냅샷은 렌더링에 사용하지 않는다.
     */
    private function resolveItems(array $contentItems, int $domainId): array
    {
        $first = reset($contentItems);

        // 레거시 객체 배열도 ID 추출만 허용한다.
        if (is_array($first) && isset($first['id'])) {
            $bannerIds = array_map(fn($item) => (int) $item['id'], $contentItems);
        } else {
            $bannerIds = array_map('intval', $contentItems);
        }

        return $this->bannerService->findByIds($domainId, $bannerIds);
    }
}
