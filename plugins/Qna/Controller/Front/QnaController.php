<?php
declare(strict_types=1);
namespace Mublo\Plugin\Qna\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Plugin\Qna\Enum\AuthorType;
use Mublo\Plugin\Qna\Repository\QnaConfigRepository;
use Mublo\Plugin\Qna\Service\QnaService;
use Mublo\Contract\Auth\AuthContextInterface;

/**
 * QnA 프론트 Controller (문의자)
 *
 * 현재 정책: 회원 전용. 비로그인 접근은 로그인 페이지로 유도한다.
 */
class QnaController
{
    private const SKIN_BASE_PATH = MUBLO_PLUGIN_PATH . '/Qna/views/Front/skins/';

    public function __construct(
        private QnaService          $qnaService,
        private QnaConfigRepository $configRepo,
        private AuthContextInterface         $auth,
    ) {
    }

    /** 내 문의 목록  GET /qna */
    public function index(array $params, Context $context): ViewResponse|RedirectResponse
    {
        if (!$this->auth->check()) {
            return RedirectResponse::to('/login');
        }

        $domainId = $context->getDomainId() ?? 1;
        $memberId = (int) $this->auth->id();
        $page     = max(1, (int) $context->getRequest()->query('page', 1));

        $result = $this->qnaService->getMyInquiries($domainId, $memberId, $page, 20);

        return ViewResponse::absoluteView($this->skinView($domainId, 'List'))
            ->withData([
                'pageTitle' => '질문과 답변',
                'items'     => $result['items'],
                'total'     => $result['total'],
                'page'      => $page,
                'perPage'   => 20,
            ]);
    }

    /** 문의 상세(스레드)  GET /qna/{id} */
    public function show(array $params, Context $context): ViewResponse|RedirectResponse
    {
        if (!$this->auth->check()) {
            return RedirectResponse::to('/login');
        }

        $domainId  = $context->getDomainId() ?? 1;
        $inquiryId = (int) ($params['id'] ?? 0);

        $result = $this->qnaService->viewInquiry(
            $domainId,
            $inquiryId,
            (int) $this->auth->id(),
            $this->auth->isAdmin()
        );

        if (!$result->isSuccess()) {
            return RedirectResponse::to('/qna');
        }

        $inquiry = $result->get('inquiry');
        $title   = trim((string) ($inquiry['title'] ?? ''));

        return ViewResponse::absoluteView($this->skinView($domainId, 'Show'))
            ->withData([
                // GA 실시간 등에서 페이지 구분이 되도록 항상 "- Q&A" 접미
                'pageTitle' => ($title !== '' ? $title : '상세') . ' - Q&A',
                'inquiry'   => $inquiry,
                'replies'   => $result->get('replies'),
                // 프론트는 접수창구 — 작성/삭제 UI는 소유자에게만, 비소유자(운영자 열람)는 관리자로 안내
                'isOwner'   => (int) ($inquiry['member_id'] ?? 0) === (int) $this->auth->id(),
            ]);
    }

    /** 문의 작성 폼  GET /qna/write */
    public function create(array $params, Context $context): ViewResponse|RedirectResponse
    {
        if (!$this->auth->check()) {
            return RedirectResponse::to('/login');
        }

        $domainId = $context->getDomainId() ?? 1;

        return ViewResponse::absoluteView($this->skinView($domainId, 'Write'))
            ->withData([
                'pageTitle' => '질문 - Q&A',
                'config'    => $this->qnaService->getConfig($domainId),
            ]);
    }

    /** 문의 등록  POST /qna */
    public function store(array $params, Context $context): JsonResponse
    {
        if (!$this->auth->check()) {
            return JsonResponse::error('로그인이 필요합니다.');
        }

        $domainId = $context->getDomainId() ?? 1;
        $request  = $context->getRequest();
        $data     = $request->json() ?: $request->input('formData') ?? [];

        // 작성자명은 세션 기준으로 스냅샷 (클라이언트 입력 신뢰 안 함)
        $data['author_name'] = $this->authorName();

        $result = $this->qnaService->createInquiry(
            $domainId,
            (int) $this->auth->id(),
            $data,
            $request->getClientIp()
        );

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 첨부 임시 업로드(AJAX)  POST /qna/upload */
    public function upload(array $params, Context $context): JsonResponse
    {
        if (!$this->auth->check()) {
            return JsonResponse::error('로그인이 필요합니다.');
        }

        $domainId = $context->getDomainId() ?? 1;
        $config   = $this->qnaService->getConfig($domainId);

        if ((int) $config['allow_file'] !== 1) {
            return JsonResponse::error('파일 첨부가 허용되지 않았습니다.');
        }

        $file = UploadedFile::fromGlobal('file');
        if (!$file) {
            return JsonResponse::error('파일이 업로드되지 않았습니다.');
        }

        $result = $this->qnaService->uploadTempAttachment($domainId, $file);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 추가 문의(스레드 글) 작성  POST /qna/{id}/reply */
    public function reply(array $params, Context $context): JsonResponse
    {
        if (!$this->auth->check()) {
            return JsonResponse::error('로그인이 필요합니다.');
        }

        $domainId  = $context->getDomainId() ?? 1;
        $inquiryId = (int) ($params['id'] ?? 0);
        $request   = $context->getRequest();
        $data      = $request->json() ?: $request->input('formData') ?? [];

        $data['author_name'] = $this->authorName();

        $result = $this->qnaService->addReply(
            $domainId,
            $inquiryId,
            AuthorType::Member,
            (int) $this->auth->id(),
            $data,
            $request->getClientIp()
        );

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 본인 문의 삭제  DELETE /qna/{id} */
    public function destroy(array $params, Context $context): JsonResponse
    {
        if (!$this->auth->check()) {
            return JsonResponse::error('로그인이 필요합니다.');
        }

        $domainId  = $context->getDomainId() ?? 1;
        $inquiryId = (int) ($params['id'] ?? 0);

        $result = $this->qnaService->deleteOwnInquiry($domainId, $inquiryId, (int) $this->auth->id());

        return $result->isSuccess()
            ? JsonResponse::success([], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 로그인 회원의 표시 이름 (닉네임 → 이름 순) */
    private function authorName(): ?string
    {
        $user = $this->auth->currentUser();
        if (!$user) {
            return null;
        }
        $name = $user->publicDisplayName();
        return $name !== '' ? $name : null;
    }

    /** 스킨 뷰 경로 해석 — plugin_qna_configs.skin_name 기준, 없으면 basic 폴백. */
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
