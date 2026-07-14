<?php
namespace Mublo\Plugin\Faq\Block;

use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Core\Block\Renderer\SkinRendererTrait;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Plugin\Faq\Service\FaqService;

/**
 * FaqRenderer
 *
 * FAQ 블록 콘텐츠 렌더러
 *
 * content_items: 카테고리 slug 배열 (DualListbox로 선택)
 * 미선택 시 전체 FAQ 표시
 *
 * 스킨에 전달되는 변수:
 * - $titleConfig: 타이틀 설정 (SkinRendererTrait에서 추출)
 * - $titlePartial: 타이틀 파셜 경로
 * - $contentConfig: 콘텐츠 설정
 * - $column: BlockColumn 엔티티
 * - $grouped: FAQ 그룹 배열 [{category_name, category_slug, items: [{faq_id, question, answer}]}, ...]
 * - $config: content_config (show_category 등)
 * - $pcCount / $moCount: 노출 개수 (카테고리 탭 하나당). 0 이면 제한 없음.
 *
 * 노출 개수는 관리자 블록 설정의 pc_count / mo_count 를 쓴다.
 * (max_items 는 렌더되지 않는 FaqConfigForm 의 레거시 키라 폴백으로만 인정)
 * 카테고리 탭으로 한 분류씩 보여주므로 개수 제한도 '탭 하나당'으로 적용하며,
 * 그룹 전체를 잘라내지 않고 스킨이 표시 여부를 판단하도록 그대로 넘긴다.
 */
class FaqRenderer implements RendererInterface
{
    use SkinRendererTrait;

    private FaqService $faqService;

    public function __construct(FaqService $faqService)
    {
        $this->faqService = $faqService;
    }

    protected function getSkinType(): string
    {
        return 'faq';
    }

    protected function getSkinBasePath(): string
    {
        return MUBLO_PLUGIN_PATH . '/Faq/views/Block/';
    }

    public function render(BlockColumn $column): string
    {
        $domainId = $column->getDomainId();
        $contentItems = $column->getContentItems() ?? [];
        $config = $column->getContentConfig() ?? [];

        // 카테고리 slug 추출
        $slugs = $this->extractSlugs($contentItems);

        // FAQ 데이터 조회
        if (empty($slugs)) {
            $grouped = $this->faqService->getGroupedAll($domainId);
        } else {
            $grouped = $this->buildGroupedFromSlugs($domainId, $slugs);
        }

        if (empty($grouped) && !is_editor_preview()) {
            return '';
        }

        // grouped 가 비어도 return '' 하지 않는다(칸 드롭 방지).
        // 스킨이 빈 상태(.block-faq__empty)를 렌더한다.

        // 노출 개수 — 관리자 설정(pc_count/mo_count) 우선, max_items 는 레거시 폴백
        $legacy  = (int) ($config['max_items'] ?? 0);
        $pcCount = (int) ($config['pc_count'] ?? 0);
        $moCount = (int) ($config['mo_count'] ?? 0);
        if ($pcCount <= 0) {
            $pcCount = $legacy;
        }
        if ($moCount <= 0) {
            $moCount = $pcCount;
        }

        $skin = $column->getContentSkin() ?: 'basic';

        return $this->renderSkin($column, $skin, [
            'grouped' => $grouped,
            'config' => $config,
            'pcCount' => max(0, $pcCount),
            'moCount' => max(0, $moCount),
        ]);
    }

    /**
     * content_items에서 카테고리 slug 추출
     */
    private function extractSlugs(array $contentItems): array
    {
        if (empty($contentItems)) {
            return [];
        }

        return array_map(function ($item) {
            return is_array($item) ? ($item['id'] ?? '') : (string) $item;
        }, $contentItems);
    }

    /**
     * slug 배열로 grouped 형식 데이터 구성
     */
    private function buildGroupedFromSlugs(int $domainId, array $slugs): array
    {
        $bySlug = $this->faqService->getByCategorySlugs($domainId, $slugs);
        $categories = $this->faqService->getCategories($domainId);

        // 카테고리명 매핑
        $catNameMap = [];
        foreach ($categories as $cat) {
            $catNameMap[$cat['category_slug']] = $cat['category_name'];
        }

        $grouped = [];
        foreach ($slugs as $slug) {
            if (!isset($bySlug[$slug])) {
                continue;
            }
            $grouped[] = [
                'category_name' => $catNameMap[$slug] ?? $slug,
                'category_slug' => $slug,
                'items' => $bySlug[$slug],
            ];
        }

        return $grouped;
    }

}
