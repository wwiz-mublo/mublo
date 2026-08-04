<?php
declare(strict_types=1);
namespace Mublo\Plugin\Manual\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\ViewResponse;
use Mublo\Plugin\Manual\Repository\ManualConfigRepository;
use Mublo\Plugin\Manual\Service\ManualService;

/**
 * 매뉴얼 프론트 Controller (열람)
 */
class ManualController
{
    private const SKIN_BASE_PATH = MUBLO_PLUGIN_PATH . '/Manual/views/Front/skins/';

    public function __construct(
        private ManualService          $manualService,
        private ManualConfigRepository $configRepo,
    ) {
    }

    /**
     * 매뉴얼 목록
     *
     * GET /manual
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $this->manualService->ensureDefaultManuals($domainId);
        $books = $this->manualService->getActiveBooks($domainId);

        return ViewResponse::absoluteView($this->skinView($domainId, 'BookList'))
            ->withData([
                'pageTitle' => '매뉴얼',
                'books'     => $books,
            ]);
    }

    /**
     * 매뉴얼 열람 (TOC + 본문). 특정 페이지 slug가 있으면 해당 위치로 딥링크.
     *
     * GET /manual/{bookSlug}
     * GET /manual/{bookSlug}/{pageSlug}
     */
    public function view(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $this->manualService->ensureDefaultManuals($domainId);
        $bookSlug = $params['bookSlug'] ?? '';
        $pageSlug = $params['pageSlug'] ?? null;

        $book = $this->manualService->getBookBySlug($domainId, $bookSlug);
        if (!$book) {
            return ViewResponse::absoluteView($this->skinView($domainId, 'BookList'))
                ->withData([
                    'pageTitle' => '매뉴얼',
                    'books'     => $this->manualService->getActiveBooks($domainId),
                    'notFound'  => true,
                ]);
        }

        $bookId = $book->bookId;
        $tree = $this->manualService->getPageTree($bookId);

        $activePage = null;
        if ($pageSlug !== null && $pageSlug !== '') {
            $activePage = $this->manualService->getPageBySlug($bookId, $pageSlug);
        }

        return ViewResponse::absoluteView($this->skinView($domainId, 'View'))
            ->withData([
                'pageTitle'  => $book->title,
                'book'       => $book,
                'tree'       => $tree,
                'activeSlug' => $activePage?->slug,
            ]);
    }

    /**
     * 스킨 뷰 경로 해석 — plugin_manual_configs.skin_name 기준.
     * 선택한 스킨에 해당 파일이 없으면 파일 단위로 basic 에 폴백한다.
     */
    private function skinView(int $domainId, string $view): string
    {
        $skin = $this->configRepo->getSkinName($domainId);
        $path = self::SKIN_BASE_PATH . $skin . '/' . $view;

        if (!is_file($path . '.php')) {
            $path = self::SKIN_BASE_PATH . 'basic/' . $view;
        }

        return $path;
    }
}
