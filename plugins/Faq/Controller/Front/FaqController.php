<?php
declare(strict_types=1);
namespace Mublo\Plugin\Faq\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Plugin\Faq\Repository\FaqConfigRepository;
use Mublo\Plugin\Faq\Service\FaqService;

/**
 * FAQ 프론트 Controller
 */
class FaqController
{
    private const SKIN_BASE_PATH = MUBLO_PLUGIN_PATH . '/Faq/views/Front/skins/';

    public function __construct(
        private FaqService          $faqService,
        private FaqConfigRepository $configRepo,
    ) {
    }

    /**
     * 전체 FAQ 페이지
     *
     * GET /faq
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        $categories = $this->faqService->getCategories($domainId);
        $grouped = $this->faqService->getGroupedAll($domainId);

        return ViewResponse::absoluteView($this->getSkinViewPath($domainId))
            ->withData([
                'pageTitle' => '자주 묻는 질문',
                'categories' => $categories,
                'grouped' => $grouped,
                'activeSlug' => null,
            ]);
    }

    /**
     * 카테고리별 FAQ 페이지
     *
     * GET /faq/{slug}
     */
    public function category(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $slug = $params['slug'] ?? '';

        $categories = $this->faqService->getCategories($domainId);
        $grouped = !empty($slug)
            ? $this->faqService->getByCategorySlugs($domainId, [$slug])
            : [];

        // slug에 해당하는 카테고리 정보 찾기
        $activeCategory = null;
        foreach ($categories as $cat) {
            if ($cat['category_slug'] === $slug) {
                $activeCategory = $cat;
                break;
            }
        }

        // 카테고리별 결과를 grouped 형식으로 변환
        $groupedForView = [];
        if ($activeCategory && isset($grouped[$slug])) {
            $groupedForView[] = [
                'category_name' => $activeCategory['category_name'],
                'category_slug' => $slug,
                'items' => $grouped[$slug],
            ];
        }

        return ViewResponse::absoluteView($this->getSkinViewPath($domainId))
            ->withData([
                'pageTitle' => $activeCategory
                    ? $activeCategory['category_name'] . ' - FAQ'
                    : '자주 묻는 질문',
                'categories' => $categories,
                'grouped' => $groupedForView,
                'activeSlug' => $slug,
            ]);
    }

    /**
     * FAQ 목록 API (패키지 AJAX 호출용)
     *
     * GET /faq/api/list
     */
    public function apiList(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $slugs = $request->query('slugs', '');

        if (!empty($slugs)) {
            $slugArray = array_filter(array_map('trim', explode(',', $slugs)));
            $data = $this->faqService->getByCategorySlugs($domainId, $slugArray);
        } else {
            $data = $this->faqService->getGroupedAll($domainId);
        }

        return JsonResponse::success(['faq' => $data]);
    }

    /**
     * 스킨 뷰 경로 해석 — plugin_faq_configs.skin_name 기준, 없으면 basic 폴백.
     */
    private function getSkinViewPath(int $domainId): string
    {
        $skin = $this->configRepo->getSkinName($domainId);
        $path = self::SKIN_BASE_PATH . $skin . '/List';

        if (!is_file($path . '.php')) {
            $path = self::SKIN_BASE_PATH . 'basic/List';
        }

        return $path;
    }
}
