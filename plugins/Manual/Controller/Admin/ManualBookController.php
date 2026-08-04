<?php
declare(strict_types=1);
namespace Mublo\Plugin\Manual\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Result\Result;
use Mublo\Helper\Form\FormHelper;
use Mublo\Plugin\Manual\Repository\ManualConfigRepository;
use Mublo\Plugin\Manual\Service\ManualService;

/**
 * 매뉴얼 책 관리 Controller (Admin)
 */
class ManualBookController
{
    private const PLUGIN_NAME = 'Manual';
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/Manual/views/Admin/';
    private const SKIN_BASE_PATH = MUBLO_PLUGIN_PATH . '/Manual/views/Front/skins/';

    public function __construct(
        private ManualService          $manualService,
        private MigrationRunner        $migrationRunner,
        private ManualConfigRepository $configRepo,
    ) {
    }

    private function getMigrationPath(): string
    {
        return MUBLO_PLUGIN_PATH . '/Manual/database/migrations';
    }

    /**
     * 책 목록 (관리자 진입점)
     *
     * GET /admin/manual
     */
    public function index(array $params, Context $context): ViewResponse
    {
        // 마이그레이션 체크
        $status = $this->migrationRunner->getStatus('plugin', self::PLUGIN_NAME, $this->getMigrationPath());
        if (!empty($status['pending'])) {
            return ViewResponse::absoluteView(self::VIEW_PATH . 'Install')
                ->withData([
                    'pageTitle' => '매뉴얼 플러그인 설치',
                    'pending'   => $status['pending'],
                ]);
        }

        $domainId = $context->getDomainId() ?? 1;
        $this->manualService->ensureDefaultManuals($domainId);
        $result = $this->manualService->getBookList($domainId);

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Book/List')
            ->withData([
                'pageTitle' => '매뉴얼 관리',
                'books'     => $result->get('books', []),
                'bundledManuals' => [
                    [
                        'title' => '스킨 제작 가이드',
                        'icon' => 'bi-palette',
                        'route' => '/admin/manual/import/skin-development',
                        'installed' => $this->manualService->hasSkinDevelopmentTutorial($domainId),
                    ],
                    [
                        'title' => '게시판 매뉴얼',
                        'icon' => 'bi-chat-square-text',
                        'route' => '/admin/manual/import/board',
                        'installed' => $this->manualService->hasBoardManual($domainId),
                    ],
                    [
                        'title' => 'Mublo Shop 매뉴얼',
                        'icon' => 'bi-shop',
                        'route' => '/admin/manual/import/shop',
                        'installed' => $this->manualService->hasShopManual($domainId),
                    ],
                ],
                'skins'       => $this->getAvailableSkins(),
                'currentSkin' => $this->configRepo->getSkinName($domainId),
            ]);
    }

    /**
     * 프론트 스킨 설정 저장
     *
     * PUT /admin/manual/config
     */
    public function saveConfig(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $data = $context->getRequest()->json() ?: [];

        if (isset($data['skin_name']) && !in_array($data['skin_name'], $this->getAvailableSkins(), true)) {
            return JsonResponse::error('존재하지 않는 스킨입니다.');
        }

        $this->configRepo->upsert($domainId, $data);

        return JsonResponse::success([], '설정이 저장되었습니다.');
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

    /**
     * 번들 스킨 제작 가이드를 현재 도메인의 매뉴얼로 가져온다.
     *
     * POST /admin/manual/import/skin-development
     */
    public function importSkinDevelopment(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        return $this->importResponse($this->manualService->importSkinDevelopmentTutorial($domainId));
    }

    /**
     * 번들 게시판 매뉴얼을 현재 도메인의 매뉴얼로 가져온다.
     *
     * POST /admin/manual/import/board
     */
    public function importBoard(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        return $this->importResponse($this->manualService->importBoardManual($domainId));
    }

    /**
     * 번들 쇼핑몰 매뉴얼을 현재 도메인의 매뉴얼로 가져온다.
     *
     * POST /admin/manual/import/shop
     */
    public function importShop(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        return $this->importResponse($this->manualService->importShopManual($domainId));
    }

    private function importResponse(Result $result): JsonResponse
    {
        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 플러그인 설치 (마이그레이션 실행)
     *
     * POST /admin/manual/install
     */
    public function install(array $params, Context $context): JsonResponse
    {
        $result = $this->migrationRunner->run('plugin', self::PLUGIN_NAME, $this->getMigrationPath());

        if ($result['success']) {
            return JsonResponse::success(
                ['redirect' => '/admin/manual'],
                '매뉴얼 플러그인이 설치되었습니다. (실행: ' . count($result['executed']) . '개)'
            );
        }

        return JsonResponse::error('설치 실패: ' . ($result['error'] ?? '알 수 없는 오류'));
    }

    /**
     * 책 생성 폼
     *
     * GET /admin/manual/book/create
     */
    public function create(array $params, Context $context): ViewResponse
    {
        return ViewResponse::absoluteView(self::VIEW_PATH . 'Book/Form')
            ->withData([
                'pageTitle' => '매뉴얼 등록',
                'book'      => null,
            ]);
    }

    /**
     * 책 수정 폼
     *
     * GET /admin/manual/book/edit/{id}
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $bookId = (int) ($params['id'] ?? 0);

        $result = $this->manualService->getBook($domainId, $bookId);
        if (!$result->isSuccess()) {
            return ViewResponse::absoluteView(self::VIEW_PATH . 'Book/List')
                ->withData([
                    'pageTitle' => '매뉴얼 관리',
                    'books'     => $this->manualService->getBookList($domainId)->get('books', []),
                    'flashError' => $result->getMessage(),
                ]);
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Book/Form')
            ->withData([
                'pageTitle' => '매뉴얼 수정',
                'book'      => $result->get('book'),
            ]);
    }

    /**
     * 책 생성
     *
     * POST /admin/manual/book
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $data = $this->formData($context);

        $result = $this->manualService->createBook($domainId, $data);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 책 수정
     *
     * PUT /admin/manual/book
     */
    public function update(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $data = $this->formData($context);
        $bookId = (int) ($data['book_id'] ?? 0);

        if ($bookId <= 0) {
            return JsonResponse::error('매뉴얼을 선택해 주세요.');
        }

        $result = $this->manualService->updateBook($domainId, $bookId, $data);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 프론트 노출 상태 변경
     *
     * POST /admin/manual/book/toggle-active
     */
    public function toggleActive(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $raw = $request->json() ?: ($request->input('formData') ?? []);
        $data = FormHelper::normalizeFormData($raw, [
            'numeric' => ['book_id', 'is_active'],
        ]);

        $bookId = (int) ($data['book_id'] ?? 0);
        $isActive = (int) ($data['is_active'] ?? -1);
        if ($bookId <= 0 || !in_array($isActive, [0, 1], true)) {
            return JsonResponse::error('매뉴얼과 노출 상태를 확인해 주세요.');
        }

        $result = $this->manualService->setBookActive($domainId, $bookId, $isActive === 1);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 책 삭제
     *
     * DELETE /admin/manual/book
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $data = $this->formData($context);
        $bookId = (int) ($data['book_id'] ?? 0);

        if ($bookId <= 0) {
            return JsonResponse::error('매뉴얼을 선택해 주세요.');
        }

        $result = $this->manualService->deleteBook($domainId, $bookId);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 요청 본문 정규화 (JSON 또는 formData)
     */
    private function formData(Context $context): array
    {
        $request = $context->getRequest();
        $raw = $request->json() ?: ($request->input('formData') ?? []);

        return FormHelper::normalizeFormData($raw, [
            'numeric'         => ['book_id', 'sort_order', 'is_active'],
            'required_string' => ['title', 'slug'],
        ]);
    }
}
