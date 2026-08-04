<?php
declare(strict_types=1);
namespace Mublo\Service\Block;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Enum\Block\BlockPosition;
use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Entity\Block\BlockRow;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockColumnContentRepository;
use Mublo\Repository\Menu\MenuItemRepository;
use Mublo\Core\Rendering\AssetManager;
use Mublo\Core\Rendering\FrontViewRuntime;
use Mublo\Infrastructure\Cache\CacheInterface;

/**
 * BlockRenderService
 *
 * 블록 프론트 렌더링 서비스
 *
 * 역할:
 * - 위치/페이지별 블록 렌더링
 * - 2단계 캐싱 (행 목록 + 행 콘텐츠 분리)
 * - DI 컨테이너를 통한 Renderer 인스턴스 관리
 * - 에러 핸들링 및 로깅
 *
 * 캐시 전략:
 * - 행 목록 캐시: block:ids:pos:{domainId}:{position} → row_id 배열
 * - 행 콘텐츠 캐시: block:row:{rowId} → 개별 행 HTML
 * - 행 1개 수정 시 해당 행만 재렌더링, 같은 위치의 다른 행은 캐시 유지
 */
class BlockRenderService
{
    private BlockRowRepository $rowRepository;
    private BlockColumnRepository $columnRepository;
    private ?BlockColumnContentRepository $contentRepository;
    private CacheInterface $cache;
    private DependencyContainer $container;

    /**
     * 캐시 TTL (기본 1시간)
     */
    private const CACHE_TTL = 3600;

    /**
     * 캐시 프리픽스
     */
    private const CACHE_PREFIX = 'block:';

    /**
     * 로드된 렌더러 인스턴스
     */
    private array $renderers = [];

    /**
     * 요청 단위 메뉴 스코프 목록 메모 (domainId => string[])
     *
     * 벌크 캐시 갱신은 행마다 무효화를 돌리므로, 메모 없이는 같은 메뉴 목록 쿼리가
     * 행 수만큼 반복된다.
     */
    private array $menuScopeCache = [];

    /**
     * 디버그 모드 여부
     */
    private bool $isDebug;

    public function __construct(
        BlockRowRepository $rowRepository,
        BlockColumnRepository $columnRepository,
        CacheInterface $cache,
        DependencyContainer $container,
        ?BlockColumnContentRepository $contentRepository = null
    ) {
        $this->rowRepository = $rowRepository;
        $this->columnRepository = $columnRepository;
        $this->cache = $cache;
        $this->container = $container;
        $this->contentRepository = $contentRepository;
        $this->isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    }

    /**
     * 스택 칸의 하위 콘텐츠 배치 preload (계획 7.4 — 칸별 개별 조회 N+1 금지).
     *
     * 공개 렌더링이므로 활성 콘텐츠만 싣는다 (계획 6.2.2).
     *
     * @param BlockColumn[] $columns
     */
    private function preloadStackContents(array $columns, int $domainId): void
    {
        if ($this->contentRepository === null) {
            return;
        }

        $stackColumnIds = [];
        foreach ($columns as $column) {
            if ($column->isStack() && $column->getContents() === []) {
                $stackColumnIds[] = $column->getColumnId();
            }
        }

        if ($stackColumnIds === []) {
            return;
        }

        try {
            $grouped = $this->contentRepository->findByColumnsForDomain($stackColumnIds, $domainId);
        } catch (\Throwable $e) {
            $this->logError('Stack contents preload failed', ['error' => $e->getMessage()]);
            return;
        }

        foreach ($columns as $column) {
            if ($column->isStack() && isset($grouped[$column->getColumnId()])) {
                $column->setContents($grouped[$column->getColumnId()]);
            }
        }
    }

    // ========================================
    // 위치 기반 렌더링
    // ========================================

    /**
     * 위치별 블록 렌더링
     *
     * 2단계 캐시:
     * 1. 행 목록 캐시 (row_id 배열) → DB 조회 절약
     * 2. 행별 콘텐츠 캐시 (HTML) → 개별 행만 재렌더링
     *
     * @param int $domainId 도메인 ID
     * @param string $position 위치 (index, left, right, etc.)
     * @param string|null $menuCode 메뉴 코드 (특정 메뉴용)
     * @param bool $useCache 캐시 사용 여부
     * @return string 렌더링된 HTML
     */
    public function renderPosition(
        int $domainId,
        string $position,
        ?string $menuCode = null,
        bool $useCache = true,
        bool $isMainScreen = false
    ): string {
        // 디버그 모드에서는 캐시 비활성화
        if ($this->isDebug) {
            $useCache = false;
        }

        $debug = '';
        if ($this->isDebug) {
            $debug = "<!-- [Block] renderPosition: domain={$domainId}, position={$position}, menu={$menuCode}, main=" . ($isMainScreen ? '1' : '0') . " -->\n";
        }

        // 1단계: 행 목록 캐시에서 row 조회
        $rows = $this->getRowsForPosition($domainId, $position, $menuCode, $useCache, $isMainScreen);

        if ($this->isDebug) {
            $debug .= '<!-- [Block] rows found: ' . count($rows) . " -->\n";
        }

        // 2단계: 각 행을 개별 캐시에서 렌더링
        $html = $this->renderRowsWithCache($rows, $useCache);

        // topbar "보지 않기" — dismissible 행이 있으면 쿠키 숨김 인라인 자산 추가
        // (topbar 는 헤더 위 최상단에서 렌더되므로, 직후 동기 실행돼 깜빡임을 최소화)
        if ($position === BlockPosition::TOPBAR->value && str_contains($html, 'block-topbar-dismissible')) {
            $html .= $this->renderTopbarDismissAssets();
        }

        return $debug . $html;
    }

    /**
     * topbar "보지 않기" 인라인 자산 (버튼 스타일 + 쿠키 숨김/클릭 JS).
     * 쿠키 mublo_topbar_hide_{rowId} 가 있으면 즉시 숨기고, 버튼 클릭 시 지정 시간만큼 쿠키 설정.
     */
    private function renderTopbarDismissAssets(): string
    {
        return <<<'HTML'

<style>
.block-topbar-dismissible{position:relative;}
.block-topbar-dismiss{position:absolute;top:50%;right:12px;transform:translateY(-50%);z-index:5;padding:1px 8px;border:1px solid rgba(255,255,255,.4);border-radius:999px;background:rgba(0,0,0,.22);color:#fff;font-size:10px;font-weight:400;line-height:1.4;letter-spacing:-.2px;cursor:pointer;white-space:nowrap;opacity:.8;transition:opacity .15s,background-color .15s;}
.block-topbar-dismiss:hover{opacity:1;background:rgba(0,0,0,.34);}
</style>
<script>
(function(){
  function gc(n){var m=document.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]*)'));return m?m[1]:null;}
  function sc(n,h){var d=new Date();d.setTime(d.getTime()+h*3600000);document.cookie=n+'=1; expires='+d.toUTCString()+'; path=/; SameSite=Lax';}
  document.querySelectorAll('.block-topbar-dismissible').forEach(function(el){
    var key='mublo_topbar_hide_'+el.getAttribute('data-dismiss-key');
    if(gc(key)){el.style.display='none';return;}
    var btn=el.querySelector('.block-topbar-dismiss');
    if(btn){btn.addEventListener('click',function(){
      var h=parseInt(el.getAttribute('data-dismiss-hours'),10)||24;
      sc(key,h);el.style.display='none';
    });}
  });
})();
</script>
HTML;
    }

    /**
     * 위치별 행 목록 캐시 무효화
     */
    public function invalidatePositionListCache(int $domainId, string $position, ?string $menuCode = null, bool $isMainScreen = false): void
    {
        $cacheKey = $this->getPositionListCacheKey($domainId, $position, $menuCode, $isMainScreen);
        $this->cache->delete($cacheKey);
    }

    // ========================================
    // 페이지 기반 렌더링
    // ========================================

    /**
     * 페이지별 블록 렌더링
     *
     * @param int $pageId 페이지 ID
     * @param bool $useCache 캐시 사용 여부
     * @return string 렌더링된 HTML
     */
    public function renderPage(int $pageId, bool $useCache = true): string
    {
        // 디버그 모드에서는 캐시 비활성화
        if ($this->isDebug) {
            $useCache = false;
        }

        // 1단계: 행 목록 캐시에서 row 조회
        $rows = $this->getRowsForPage($pageId, $useCache);

        // 2단계: 각 행을 개별 캐시에서 렌더링
        return $this->renderRowsWithCache($rows, $useCache);
    }

    /**
     * 페이지별 행 목록 캐시 무효화
     */
    public function invalidatePageListCache(int $pageId): void
    {
        $cacheKey = $this->getPageListCacheKey($pageId);
        $this->cache->delete($cacheKey);
    }

    // ========================================
    // 행 렌더링
    // ========================================

    /**
     * 단일 행 렌더링
     *
     * 캐시 시 HTML과 에셋(CSS/JS) 경로를 함께 저장하여
     * 캐시 히트 시에도 스킨 CSS/JS가 정상 등록됩니다.
     *
     * @param BlockRow $row 행 엔티티
     * @param bool $useCache 캐시 사용 여부
     * @return string 렌더링된 HTML
     */
    public function renderRow(BlockRow $row, bool $useCache = true): string
    {
        if (!$row->isActive()) {
            return '';
        }

        $cacheKey = $this->getRowCacheKey($row->getRowId());
        $assets = $this->getAssetManager();

        // 캐시 히트: HTML 반환 + 에셋 재등록
        if ($useCache) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                // 레거시 호환: 문자열이면 HTML만 있는 구 캐시
                if (is_string($cached)) {
                    return $cached;
                }

                // 에셋 재등록
                if ($assets && is_array($cached)) {
                    foreach ($cached['c'] ?? [] as $css) {
                        $assets->addCss($css);
                    }
                    foreach ($cached['j'] ?? [] as $js) {
                        $assets->addJs($js);
                    }
                }

                return $cached['h'] ?? '';
            }
        }

        // 이 행이 렌더 중 등록한 에셋을 캡처 — dedup 무관하게 "필요로 하는" 전체를 기록한다.
        // (array_diff 방식은 공유 CSS를 먼저 등록한 다른 행에 뺏겨 c=[]로 캐시되는 버그가 있었음)
        $assets?->beginCapture();

        // buildRowHtml 내부에서 noCache 콘텐츠 감지 시 플래그 설정
        $this->_rowHasNoCache = false;
        try {
            $html = $this->buildRowHtml($row);
        } finally {
            $captured = $assets ? $assets->endCapture() : ['css' => [], 'js' => []];
        }

        if ($useCache && !$this->_rowHasNoCache && !empty($html)) {
            $this->cache->set($cacheKey, [
                'h' => $html,
                'c' => $captured['css'],
                'j' => $captured['js'],
            ], self::CACHE_TTL);
            $this->rememberRowCacheVariant($row->getRowId());
        }

        return $html;
    }

    /**
     * Entity 기반 행 렌더링 (미리보기용)
     *
     * DB 조회 없이 전달받은 Row/Column Entity로 직접 렌더링.
     * 캐시 미사용, 디버그 주석 미출력.
     *
     * @param BlockRow $row 행 엔티티
     * @param BlockColumn[] $columns 칸 엔티티 배열
     * @return string 렌더링된 HTML
     */
    public function renderRowFromEntities(BlockRow $row, array $columns): string
    {
        if (empty($columns)) {
            return '';
        }

        $previousPlaceholderMode = $this->forceEditorPlaceholders;
        $this->forceEditorPlaceholders = true;

        try {
            $rowAttributes = $this->buildRowAttributes($row);
            $rowStyle = $this->buildRowStyle($row);
            $columnsHtml = $this->buildColumnsHtml($row, $columns);

            if (trim($columnsHtml) === '') {
                return '';
            }

            // 기본(최대넓이)은 .block-container 자체가 담당, 와이드일 때만 modifier 추가
            $containerClass = $row->isWide() ? ' block-container--wide' : '';
            $rowId = 'br-' . $row->getRowId();
            $rowSpacingStyleBlock = $this->buildRowSpacingStyleBlock($row, $rowId);
            $dismissBtn = $this->buildDismissButton($row);

            return $rowSpacingStyleBlock . <<<HTML
<section{$rowAttributes}>
    <div class="block-container{$containerClass}">
        <div class="block-row" id="{$rowId}" style="{$rowStyle}">
            {$columnsHtml}
        </div>
    </div>{$dismissBtn}
</section>
HTML;
        } catch (\Throwable $e) {
            $this->logError('Preview render failed', [
                'error' => $e->getMessage(),
            ]);
            return '';
        } finally {
            $this->forceEditorPlaceholders = $previousPlaceholderMode;
        }
    }

    /**
     * AssetManager 인스턴스 반환
     */
    private function getAssetManager(): ?AssetManager
    {
        try {
            return $this->container->get(AssetManager::class);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 행 콘텐츠 캐시 무효화
     */
    public function invalidateRowCache(int $rowId): void
    {
        $this->cache->delete(self::CACHE_PREFIX . "row:{$rowId}");

        $variantIndexKey = self::CACHE_PREFIX . "row-variants:{$rowId}";
        $variants = $this->cache->get($variantIndexKey);
        if (is_array($variants)) {
            foreach ($variants as $variant) {
                if (is_string($variant) && $variant !== '') {
                    $this->cache->delete(self::CACHE_PREFIX . "row:{$rowId}:{$variant}");
                }
            }
        }
        $this->cache->delete($variantIndexKey);
    }

    /**
     * 행 목록을 개별 캐시를 활용하여 렌더링
     *
     * @param BlockRow[] $rows 행 목록
     * @param bool $useCache 캐시 사용 여부
     * @return string 렌더링된 HTML
     */
    private function renderRowsWithCache(array $rows, bool $useCache): string
    {
        // 콜드 캐시 N+1 방지 — 캐시 미스 행들의 칸을 IN 절 한 번으로 프리로드
        $this->preloadColumnsForColdRows($rows, $useCache);

        $output = '';

        foreach ($rows as $row) {
            if (!$row->isActive()) {
                continue;
            }

            $output .= $this->renderRow($row, $useCache);
        }

        return $output;
    }

    /** buildRowHtml 이 소비하는 칸 프리로드 (row_id => BlockColumn[]) */
    private array $preloadedColumns = [];

    /**
     * 캐시 미스가 확정된 행들의 칸을 일괄 조회해 둔다.
     *
     * 웜 캐시 행은 칸 조회 자체가 없으므로 대상에서 제외. 미스 행이 1개면
     * 개별 조회와 동일하므로 프리로드하지 않는다. 캐시 get 이 여기서 한 번,
     * renderRow 에서 한 번 더 일어나지만(2회) SQL N회 대비 무시 가능한 비용.
     */
    private function preloadColumnsForColdRows(array $rows, bool $useCache): void
    {
        $coldIds = [];
        $domainId = null;

        foreach ($rows as $row) {
            if (!$row->isActive()) {
                continue;
            }
            if ($useCache && $this->cache->get($this->getRowCacheKey($row->getRowId())) !== null) {
                continue;
            }
            $coldIds[] = $row->getRowId();
            $domainId ??= $row->getDomainId();
        }

        if (count($coldIds) < 2 || $domainId === null) {
            return;
        }

        try {
            $this->preloadedColumns = $this->columnRepository->findByRowsForDomain($coldIds, $domainId)
                + $this->preloadedColumns;
        } catch (\Throwable $e) {
            // 프리로드 실패는 개별 조회 폴백으로 흡수
        }
    }

    /**
     * 위치별 행 목록 조회 (캐시 적용)
     *
     * @return BlockRow[]
     */
    private function getRowsForPosition(int $domainId, string $position, ?string $menuCode, bool $useCache, bool $isMainScreen = false): array
    {
        if (!$useCache) {
            return $this->rowRepository->findByPosition($domainId, $position, $menuCode, $isMainScreen);
        }

        $cacheKey = $this->getPositionListCacheKey($domainId, $position, $menuCode, $isMainScreen);
        $cachedIds = $this->cache->get($cacheKey);

        if ($cachedIds !== null && is_array($cachedIds)) {
            // 캐시된 ID로 행 조회
            return $this->getRowsByIdsForDomain($cachedIds, $domainId);
        }

        // DB 조회
        $rows = $this->rowRepository->findByPosition($domainId, $position, $menuCode, $isMainScreen);

        // row_id 배열 캐시
        $rowIds = array_map(fn(BlockRow $row) => $row->getRowId(), $rows);
        $this->cache->set($cacheKey, $rowIds, self::CACHE_TTL);

        return $rows;
    }

    /**
     * 페이지별 행 목록 조회 (캐시 적용)
     *
     * @return BlockRow[]
     */
    private function getRowsForPage(int $pageId, bool $useCache): array
    {
        if (!$useCache) {
            return $this->rowRepository->findByPage($pageId);
        }

        $cacheKey = $this->getPageListCacheKey($pageId);
        $cachedIds = $this->cache->get($cacheKey);

        if ($cachedIds !== null && is_array($cachedIds)) {
            return $this->rowRepository->findByIdsForPage($pageId, $cachedIds);
        }

        // DB 조회
        $rows = $this->rowRepository->findByPage($pageId);

        // row_id 배열 캐시
        $rowIds = array_map(fn(BlockRow $row) => $row->getRowId(), $rows);
        $this->cache->set($cacheKey, $rowIds, self::CACHE_TTL);

        return $rows;
    }

    /**
     * ID 배열로 행 엔티티 조회
     *
     * @param int[] $rowIds
     * @return BlockRow[]
     */
    private function getRowsByIdsForDomain(array $rowIds, int $domainId): array
    {
        return $this->rowRepository->findByIdsForDomain($domainId, $rowIds);
    }

    /**
     * 행 HTML 빌드
     */
    /**
     * buildRowHtml 실행 중 noCache 콘텐츠 감지 플래그
     */
    private bool $_rowHasNoCache = false;

    /** 관리자 엔티티 미리보기처럼 쿼리 플래그가 없는 렌더에서도 안내를 표시한다. */
    private bool $forceEditorPlaceholders = false;

    /**
     * 캐시 키 변형 — 동일 rowId라도 컨텍스트(브랜드샵 등)가 다른 경우 분리
     * BrandContextSubscriber 등에서 setSiteOverride 이후 setCacheVariant()로 설정
     */
    private string $cacheVariant = '';

    public function setCacheVariant(string $variant): void
    {
        $this->cacheVariant = $variant;
    }

    private function buildRowHtml(BlockRow $row): string
    {
        $rowId = $row->getRowId();
        $debug = $this->isDebug ? "<!-- [Block] buildRowHtml: row_id={$rowId} -->\n" : '';

        try {
            // 프리로드 분(one-shot 소비 — 이후 재렌더는 최신 조회) 우선, 없으면 개별 조회
            if (isset($this->preloadedColumns[$rowId])) {
                $columns = $this->preloadedColumns[$rowId];
                unset($this->preloadedColumns[$rowId]);
            } else {
                $columns = $this->columnRepository->findByRowForDomain($rowId, $row->getDomainId());
            }

            // 스택 칸 하위 콘텐츠 배치 preload (행 단위 1회 — N+1 금지, 계획 7.4)
            $this->preloadStackContents($columns, $row->getDomainId());

            // noCache 콘텐츠 감지 (로그인 위젯 등 사용자 상태 의존)
            // 스택 칸은 하위 콘텐츠 중 하나라도 noCache 면 행 전체 no-cache (계획 7.4)
            foreach ($columns as $column) {
                if (!$column->isActive()) {
                    continue;
                }

                if ($column->isStack()) {
                    foreach ($column->getContents() as $content) {
                        $typeStr = $content->getContentTypeString();
                        if ($typeStr && $content->isActive() && BlockRegistry::isNoCache($typeStr)) {
                            $this->_rowHasNoCache = true;
                            break 2;
                        }
                    }
                    continue;
                }

                $typeStr = $column->getContentTypeString();
                if ($typeStr && BlockRegistry::isNoCache($typeStr)) {
                    $this->_rowHasNoCache = true;
                    break;
                }
            }

            if ($this->isDebug) {
                $debug .= "<!-- [Block] row_id={$rowId}, columns=" . count($columns) . " -->\n";
            }

            if (empty($columns)) {
                return $this->isDebug
                    ? $debug . "<!-- [Block] row_id={$rowId} has no columns -->\n"
                    : '';
            }

            $rowAttributes = $this->buildRowAttributes($row);
            $rowStyle = $this->buildRowStyle($row);
            $columnsHtml = $this->buildColumnsHtml($row, $columns);

            // 칸 콘텐츠가 모두 비어있으면 행 자체를 출력하지 않음
            if (trim($columnsHtml) === '') {
                return $debug;
            }

            // 와이드일 때만 modifier 추가 (기본 최대넓이는 .block-container 자체가 담당)
            $containerClass = $row->isWide() ? ' block-container--wide' : '';

            $rowDomId = 'br-' . $row->getRowId();
            $rowSpacingStyleBlock = $this->buildRowSpacingStyleBlock($row, $rowDomId);
            $dismissBtn = $this->buildDismissButton($row);

            $html = $debug . $rowSpacingStyleBlock . <<<HTML
<section{$rowAttributes}>
    <div class="block-container{$containerClass}">
        <div class="block-row" id="{$rowDomId}" style="{$rowStyle}">
            {$columnsHtml}
        </div>
    </div>{$dismissBtn}
</section>
HTML;

            return $html;
        } catch (\Throwable $e) {
            $this->logError('Row build failed', [
                'row_id' => $rowId,
                'error' => $e->getMessage(),
            ]);
            return $this->renderErrorPlaceholder("Row build error: row_id={$rowId}");
        }
    }

    /**
     * 행 속성 빌드
     */
    private function buildRowAttributes(BlockRow $row): string
    {
        $attrs = [];

        // CSS ID
        if ($row->getSectionId()) {
            $attrs[] = 'id="' . htmlspecialchars($row->getSectionId()) . '"';
        }

        // CSS Class
        // block-section--{rowId} 는 행별 여백(margin) <style> 훅 — 사용자 section_id(앵커)와
        // 무관하게 불변 PK 로 보장되며, 캐시된 HTML 에 그대로 박혀도 안정적이다.
        $classes = ['block-section', 'block-section--' . $row->getRowId()];
        if ($row->isWide()) {
            $classes[] = 'block-section--wide';
        }
        // topbar "보지 않기" 훅 — 프론트 JS 가 쿠키로 숨김 처리
        $isDismissTopbar = $row->getPosition()?->value === BlockPosition::TOPBAR->value && $row->isDismissible();
        if ($isDismissTopbar) {
            $classes[] = 'block-topbar-dismissible';
        }
        $attrs[] = 'class="' . implode(' ', $classes) . '"';

        if ($isDismissTopbar) {
            $attrs[] = 'data-dismiss-key="' . (int) $row->getRowId() . '"';
            $attrs[] = 'data-dismiss-hours="' . (int) $row->getDismissHours() . '"';
        }

        // 배경 스타일
        $bgStyle = $row->getBackgroundStyle();
        if ($bgStyle) {
            $attrs[] = 'style="' . htmlspecialchars($bgStyle) . '"';
        }

        return ' ' . implode(' ', $attrs);
    }

    /**
     * topbar "보지 않기" 버튼 HTML (dismissible topbar 행에서만, 아니면 빈 문자열).
     * 클릭 처리·쿠키는 renderTopbarDismissScript() 가 붙이는 인라인 JS 가 담당한다.
     */
    private function buildDismissButton(BlockRow $row): string
    {
        if ($row->getPosition()?->value !== BlockPosition::TOPBAR->value || !$row->isDismissible()) {
            return '';
        }
        $hours = max(1, $row->getDismissHours());
        $label = $hours >= 24
            ? (intdiv($hours, 24) . '일 동안 보지 않기')
            : ($hours . '시간 동안 보지 않기');
        $esc = htmlspecialchars($label);
        return "\n    <button type=\"button\" class=\"block-topbar-dismiss\" aria-label=\"{$esc}\">{$esc}</button>";
    }

    /**
     * 행 내부 스타일 빌드
     *
     * PC/모바일 여백(margin/padding)은 인라인이 미디어 쿼리를 못 받으므로 별도 <style> 블록으로
     * 분리한다 (buildRowSpacingStyleBlock). 인라인 스타일에는 gap 등 미디어 분기가 필요 없는
     * 값만 남긴다.
     */
    private function buildRowStyle(BlockRow $row): string
    {
        $styles = [];

        // 칸 간격 (gap)
        if ($row->getColumnMargin() > 0) {
            $styles[] = "gap: {$row->getColumnMargin()}px";
        }

        return implode('; ', $styles);
    }

    /**
     * 행 여백용 동적 <style> 블록 빌드 (column 의 동일 패턴).
     *
     * 인라인 style 은 미디어 쿼리 분기를 못 받으므로 selector + 미디어 쿼리로 분리 적용한다.
     *   - 외부 여백(margin) : 섹션(.block-section--{rowId})에 적용 → 배경 밖 행간 투명 간격
     *   - 내부 여백(padding): 행(#br-{rowId})에 적용 → 배경이 채워지는 밴드 안쪽 공간
     * PC/모바일을 하나의 <style> 블록에 묶고, 모바일 값이 PC 와 같거나 비어있으면 해당 규칙 생략.
     *
     * @return string <style>...</style> 문자열 (없으면 빈 문자열)
     */
    private function buildRowSpacingStyleBlock(BlockRow $row, string $rowDomId): string
    {
        $pcMargin = $row->getPcMargin();
        $moMargin = $row->getMobileMargin();
        $pcPadding = $row->getPcPadding();
        $moPadding = $row->getMobilePadding();

        if (!$pcMargin && !$moMargin && !$pcPadding && !$moPadding) {
            return '';
        }

        $sectionSel = '.block-section--' . $row->getRowId();

        // PC 규칙
        $pcCss = '';
        if ($pcMargin) {
            $pcCss .= "{$sectionSel}{margin:{$pcMargin}}";
        }
        if ($pcPadding) {
            $pcCss .= "#{$rowDomId}{padding:{$pcPadding}}";
        }

        // 모바일 규칙 (PC 와 다른 값만)
        $moCss = '';
        if ($moMargin && $moMargin !== $pcMargin) {
            $moCss .= "{$sectionSel}{margin:{$moMargin}}";
        }
        if ($moPadding && $moPadding !== $pcPadding) {
            $moCss .= "#{$rowDomId}{padding:{$moPadding}}";
        }

        $css = $pcCss;
        if ($moCss !== '') {
            $css .= "@media(max-width:767px){{$moCss}}";
        }

        return $css === '' ? '' : "<style>{$css}</style>\n";
    }

    // ========================================
    // 칸 렌더링
    // ========================================

    /**
     * 칸 목록 HTML 빌드
     *
     * @param BlockRow $row 행 엔티티
     * @param BlockColumn[] $columns 칸 목록
     * @return string HTML
     */
    private function buildColumnsHtml(BlockRow $row, array $columns): string
    {
        $html = '';
        $totalColumns = count($columns);

        foreach ($columns as $index => $column) {
            if (!$column->isActive()) {
                continue;
            }

            $html .= $this->buildColumnHtml($column, $row, $totalColumns);
        }

        return $html;
    }

    /**
     * 단일 칸 HTML 빌드
     *
     * 타이틀과 콘텐츠는 각 렌더러의 스킨에서 처리
     */
    private function buildColumnHtml(BlockColumn $column, BlockRow $row, int $totalColumns): string
    {
        $contentHtml = $this->renderColumnContent($column);
        $isEmptySlot = $contentHtml === null || trim($contentHtml) === '';

        // 콘텐츠가 비어도 칸 영역은 유지한다(backstop).
        // 편집 미리보기는 안내를 표시하고, 공개 화면은 빈 칸으로 두되
        // block-column--empty 최소 높이로 1px까지 접히지 않게 한다.
        if ($isEmptySlot) {
            $contentHtml = $this->renderEmptyPlaceholder();
        }

        $columnStyle = $this->buildColumnStyle($column, $row, $totalColumns);
        // style 속성은 반드시 이스케이프한다. columnStyle 은 background_config/
        // border_config 등 사용자 제어 JSON 값에서 조립되며, 정화기(HTMLPurifier)는
        // html/css/js 필드만 처리하고 이 config 값은 손대지 않는다. 이스케이프를
        // 빠뜨리면 color/width 값에 심은 따옴표로 속성 탈출 → 저장형 XSS 가 된다.
        // (배경행 경로 buildRowAttributes() 와 동일한 방어.)
        $columnStyleAttr = htmlspecialchars($columnStyle, ENT_QUOTES, 'UTF-8');
        $columnId = 'bc-' . $column->getColumnId();
        $isStack = $column->isStack();
        $columnClass = 'block-column'
            . ($isStack ? ' block-column--stack' : '')
            . ($isEmptySlot ? ' block-column--empty' : '');
        $paddingStyleBlock = $this->buildColumnPaddingStyleBlock($column, $columnId);
        if ($isStack) {
            $paddingStyleBlock .= $this->buildStackGapStyleBlock($column, $columnId);
        }

        // AOS: 스택 칸은 각 콘텐츠 항목에 적용한다 (계획 7.3) — 칸 wrapper 의
        // AOS 는 레거시 미러(첫 활성 콘텐츠 config)에서 새어 나오므로 억제
        $aosAttr = '';
        if (!$isStack && $column->getAos()) {
            $aosAttr = ' data-aos="' . htmlspecialchars($column->getAos()) . '"';
            $aosDuration = $column->getAosDuration();
            if ($aosDuration && $aosDuration !== 600) {
                $aosAttr .= ' data-aos-duration="' . $aosDuration . '"';
            }
        }

        $html = $paddingStyleBlock . <<<HTML
<div class="{$columnClass}" id="{$columnId}" style="{$columnStyleAttr}"{$aosAttr}>
    {$contentHtml}
</div>
HTML;

        return $html;
    }

    /**
     * 칸 본문 패딩용 동적 <style> 블록 빌드
     *
     * column inline style 에 padding 관련 정보를 넣지 않고,
     * 칸 ID 별 selector + .block-body 조합으로 본문 영역에만 padding 적용한다.
     * 제목 영역(.block-title)은 영향 없음.
     *
     * @return string <style>...</style> 문자열 (없으면 빈 문자열)
     */
    private function buildColumnPaddingStyleBlock(BlockColumn $column, string $columnId): string
    {
        $pc = $column->getPcPadding();
        $mo = $column->getMobilePadding();

        if (!$pc && !$mo) {
            return '';
        }

        // 스택 칸: 패딩은 .block-content-stack 외곽에 한 번만 적용한다.
        // 자식 .block-body 에 칸 패딩을 반복 적용하면 콘텐츠 사이 상하
        // 패딩이 중복된다 (계획 4.3).
        $selector = $column->isStack()
            ? "#{$columnId} > .block-content-stack"
            : "#{$columnId} .block-body";

        $css = '';
        if ($pc) {
            $css .= "{$selector}{padding:{$pc}}";
        }
        if ($mo && $mo !== $pc) {
            $css .= "@media(max-width:767px){{$selector}{padding:{$mo}}}";
        }

        return "<style>{$css}</style>\n";
    }

    /**
     * 스택 콘텐츠 간격 <style> 블록 (계획 7.3 — CSS gap, PC/Mobile 반응형).
     */
    private function buildStackGapStyleBlock(BlockColumn $column, string $columnId): string
    {
        $pc = $column->getPcContentGap();
        $mo = $column->getMobileContentGap();

        $css = "#{$columnId} > .block-content-stack{display:flex;flex-direction:column;gap:{$pc}px}";
        if ($mo !== $pc) {
            $css .= "@media(max-width:767px){#{$columnId} > .block-content-stack{gap:{$mo}px}}";
        }

        return "<style>{$css}</style>\n";
    }

    /**
     * 칸 스타일 빌드
     */
    private function buildColumnStyle(BlockColumn $column, BlockRow $row, int $totalColumns): string
    {
        $styles = [];

        // 너비
        $width = $column->getWidth();
        if ($width) {
            $gap = $row->getColumnMargin();
            // % 너비에 gap이 있을 경우 auto-calc와 동일하게 gap 차감 보정
            if ($gap > 0 && $totalColumns > 1 && str_ends_with($width, '%')) {
                $gapShare = round($gap * ($totalColumns - 1) / $totalColumns, 2);
                $styles[] = "width: calc({$width} - {$gapShare}px)";
                $styles[] = "flex: 0 0 calc({$width} - {$gapShare}px)";
            } else {
                $styles[] = "width: {$width}";
                $styles[] = "flex: 0 0 {$width}";
            }
        } else {
            // 기본 균등 분할 (gap 고려) — 칸 너비는 항상 % 기준
            $gap = $row->getColumnMargin();
            $percent = round(100 / $totalColumns, 2);

            if ($gap > 0 && $totalColumns > 1) {
                // gap 총량을 칸 수로 나눠 각 칸의 flex-basis에서 차감
                $gapShare = round($gap * ($totalColumns - 1) / $totalColumns, 2);
                $styles[] = "flex: 1 1 calc({$percent}% - {$gapShare}px)";
            } else {
                $styles[] = "flex: 1 1 {$percent}%";
            }
        }

        // 패딩은 column inline style 에 넣지 않음.
        // buildColumnPaddingStyleBlock() 가 칸 ID 별 동적 <style> 블록으로 출력하여
        // 본문 영역(.block-body)에만 적용한다. (제목 영역은 영향 없음)

        // 배경
        $bgStyle = $column->getBackgroundStyle();
        if ($bgStyle) {
            $styles[] = $bgStyle;
        }

        // 테두리
        $borderStyle = $column->getBorderStyle();
        if ($borderStyle) {
            $styles[] = $borderStyle;
        }

        $borderConfig = $column->getBorderConfig() ?? [];
        if ($column->getContentTypeString() === 'image' && !empty($borderConfig['radius'])) {
            $styles[] = 'overflow: hidden';
        }

        return implode('; ', $styles);
    }


    // ========================================
    // 콘텐츠 렌더링
    // ========================================

    /**
     * 칸 콘텐츠 렌더링 — 스택 칸은 자식 콘텐츠를 순회하며 각 실제 타입의
     * 기존 Renderer 를 직접 호출한다 (Composite/Stack Renderer 없음, 계획 7.1).
     */
    private function renderColumnContent(BlockColumn $column): string
    {
        if ($column->isStack()) {
            return $this->renderStackContent($column);
        }

        return $this->renderSingleContent($column);
    }

    /**
     * 스택 칸 렌더링 (계획 7.3).
     *
     * - 각 콘텐츠는 원본 칸 레이아웃 + 콘텐츠 필드를 합친 렌더 전용 view 로
     *   기존 렌더러 계약을 그대로 사용한다
     * - 콘텐츠 항목 ID = bc-{column}-c-{content} — CSS/JS 스코핑 앵커이자
     *   같은 타입 반복 배치 시의 고유 DOM ID (계획 7.2.1)
     * - 한 콘텐츠의 실패·빈 출력이 나머지 콘텐츠를 막지 않는다 (콘텐츠별 격리)
     * - AOS 는 콘텐츠별로 항목 wrapper 에 적용
     */
    private function renderStackContent(BlockColumn $column): string
    {
        $itemsHtml = '';

        foreach ($column->getContents() as $content) {
            if (!$content->isActive()) {
                continue; // 공개 렌더는 활성만 (preload 가 걸렀지만 주입 경로 방어)
            }

            $view = $column->withContentView($content);
            $itemHtml = $this->renderSingleContent($view);

            $isEmptyItem = trim((string) $itemHtml) === '';
            if ($isEmptyItem) {
                // 빈 콘텐츠·미설치 타입도 항목 공간은 유지한다 (계획 5.5).
                // 공개 화면은 placeholder 가 비므로 --empty 클래스가 최소 높이를 맡는다
                // (칸 전체가 비지 않으면 block-column--empty 는 적용되지 않는다).
                $itemHtml = $this->renderEmptyPlaceholder();
            }

            $itemId = 'bc-' . $view->getRenderKey();
            $aosAttr = '';
            if ($view->getAos()) {
                $aosAttr = ' data-aos="' . htmlspecialchars($view->getAos()) . '"';
                $aosDuration = $view->getAosDuration();
                if ($aosDuration && $aosDuration !== 600) {
                    $aosAttr .= ' data-aos-duration="' . $aosDuration . '"';
                }
            }

            $contentId = $content->getContentId();
            $emptyClass = $isEmptyItem ? ' block-content-item--empty' : '';
            $itemsHtml .= <<<HTML
<div class="block-content-item{$emptyClass}" id="{$itemId}" data-content-id="{$contentId}"{$aosAttr}>
    {$itemHtml}
</div>
HTML;
        }

        if ($itemsHtml === '') {
            return ''; // 활성 콘텐츠 없음 — 칸 backstop(빈 칸 최소 높이)이 처리
        }

        $columnId = $column->getColumnId();

        return <<<HTML
<div class="block-content-stack" data-column-id="{$columnId}">
    {$itemsHtml}
</div>
HTML;
    }

    /**
     * 단일 콘텐츠 렌더링 (single 칸 원본 또는 스택 콘텐츠 view)
     */
    private function renderSingleContent(BlockColumn $column): string
    {
        $columnId = $column->getColumnId();
        $renderKey = $column->getRenderKey();
        $debug = $this->isDebug ? "<!-- [Block Debug] column_id={$columnId}" : '';

        if (!$column->hasContent()) {
            // 콘텐츠 미지정 칸도 슬롯을 유지하고 안내 플레이스홀더를 채운다(칸이 사라지지 않음).
            return $this->renderTypePlaceholder();
        }

        $contentType = $column->getContentTypeString();

        if ($this->isDebug) {
            $debug .= ", type={$contentType}, hasContent=" . ($column->hasContent() ? 'true' : 'false');
        }

        if (!$contentType) {
            return $this->renderTypePlaceholder();
        }

        try {
            $renderer = $this->getRenderer($contentType);
            $rendererClass = $renderer ? get_class($renderer) : 'null';

            if (!$renderer) {
                $this->logError('Unknown content type', [
                    'content_type' => $contentType,
                    'column_id' => $columnId,
                    'render_key' => $renderKey,
                ]);
                return $this->renderErrorPlaceholder("Unknown content type: {$contentType}");
            }

            $result = $renderer->render($column);

            if ($this->isDebug) {
                $debug .= ", renderer={$rendererClass}, result_len=" . strlen($result) . " -->\n";
                return $debug . $result;
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logError('Render failed', [
                'content_type' => $contentType,
                'column_id' => $columnId,
                'render_key' => $renderKey,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $this->renderErrorPlaceholder("Render error: {$contentType}");
        }
    }

    /**
     * 콘텐츠 타입 미지정 칸 플레이스홀더 (프리뷰 전용)
     *
     * 코어 .block-placeholder 스타일(block.css) 사용.
     */
    private function renderTypePlaceholder(): string
    {
        if (!$this->shouldRenderEditorPlaceholder()) {
            return '';
        }

        return <<<HTML
<div class="block-placeholder">
    <i class="bi bi-plus-square-dotted block-placeholder__icon"></i>
    <span>콘텐츠 타입을 선택하세요.</span>
</div>
HTML;
    }

    /**
     * 빈 콘텐츠 backstop 플레이스홀더 (코어)
     *
     * 렌더러가 '' 를 반환하거나 렌더 실패로 빈 값이 와도 칸을 유지하기 위한 안내.
     * 코어 .block-placeholder 스타일(block.css) 사용.
     */
    private function renderEmptyPlaceholder(): string
    {
        if (!$this->shouldRenderEditorPlaceholder()) {
            return '';
        }

        return <<<HTML
<div class="block-placeholder">
    <i class="bi bi-dash-square-dotted block-placeholder__icon"></i>
    <span>표시할 콘텐츠가 없습니다.</span>
</div>
HTML;
    }

    private function shouldRenderEditorPlaceholder(): bool
    {
        return $this->forceEditorPlaceholders || is_editor_preview();
    }

    /**
     * 에러 발생 시 플레이스홀더 렌더링
     *
     * 디버그 모드에서만 에러 정보 표시
     */
    private function renderErrorPlaceholder(string $message): string
    {
        if ($this->isDebug) {
            return "<!-- [Block Error] {$message} -->";
        }

        // 프로덕션에서는 빈 문자열 반환 (에러 정보 노출 방지)
        return '';
    }

    /**
     * 에러 로깅
     *
     * 블록 렌더링 에러를 파일에 기록
     */
    private function logError(string $message, array $context = []): void
    {
        $logMessage = "[BlockRenderService] {$message}";

        if (!empty($context)) {
            $logMessage .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        error_log($logMessage);

        // 로그 파일에도 기록 (storage/logs/block_error.log)
        $logPath = defined('MUBLO_STORAGE_PATH')
            ? MUBLO_STORAGE_PATH . '/logs'
            : dirname(__DIR__, 3) . '/storage/logs';

        if (!is_dir($logPath)) {
            @mkdir($logPath, 0755, true);
        }

        $logFile = $logPath . '/block_error.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$logMessage}" . PHP_EOL;

        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * 렌더러 인스턴스 가져오기
     *
     * DI 컨테이너를 우선 사용하고, 실패 시 직접 생성 (Plugin 하위 호환)
     */
    private function getRenderer(string $contentType): ?RendererInterface
    {
        // 캐시된 인스턴스 반환
        if (isset($this->renderers[$contentType])) {
            return $this->renderers[$contentType];
        }

        // 렌더러 클래스 조회
        $rendererClass = BlockRegistry::getRendererClass($contentType);

        if (!$rendererClass || !class_exists($rendererClass)) {
            return null;
        }

        // 컨테이너에서 해석 시도 → 실패 시 직접 생성 (Plugin 호환)
        try {
            if ($this->container->canResolve($rendererClass)) {
                $renderer = $this->container->get($rendererClass);
            } else {
                $renderer = new $rendererClass();
            }
        } catch (\Throwable $e) {
            // DI 해석/직접 생성 모두 실패 시 null 반환
            $this->logError('Renderer instantiation failed', [
                'renderer_class' => $rendererClass,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$renderer instanceof RendererInterface) {
            return null;
        }

        // AssetManager 주입 (SkinRendererTrait 사용 렌더러)
        if (property_exists($renderer, 'assetManager') && $renderer->assetManager === null) {
            try {
                $renderer->assetManager = $this->container->get(AssetManager::class);
            } catch (\Throwable $e) {
                // AssetManager 미등록 시 무시 (스킨에서 $assets = null)
            }
        }

        // 모든 블록 스킨이 일반 Front View와 같은 ViewContext/$mublo 계약을 사용한다.
        if (property_exists($renderer, 'frontViewRuntime') && $renderer->frontViewRuntime === null) {
            try {
                $renderer->frontViewRuntime = $this->container->get(FrontViewRuntime::class);
            } catch (\Throwable $e) {
                // 관리자 단독 미리보기는 SkinRendererTrait의 빈 계약으로 폴백한다.
            }
        }

        $this->renderers[$contentType] = $renderer;

        return $renderer;
    }

    // ========================================
    // 캐시 키 생성
    // ========================================

    /**
     * 위치별 행 목록 캐시 키
     */
    private function getPositionListCacheKey(int $domainId, string $position, ?string $menuCode, bool $isMainScreen = false): string
    {
        $key = self::CACHE_PREFIX . "ids:pos:{$domainId}:{$position}";
        // 메인화면은 항상 홈(고정) 스코프다 → menuCode(홈 코드)와 무관하게 단일 ':main' 키로 캐싱.
        // 이렇게 하면 무효화 시 홈 menuCode 를 몰라도 invalidate(..., true) 한 번으로 정확히 지워진다.
        if ($isMainScreen) {
            return $key . ':main';
        }
        if ($menuCode) {
            $key .= ":{$menuCode}";
        }
        return $key;
    }

    /**
     * 페이지별 행 목록 캐시 키
     */
    private function getPageListCacheKey(int $pageId): string
    {
        return self::CACHE_PREFIX . "ids:page:{$pageId}";
    }

    /**
     * 행별 캐시 키
     */
    private function getRowCacheKey(int $rowId): string
    {
        $variant = $this->cacheVariant !== '' ? ':' . $this->cacheVariant : '';
        return self::CACHE_PREFIX . "row:{$rowId}{$variant}";
    }

    private function rememberRowCacheVariant(int $rowId): void
    {
        if ($this->cacheVariant === '') {
            return;
        }

        $key = self::CACHE_PREFIX . "row-variants:{$rowId}";
        $variants = $this->cache->get($key);
        $variants = is_array($variants) ? $variants : [];
        if (!in_array($this->cacheVariant, $variants, true)) {
            $variants[] = $this->cacheVariant;
            $this->cache->set($key, $variants, self::CACHE_TTL);
        }
    }

    // ========================================
    // 캐시 관리
    // ========================================

    /**
     * 무효화 대상 메뉴 스코프 목록
     *
     * 위치 목록 캐시 키의 :{menuCode} 스코프는 "블록 행이 배정된 메뉴"가 아니라
     * "한 번이라도 방문된 메뉴"에서 생긴다 — getRowsForPosition() 이 결과가 빈 배열이어도
     * 캐시에 기록하기 때문이다. 그래서 열거 소스는 block_rows 가 아니라 메뉴 테이블이어야 한다.
     * (block_rows 기준으로 열거하면 자기 행이 없는 메뉴에 남은 빈 목록 캐시를 못 지워서,
     *  전역 행을 추가·수정해도 그 메뉴에서만 TTL 만료 전까지 안 보인다.)
     *
     * menuCode 값의 유일한 발생원은 ContextBuilder 의 메뉴 urlMap 매칭이므로
     * 메뉴 테이블 전체를 열거하면 키 공간이 완전히 덮인다. 삭제 대상이 실제로 없어도
     * 파일 존재 확인 한 번으로 끝나 비용이 사실상 없다.
     *
     * block_rows 쪽 코드도 합집합으로 남긴다 — '__index__' 같은 특수값이나 이미 삭제된
     * 메뉴의 잔여 스코프가 메뉴 테이블에는 없기 때문.
     *
     * @return string[]
     */
    private function getMenuScopesForInvalidation(int $domainId): array
    {
        if (isset($this->menuScopeCache[$domainId])) {
            return $this->menuScopeCache[$domainId];
        }

        $codes = $this->rowRepository->getDistinctMenuCodes($domainId);

        if ($this->container->canResolve(MenuItemRepository::class)) {
            $menuRepository = $this->container->get(MenuItemRepository::class);
            $codes = array_merge($codes, $menuRepository->findMenuCodesByDomain($domainId));
        }

        return $this->menuScopeCache[$domainId] = array_values(array_unique($codes));
    }

    /**
     * 도메인별 전체 블록 캐시 무효화
     *
     * menuCode=null 캐시뿐 아니라 메뉴별 캐시도 함께 삭제
     */
    public function invalidateDomainCache(int $domainId): void
    {
        // 무효화 대상 메뉴 스코프 (메뉴 테이블 기준 — getMenuScopesForInvalidation() 주석 참조)
        $menuCodes = $this->getMenuScopesForInvalidation($domainId);

        // 모든 위치 × (글로벌 + 메뉴별) + 메인화면(:main) 캐시 무효화
        foreach (BlockPosition::options() as $position => $label) {
            // 글로벌 (menuCode=null)
            $this->invalidatePositionListCache($domainId, $position);
            // 메인화면 스코프 (:main 단일 키 — menuCode 무관)
            $this->invalidatePositionListCache($domainId, $position, null, true);

            // 메뉴별
            foreach ($menuCodes as $menuCode) {
                $this->invalidatePositionListCache($domainId, $position, $menuCode);
            }
        }

        // 페이지 행 목록 캐시 — 이것을 빠뜨리면 페이지 블록 킷 적용(특히 교체) 후
        // 삭제된 행 ID 목록이 살아남아 페이지가 빈 화면으로 렌더된다.
        foreach ($this->rowRepository->getDistinctPageIds($domainId) as $pageId) {
            $this->invalidatePageListCache($pageId);
        }
    }

    /**
     * 행 콘텐츠 변경 시 캐시 무효화 (칸 내용 수정)
     *
     * 행 콘텐츠만 변경된 경우: 해당 행 캐시만 삭제
     * 행 목록 캐시는 유지 (행 추가/삭제/순서 변경이 아니므로)
     *
     * @param int $rowId 변경된 행 ID
     */
    public function invalidateRowContentCache(int $rowId): void
    {
        $this->invalidateRowCache($rowId);
    }

    /**
     * 행 구조 변경 시 캐시 무효화 (행 추가/삭제/순서변경/활성화)
     *
     * 행 목록 + 행 콘텐츠 모두 삭제
     *
     * @param BlockRow $row 변경된 행
     */
    public function invalidateRowRelatedCache(BlockRow $row): void
    {
        // 행 콘텐츠 캐시
        $this->invalidateRowCache($row->getRowId());

        // 위치 행 목록 캐시
        if ($row->getPosition()) {
            $domainId = $row->getDomainId();
            $position = $row->getPosition()->value;
            $menuCode = $row->getPositionMenu();

            $this->invalidatePositionListCache($domainId, $position, $menuCode);
            // 메인화면 스코프(:main) 캐시도 무효화 — 전역/메인전용(__index__)/홈 타겟 행 변경이
            // 메인화면 topbar 등에 즉시 반영되게 한다. (:main 은 홈 코드와 무관한 단일 키)
            $this->invalidatePositionListCache($domainId, $position, null, true);

            // 전역 행(position_menu IS NULL)은 findByPosition() 에서 모든 메뉴 스코프 목록에
            // 함께 포함된다(whereNull 이 항상 OR). 따라서 전역 행 변경 시 글로벌·메인 키만
            // 지우면 이미 캐시된 각 메뉴 스코프 키(:{menuCode})에 스테일 목록이 남는다.
            // → 해당 위치의 모든 메뉴 스코프 키까지 무효화한다.
            if ($menuCode === null) {
                foreach ($this->getMenuScopesForInvalidation($domainId) as $code) {
                    $this->invalidatePositionListCache($domainId, $position, $code);
                }
            }
        }

        // 페이지 행 목록 캐시
        if ($row->getPageId()) {
            $this->invalidatePageListCache($row->getPageId());
        }
    }
}
