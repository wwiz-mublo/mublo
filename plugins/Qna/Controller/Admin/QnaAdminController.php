<?php
declare(strict_types=1);
namespace Mublo\Plugin\Qna\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Plugin\Qna\Enum\AuthorType;
use Mublo\Plugin\Qna\Enum\InquiryStatus;
use Mublo\Plugin\Qna\Repository\QnaConfigRepository;
use Mublo\Plugin\Qna\Service\QnaService;
use Mublo\Contract\Auth\AuthContextInterface;

/**
 * QnA 관리자 Controller.
 * 모든 라우트는 AdminMiddleware 뒤에 있다.
 */
class QnaAdminController
{
    private const PLUGIN_NAME    = 'Qna';
    private const VIEW_PATH      = MUBLO_PLUGIN_PATH . '/Qna/views/Admin/';
    private const SKIN_BASE_PATH = MUBLO_PLUGIN_PATH . '/Qna/views/Front/skins/';
    private const PER_PAGE       = 20;

    public function __construct(
        private QnaService          $qnaService,
        private MigrationRunner     $migrationRunner,
        private QnaConfigRepository $configRepo,
        private AuthContextInterface         $auth,
    ) {
    }

    private function migrationPath(): string
    {
        return MUBLO_PLUGIN_PATH . '/Qna/database/migrations';
    }

    /** 문의 목록  GET /admin/qna */
    public function index(array $params, Context $context): ViewResponse
    {
        $status = $this->migrationRunner->getStatus('plugin', self::PLUGIN_NAME, $this->migrationPath());
        if (!empty($status['pending'])) {
            return ViewResponse::absoluteView(self::VIEW_PATH . 'Install')
                ->withData([
                    'pageTitle' => 'Q&A 플러그인 설치',
                    'pending'   => $status['pending'],
                ]);
        }

        $domainId = $context->getDomainId() ?? 1;
        $request  = $context->getRequest();
        $page     = max(1, (int) $request->query('page', 1));
        $filter   = (string) $request->query('status', '');
        $keyword  = (string) $request->query('keyword', '');

        $result = $this->qnaService->getInquiriesForAdmin($domainId, $page, self::PER_PAGE, $filter, $keyword);

        return ViewResponse::absoluteView(self::VIEW_PATH . 'List')
            ->withData([
                'pageTitle'   => 'Q&A 관리',
                'items'       => $result['items'],
                'pagination'  => $result['pagination'],
                'filters'     => ['status' => $filter, 'keyword' => $keyword],
                'statuses'    => InquiryStatus::options(),
                'skins'       => $this->getAvailableSkins(),
                'currentSkin' => $this->configRepo->getSkinName($domainId),
            ]);
    }

    /**
     * 사용 가능한 프론트 스킨 목록 (디렉토리 스캔)
     *
     * @return string[]
     */
    private function getAvailableSkins(): array
    {
        $dirs = glob(self::SKIN_BASE_PATH . '*', GLOB_ONLYDIR);
        if (!$dirs) {
            return ['basic'];
        }

        return array_map('basename', $dirs);
    }

    /** 상태 일괄 변경  POST /admin/qna/list-modify */
    public function listModify(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $items    = $context->getRequest()->json('items') ?? [];

        if (empty($items)) {
            return JsonResponse::error('수정할 항목이 없습니다.');
        }

        $result = $this->qnaService->batchUpdateStatus($domainId, $items);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 선택 삭제  POST /admin/qna/list-delete */
    public function listDelete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $ids      = $context->getRequest()->input('chk') ?? [];

        if (empty($ids)) {
            return JsonResponse::error('삭제할 항목이 없습니다.');
        }

        $result = $this->qnaService->batchDelete($domainId, $ids);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 문의 상세/답변(스레드)  GET /admin/qna/{id}/edit */
    public function edit(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $domainId  = $context->getDomainId() ?? 1;
        $inquiryId = (int) ($params['id'] ?? 0);

        $result = $this->qnaService->viewInquiry($domainId, $inquiryId, (int) $this->auth->id(), true);
        if (!$result->isSuccess()) {
            return RedirectResponse::to('/admin/qna');
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Form')
            ->withData([
                'pageTitle' => 'Q&A',
                'inquiry'   => $result->get('inquiry'),
                'replies'   => $result->get('replies'),
                'statuses'  => InquiryStatus::options(),
            ]);
    }

    /** 첨부 임시 업로드(AJAX)  POST /admin/qna/upload */
    public function upload(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        $file = UploadedFile::fromGlobal('file');
        if (!$file) {
            return JsonResponse::error('파일이 업로드되지 않았습니다.');
        }

        $result = $this->qnaService->uploadTempAttachment($domainId, $file);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 운영자 답변 작성  POST /admin/qna/{id}/reply */
    public function reply(array $params, Context $context): JsonResponse
    {
        $domainId  = $context->getDomainId() ?? 1;
        $inquiryId = (int) ($params['id'] ?? 0);
        $request   = $context->getRequest();
        $data      = $request->json() ?: $request->input('formData') ?? [];

        $user = $this->auth->currentUser();
        $data['author_name'] = $user?->publicDisplayName() ?: '관리자';

        $result = $this->qnaService->addReply(
            $domainId,
            $inquiryId,
            AuthorType::Admin,
            (int) $this->auth->id(),
            $data,
            $request->getClientIp()
        );

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 상태 변경  PUT /admin/qna/{id}/status */
    public function status(array $params, Context $context): JsonResponse
    {
        $domainId  = $context->getDomainId() ?? 1;
        $inquiryId = (int) ($params['id'] ?? 0);
        $data      = $context->getRequest()->json() ?: [];

        $result = $this->qnaService->updateStatus($domainId, $inquiryId, (string) ($data['status'] ?? ''));

        return $result->isSuccess()
            ? JsonResponse::success([], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 문의 삭제  DELETE /admin/qna/{id} */
    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId  = $context->getDomainId() ?? 1;
        $inquiryId = (int) ($params['id'] ?? 0);

        $result = $this->qnaService->deleteInquiry($domainId, $inquiryId);

        return $result->isSuccess()
            ? JsonResponse::success([], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 설정 저장  PUT /admin/qna/config */
    public function saveConfig(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $data     = $context->getRequest()->json() ?: [];

        if (isset($data['skin_name']) && !in_array($data['skin_name'], $this->getAvailableSkins(), true)) {
            return JsonResponse::error('존재하지 않는 스킨입니다.');
        }

        $this->configRepo->upsert($domainId, $data);

        return JsonResponse::success([], '설정이 저장되었습니다.');
    }

    /** 플러그인 설치(마이그레이션 실행)  POST /admin/qna/install */
    public function install(array $params, Context $context): JsonResponse
    {
        $result = $this->migrationRunner->run('plugin', self::PLUGIN_NAME, $this->migrationPath());

        if ($result['success']) {
            return JsonResponse::success(
                ['redirect' => '/admin/qna'],
                'Q&A 플러그인이 설치되었습니다. (실행: ' . count($result['executed']) . '개)'
            );
        }

        return JsonResponse::error('설치 실패: ' . ($result['error'] ?? '알 수 없는 오류'));
    }
}
