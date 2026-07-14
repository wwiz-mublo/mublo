<?php
namespace Mublo\Packages\Board\Plugins\BoardReport\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Packages\Board\Contract\Extension\BoardExtensionApiInterface;
use Mublo\Packages\Board\Plugins\BoardReport\Service\BoardReportService;

/**
 * 신고 관리 (관리자)
 *
 * 신고 "처리"는 상태값 변경이 아니라 실제 조치다:
 * - 글 보기/수정: Board 관리자 게시글 화면으로 (블라인드여도 관리자는 열람)
 * - 블라인드: 방문자에게 숨김 → 그 글의 대기 신고 자동 "인용"(resolved)
 * - 글 삭제: Board 서비스로 삭제 → 대기 신고 자동 "인용", 이력은 남음
 * - 기각: 신고가 부당함 — 조치 없이 종결
 *
 * GET  /admin/board/report/list            → 신고 목록 (페이징·상태 필터)
 * POST /admin/board/report/status          → 개별 기각
 * POST /admin/board/report/blind           → 게시글 블라인드 토글
 * POST /admin/board/report/delete-article  → 게시글 삭제
 * POST /admin/board/report/bulk            → 선택 일괄 조치 (blind/dismiss/delete)
 */
class BoardReportAdminController
{
    public function __construct(
        private BoardReportService $service,
        private BoardExtensionApiInterface $board
    ) {}

    private const PER_PAGE = 20;

    public function list(array $params, Context $context): ViewResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $status = (string) $request->query('status', '');
        $page = max(1, (int) $request->query('page', 1));

        $result = $this->service->paginate($domainId, $status, $page, self::PER_PAGE);

        // 게시글 링크 구성 (board_slug)
        foreach ($result['items'] as &$item) {
            $article = $this->board->articles()->findAccessibleById(
                (int) $item['article_id'],
                $domainId
            );
            $item['article_exists'] = $article !== null;
        }

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/List')
            ->withData([
                'pageTitle' => '게시글 신고 관리',
                'items' => $result['items'],
                'total' => $result['total'],
                'status' => $status,
                'reasons' => BoardReportService::REASONS,
                // 목록 번호(내림차순)의 시작값 — DB ID 대신 표시용
                'startNumber' => $result['total'] - ($page - 1) * self::PER_PAGE,
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => (int) ceil($result['total'] / self::PER_PAGE),
                    'totalItems' => $result['total'],
                ],
            ]);
    }

    public function status(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;

        // MubloRequest.requestJson 은 JSON 본문으로 보낸다 (form 폴백 포함)
        $result = $this->service->setStatus(
            $domainId,
            (int) ($request->json('report_id') ?? $request->input('report_id', 0)),
            (string) ($request->json('status') ?? $request->input('status', ''))
        );

        return $result->isSuccess()
            ? JsonResponse::success($result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function blind(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;

        $result = $this->service->setBlind(
            $domainId,
            (int) ($request->json('article_id') ?? $request->input('article_id', 0)),
            (string) ($request->json('blind') ?? $request->input('blind', '')) === '1'
        );

        return $result->isSuccess()
            ? JsonResponse::success($result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 게시글 삭제 — Board 의 삭제 서비스를 그대로 사용한다 (soft delete,
     * 이벤트 발행 포함). ArticleDeletedEvent 를 통해 대기 신고가 자동으로
     * 인용으로 전이된다.
     */
    public function deleteArticle(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $articleId = (int) ($request->json('article_id') ?? $request->input('article_id', 0));

        $result = $this->board->commands()->delete($articleId, $context);

        return $result->isSuccess()
            ? JsonResponse::success('게시글을 삭제했습니다. 대기 신고는 인용으로 전환됩니다.')
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 선택 일괄 조치
     *
     * action: blind(선택 글 블라인드) | dismiss(선택 신고 기각) | delete(선택 글 삭제)
     */
    public function bulk(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $action = (string) ($request->json('action') ?? $request->input('action', ''));
        $reportIds = $request->json('report_ids') ?? $request->input('report_ids', []);

        if (!is_array($reportIds) || $reportIds === []) {
            return JsonResponse::error('선택된 신고가 없습니다.');
        }

        // 신고 → 대상 글 (중복 글은 한 번만 조치)
        $articleIds = [];
        $validReportIds = [];
        foreach ($reportIds as $reportId) {
            $report = $this->service->find($domainId, (int) $reportId);
            if ($report === null) {
                continue;
            }
            $validReportIds[] = (int) $reportId;
            $articleIds[(int) $report['article_id']] = true;
        }

        if ($validReportIds === []) {
            return JsonResponse::error('처리할 신고를 찾을 수 없습니다.');
        }

        $done = 0;
        $failed = [];

        switch ($action) {
            case 'dismiss':
                foreach ($validReportIds as $reportId) {
                    if ($this->service->setStatus($domainId, $reportId, 'dismissed')->isSuccess()) {
                        $done++;
                    }
                }
                return JsonResponse::success("신고 {$done}건을 기각했습니다.");

            case 'blind':
                foreach (array_keys($articleIds) as $articleId) {
                    if ($this->service->setBlind($domainId, $articleId, true)->isSuccess()) {
                        $done++;
                    }
                }
                return JsonResponse::success("게시글 {$done}개를 블라인드 처리했습니다. 대기 신고는 인용으로 전환됩니다.");

            case 'delete':
                foreach (array_keys($articleIds) as $articleId) {
                    $result = $this->board->commands()->delete($articleId, $context);
                    $result->isSuccess() ? $done++ : $failed[] = $articleId;
                }
                $message = "게시글 {$done}개를 삭제했습니다.";
                if ($failed !== []) {
                    $message .= ' (실패 ' . count($failed) . '개)';
                }
                return JsonResponse::success($message);

            default:
                return JsonResponse::error('잘못된 조치입니다.');
        }
    }
}
