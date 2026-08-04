<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Plugins\BoardReport\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Packages\Board\Contract\Extension\BoardExtensionApiInterface;
use Mublo\Packages\Board\Plugins\BoardReport\Service\BoardReportService;
use Mublo\Contract\Auth\AuthContextInterface;

/**
 * 신고 접수 (프론트)
 *
 * GET  /board/report/form?article_id=N  → 신고 사유 폼
 * POST /board/report/submit             → 접수 후 게시글로 복귀
 *
 * JS 의존 없는 링크+폼 방식 — 어떤 게시판 스킨에서도 동작한다.
 */
class BoardReportController
{
    public function __construct(
        private BoardReportService $service,
        private BoardExtensionApiInterface $board,
        private AuthContextInterface $authService
    ) {}

    public function form(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $articleId = (int) $context->getRequest()->query('article_id', 0);
        $domainId = $context->getDomainId() ?? 1;
        $article = $articleId > 0
            ? $this->board->articles()->findAccessibleById($articleId, $domainId)
            : null;

        if ($article === null) {
            return RedirectResponse::to('/');
        }

        // 렌더러가 '..' 포함 경로를 차단하므로 dirname 으로 상위 경로를 만든다
        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Front/Form')
            ->withData([
                'pageTitle' => '게시글 신고',
                'articleId' => $articleId,
                'articleTitle' => $article->getTitle(),
                'reasons' => BoardReportService::REASONS,
                'sent' => $context->getRequest()->query('sent', '') !== '',
                'error' => (string) $context->getRequest()->query('error', ''),
            ]);
    }

    public function submit(array $params, Context $context): RedirectResponse|JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $articleId = (int) $request->input('article_id', 0);
        $result = $this->service->submit(
            $domainId,
            $articleId,
            (string) $request->input('reason', ''),
            trim((string) $request->input('detail', '')),
            $this->authService->id(),
            $request->getClientIp()
        );

        // 모달(AJAX)에서는 JSON, 폴백 페이지 폼에서는 리다이렉트
        if ($request->isAjax()) {
            return $result->isSuccess()
                ? JsonResponse::success($result->getMessage())
                : JsonResponse::error($result->getMessage());
        }

        $query = 'article_id=' . $articleId . ($result->isSuccess()
            ? '&sent=1'
            : '&error=' . urlencode($result->getMessage()));

        return RedirectResponse::to('/board/report/form?' . $query);
    }
}
