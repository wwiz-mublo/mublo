<?php
declare(strict_types=1);
namespace Mublo\Controller\Front;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Block\BlockPageRenderingEvent;
use Mublo\Service\Block\BlockPageService;
use Mublo\Service\Block\BlockRenderService;
use Mublo\Service\Auth\AuthService;

/**
 * 블록 페이지 Front 컨트롤러
 *
 * /page/{code} 라우트 처리
 */
class PageController
{
    private BlockPageService $pageService;
    private BlockRenderService $blockRenderService;
    private AuthService $authService;
    private ?EventDispatcher $eventDispatcher;

    public function __construct(
        BlockPageService $pageService,
        BlockRenderService $blockRenderService,
        AuthService $authService,
        ?EventDispatcher $eventDispatcher = null
    ) {
        $this->pageService = $pageService;
        $this->blockRenderService = $blockRenderService;
        $this->authService = $authService;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * 블록 페이지 표시
     */
    public function view(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $code = $params['code'] ?? '';
        $domainId = $context->getDomainId() ?? 1;

        // 페이지 조회
        $page = $this->pageService->getPageByCode($domainId, $code);

        if (!$page) {
            return ViewResponse::view('error/notfound')
                ->withStatusCode(404)
                ->withData(['message' => '페이지를 찾을 수 없습니다.']);
        }

        // 비활성 페이지는 도메인 운영자의 preview 모드에서만 허용한다.
        // 쿼리 문자열만으로 미공개 콘텐츠가 노출되어서는 안 된다.
        if (!$page->isActive()) {
            $request = $context->getRequest();
            $isPreview = ($request->query('preview') === '1');
            if (!$isPreview || !$this->authService->canOperateDomain()) {
                return ViewResponse::view('error/notfound')
                    ->withStatusCode(404)
                    ->withData(['message' => '페이지를 찾을 수 없습니다.']);
            }
        }

        // 접근 권한 확인
        $member = $this->authService->user();
        $memberLevel = (int) ($member['level_value'] ?? 0);

        if (!$page->canAccess($memberLevel)) {
            return RedirectResponse::to('/login');
        }

        // 블록 렌더링 (preview 모드에서는 캐시 우회)
        $isPreview = ($context->getRequest()->query('preview') === '1');
        $blockHtml = $this->blockRenderService->renderPage($page->getPageId(), !$isPreview);

        // 이벤트 발행: 패키지가 추가 HTML 주입 (사업자 정보 등)
        $appendHtml = '';
        if ($this->eventDispatcher) {
            $event = $this->eventDispatcher->dispatch(
                new BlockPageRenderingEvent($page, $context)
            );
            if ($event->hasHtml()) {
                $appendHtml = implode("\n", $event->getHtmlSorted());
            }
        }

        // 페이지 설정을 _pageConfig로 전달 (FrontViewRenderer가 참조)
        // page_config(CTA 등 확장 설정)를 레이아웃 설정과 merge
        $response = ViewResponse::view('page/view')
            ->withData([
                'pageTitle' => $page->getPageTitle(),
                'seoTitle' => $page->getSeoTitle(),
                'seoDescription' => $page->getSeoDescription() ?? $page->getPageDescription() ?? '',
                'seoKeywords' => $page->getSeoKeywords() ?? '',
                'pageCode' => $page->getPageCode(),
                'domainId' => $domainId,
                'blockHtml' => $blockHtml,
                'appendHtml' => $appendHtml,
                '_pageConfig' => array_merge($page->getPageConfig(), [
                    'layout_type' => $page->getLayoutType()->value,
                    'use_fullpage' => $page->useFullpage(),
                    'custom_width' => $page->getCustomWidth(),
                    'sidebar_left_width' => $page->getSidebarLeftWidth(),
                    'sidebar_left_mobile' => $page->getSidebarLeftMobile(),
                    'sidebar_right_width' => $page->getSidebarRightWidth(),
                    'sidebar_right_mobile' => $page->getSidebarRightMobile(),
                    'use_header' => $page->useHeader(),
                    'use_footer' => $page->useFooter(),
                ]),
            ]);

        // preview 모드: CDN 캐시 방지
        if ($isPreview) {
            $response->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
            $response->withHeader('CDN-Cache-Control', 'no-store');
        }

        return $response;
    }
}
