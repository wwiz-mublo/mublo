<?php
namespace Mublo\Plugin\Survey\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Plugin\Survey\Enum\QuestionType;
use Mublo\Plugin\Survey\Enum\SurveyStatus;
use Mublo\Plugin\Survey\Repository\SurveyConfigRepository;
use Mublo\Plugin\Survey\Service\SurveyResultService;
use Mublo\Plugin\Survey\Service\SurveyService;

class SurveyAdminController
{
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/Survey/views/Admin/';
    private const SKIN_BASE_PATH = MUBLO_PLUGIN_PATH . '/Survey/views/Front/skins/';
    private const MIGRATION_PATH = MUBLO_PLUGIN_PATH . '/Survey/database/migrations';

    public function __construct(
        private SurveyService          $surveyService,
        private SurveyResultService    $resultService,
        private MigrationRunner        $migrationRunner,
        private SurveyConfigRepository $configRepo,
    ) {}

    /** 설문 목록 */
    public function index(array $params, Context $context): ViewResponse
    {
        if ($this->hasPendingMigrations()) {
            return $this->installView();
        }

        $request  = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $page     = max(1, (int) $request->query('page', 1));
        $keyword  = trim((string) $request->query('keyword', ''));

        $result = $this->surveyService->getList($domainId, $page, 20, $keyword);

        return ViewResponse::absoluteView(self::VIEW_PATH . 'List')->withData([
            'pageTitle'  => '설문 관리',
            'items'      => $result->get('items', []),
            'pagination' => [
                'totalItems'  => $result->get('totalItems', 0),
                'currentPage' => $result->get('currentPage', 1),
                'totalPages'  => $result->get('totalPages', 1),
                'perPage'     => 20,
            ],
            'search'  => ['keyword' => $keyword],
            'statuses' => SurveyStatus::options(),
            'listQuery' => $this->buildListQuery($context),
            'skins'       => $this->getAvailableSkins(),
            'currentSkin' => $this->configRepo->getSkinName($domainId),
        ]);
    }

    /** 프론트 스킨 설정 저장 */
    public function saveSkin(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $data     = $context->getRequest()->json() ?: [];
        $skin     = (string) ($data['skin'] ?? 'basic');

        if (!in_array($skin, $this->getAvailableSkins(), true)) {
            return JsonResponse::error('존재하지 않는 스킨입니다.');
        }

        $this->configRepo->upsert($domainId, ['skin_name' => $skin]);

        return JsonResponse::success([], '스킨이 변경되었습니다.');
    }

    /**
     * 사용 가능한 프론트 스킨 목록 (skins/ 디렉토리 스캔)
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

    /** 설문 생성 폼 */
    public function create(array $params, Context $context): ViewResponse
    {
        return ViewResponse::absoluteView(self::VIEW_PATH . 'Form')->withData([
            'pageTitle'     => '새 설문 만들기',
            'survey'        => null,
            'questions'     => [],
            'statusOptions' => SurveyStatus::options(),
            'typeOptions'   => QuestionType::options(),
        ]);
    }

    /** 설문 생성 저장 */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $payload  = $context->getRequest()->json() ?? [];

        $result = $this->surveyService->create($domainId, $payload);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 설문 편집 폼 */
    public function edit(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $surveyId = (int) ($params['id'] ?? 0);
        $domainId = $context->getDomainId() ?? 1;
        $listUrl  = $this->resolveListUrl($context);

        $result = $this->surveyService->getDetail($domainId, $surveyId);
        if ($result->isFailure()) {
            return RedirectResponse::to($listUrl);
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Form')->withData([
            'pageTitle'     => '설문 편집',
            'survey'        => $result->get('survey'),
            'questions'     => $result->get('questions', []),
            'statusOptions' => SurveyStatus::options(),
            'typeOptions'   => QuestionType::options(),
            'listUrl'       => $listUrl,
        ]);
    }

    /** 목록의 현재 검색/페이징을 쿼리스트링으로 (목록 뷰가 return 파라미터로 실어보냄) */
    private function buildListQuery(Context $context): string
    {
        $request = $context->getRequest();
        $keyword = trim((string) $request->query('keyword', ''));
        $page    = (int) $request->query('page', 1);

        return http_build_query(array_filter([
            'keyword' => $keyword !== '' ? $keyword : null,
            'page'    => $page > 1 ? $page : null,
        ]));
    }

    /** return 파라미터를 open-redirect 방어하여 안전한 목록 복귀 URL로 (편집/결과 → 목록 유지) */
    private function resolveListUrl(Context $context): string
    {
        $return = (string) ($context->getRequest()->get('return') ?? '');

        return ($return === '/admin/survey/surveys' || str_starts_with($return, '/admin/survey/surveys?'))
            ? $return
            : '/admin/survey/surveys';
    }

    /** 설문 편집 저장 */
    public function update(array $params, Context $context): JsonResponse
    {
        $surveyId = (int) ($params['id'] ?? 0);
        $domainId = $context->getDomainId() ?? 1;
        $payload  = $context->getRequest()->json() ?? [];

        $result = $this->surveyService->update($domainId, $surveyId, $payload);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 상태 변경 (draft/active/closed) */
    public function changeStatus(array $params, Context $context): JsonResponse
    {
        $surveyId = (int) ($params['id'] ?? 0);
        $domainId = $context->getDomainId() ?? 1;
        $data     = $context->getRequest()->json() ?? [];
        $status   = (string) ($data['status'] ?? '');

        $result = $this->surveyService->changeStatus($domainId, $surveyId, $status);

        return $result->isSuccess()
            ? JsonResponse::success([], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 질문 순서 저장 (드래그앤드롭) */
    public function updateOrder(array $params, Context $context): JsonResponse
    {
        $surveyId = (int) ($params['id'] ?? 0);
        $domainId = $context->getDomainId() ?? 1;
        $data     = $context->getRequest()->json() ?? [];
        $orders   = $data['orders'] ?? [];

        $result = $this->surveyService->updateQuestionOrder($domainId, $surveyId, $orders);

        return $result->isSuccess()
            ? JsonResponse::success([], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 설문 삭제 */
    public function delete(array $params, Context $context): JsonResponse
    {
        $surveyId = (int) ($params['id'] ?? 0);
        $domainId = $context->getDomainId() ?? 1;

        $result = $this->surveyService->delete($domainId, $surveyId);

        return $result->isSuccess()
            ? JsonResponse::success([], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /** 선택 삭제 (chk[] 반복 삭제) */
    public function listDelete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $chk      = $context->getRequest()->input('chk') ?? [];

        if (empty($chk)) {
            return JsonResponse::error('삭제할 설문을 선택해주세요.');
        }

        $deleted = 0;
        $failed  = 0;
        foreach ($chk as $surveyId) {
            $result = $this->surveyService->delete($domainId, (int) $surveyId);
            $result->isSuccess() ? $deleted++ : $failed++;
        }

        if ($deleted > 0) {
            $message = "{$deleted}개 설문이 삭제되었습니다.";
            if ($failed > 0) {
                $message .= " ({$failed}개 실패)";
            }
            return JsonResponse::success(['deleted' => $deleted], $message);
        }

        return JsonResponse::error('삭제할 수 있는 설문이 없습니다.');
    }

    /** 결과 통계 페이지 */
    public function result(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $surveyId = (int) ($params['id'] ?? 0);
        $domainId = $context->getDomainId() ?? 1;
        $listUrl  = $this->resolveListUrl($context);

        $result = $this->resultService->getStats($domainId, $surveyId);
        if ($result->isFailure()) {
            return RedirectResponse::to($listUrl);
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Result')->withData([
            'pageTitle'      => '설문 결과',
            'survey'         => $result->get('survey'),
            'totalResponses' => $result->get('total_responses', 0),
            'questions'      => $result->get('questions', []),
            'listUrl'        => $listUrl,
        ]);
    }

    /** 설치 실행 */
    public function install(array $params, Context $context): JsonResponse
    {
        $this->migrationRunner->run('plugin', 'Survey', self::MIGRATION_PATH);
        return JsonResponse::success([], '설치가 완료되었습니다.');
    }

    // -------------------------------------------------------------------------

    private function hasPendingMigrations(): bool
    {
        $status = $this->migrationRunner->getStatus('plugin', 'Survey', self::MIGRATION_PATH);
        return !empty($status['pending']);
    }

    private function installView(): ViewResponse
    {
        return ViewResponse::absoluteView(self::VIEW_PATH . 'Install')->withData([
            'pageTitle'   => '설문 플러그인 설치',
            'installUrl'  => '/admin/survey/install',
        ]);
    }
}
