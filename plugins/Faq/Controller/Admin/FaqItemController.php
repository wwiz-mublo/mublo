<?php
namespace Mublo\Plugin\Faq\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Plugin\Faq\Repository\FaqConfigRepository;
use Mublo\Plugin\Faq\Service\FaqService;

/**
 * FAQ 항목 관리 Controller (Admin)
 */
class FaqItemController
{
    private FaqService $faqService;
    private MigrationRunner $migrationRunner;
    private FaqConfigRepository $configRepo;

    private const PLUGIN_NAME = 'Faq';
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/Faq/views/Admin/';
    private const SKIN_BASE_PATH = MUBLO_PLUGIN_PATH . '/Faq/views/Front/skins/';

    public function __construct(
        FaqService $faqService,
        MigrationRunner $migrationRunner,
        FaqConfigRepository $configRepo
    ) {
        $this->faqService = $faqService;
        $this->migrationRunner = $migrationRunner;
        $this->configRepo = $configRepo;
    }

    private function getMigrationPath(): string
    {
        return MUBLO_PLUGIN_PATH . '/Faq/database/migrations';
    }

    /**
     * FAQ 관리 페이지 (카테고리 + 항목 통합)
     */
    public function index(array $params, Context $context): ViewResponse
    {
        // 마이그레이션 체크
        $status = $this->migrationRunner->getStatus('plugin', self::PLUGIN_NAME, $this->getMigrationPath());
        if (!empty($status['pending'])) {
            return ViewResponse::absoluteView(self::VIEW_PATH . 'Install')
                ->withData([
                    'pageTitle' => 'FAQ 플러그인 설치',
                    'pending' => $status['pending'],
                ]);
        }

        $domainId = $context->getDomainId() ?? 1;

        $categoryResult = $this->faqService->getCategoryList($domainId);
        $categories = $categoryResult->get('categories', []);

        // 첫 번째 카테고리의 항목을 기본 로드
        $items = [];
        $activeCategoryId = null;
        if (!empty($categories)) {
            $activeCategoryId = (int) $categories[0]['category_id'];
            $itemResult = $this->faqService->getItemsByCategory($domainId, $activeCategoryId);
            $items = $itemResult->get('items', []);
        }

        // 스킨 목록 (디렉토리 스캔) + 현재 스킨 (플러그인 config)
        $skins = $this->getAvailableSkins();
        $currentSkin = $this->configRepo->getSkinName($domainId);

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Index')
            ->withData([
                'pageTitle' => 'FAQ 관리',
                'categories' => $categories,
                'items' => $items,
                'activeCategoryId' => $activeCategoryId,
                'skins' => $skins,
                'currentSkin' => $currentSkin,
            ]);
    }

    /**
     * 플러그인 설치 (마이그레이션 실행)
     */
    public function install(array $params, Context $context): JsonResponse
    {
        $result = $this->migrationRunner->run('plugin', self::PLUGIN_NAME, $this->getMigrationPath());

        if ($result['success']) {
            return JsonResponse::success(
                ['redirect' => '/admin/faq'],
                'FAQ 플러그인이 설치되었습니다. (실행: ' . count($result['executed']) . '개)'
            );
        }

        return JsonResponse::error('설치 실패: ' . ($result['error'] ?? '알 수 없는 오류'));
    }

    /**
     * 카테고리별 FAQ 항목 목록 (AJAX)
     */
    public function items(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $categoryId = (int) $request->query('category_id', 0);

        $result = $this->faqService->getItemsByCategory(
            $domainId,
            $categoryId > 0 ? $categoryId : null
        );

        return JsonResponse::success([
            'items' => $result->get('items', []),
        ]);
    }

    /**
     * FAQ 항목 생성
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $data = $request->json() ?: ($request->input('formData') ?? []);

        $result = $this->faqService->createItem($domainId, $data);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * FAQ 항목 수정
     */
    public function update(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $data = $request->json() ?: ($request->input('formData') ?? []);
        $faqId = (int) ($data['faq_id'] ?? 0);

        if ($faqId <= 0) {
            return JsonResponse::error('FAQ 항목을 선택해 주세요.');
        }

        $result = $this->faqService->updateItem($domainId, $faqId, $data);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * FAQ 항목 삭제
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $data = $request->json() ?: ($request->input('formData') ?? []);
        $faqId = (int) ($data['faq_id'] ?? 0);

        if ($faqId <= 0) {
            return JsonResponse::error('FAQ 항목을 선택해 주세요.');
        }

        $result = $this->faqService->deleteItem($domainId, $faqId);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 프론트 스킨 설정 저장
     */
    public function saveSkin(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $data = $request->json() ?: [];
        $skin = $data['skin'] ?? 'basic';

        // 스킨 디렉토리 존재 확인
        $skins = $this->getAvailableSkins();
        if (!in_array($skin, $skins, true)) {
            return JsonResponse::error('존재하지 않는 스킨입니다.');
        }

        // 플러그인 소유 config 에 저장
        $this->configRepo->upsert($domainId, ['skin_name' => $skin]);

        return JsonResponse::success([], '스킨이 변경되었습니다.');
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
     * 정렬 순서 변경 (AJAX)
     */
    public function sort(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $items = $request->json('items') ?? $request->input('items') ?? [];

        if (empty($items)) {
            return JsonResponse::error('정렬할 항목이 없습니다.');
        }

        $result = $this->faqService->updateSortOrder($domainId, $items);

        return $result->isSuccess()
            ? JsonResponse::success([], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }
}
