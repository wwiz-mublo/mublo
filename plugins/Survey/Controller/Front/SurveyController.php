<?php
namespace Mublo\Plugin\Survey\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Plugin\Survey\Entity\Survey;
use Mublo\Plugin\Survey\Repository\SurveyConfigRepository;
use Mublo\Plugin\Survey\Service\SurveyService;
use Mublo\Plugin\Survey\Service\SurveySubmitService;
use Mublo\Contract\Auth\AuthContextInterface;

class SurveyController
{
    private const SKIN_BASE_PATH = MUBLO_PLUGIN_PATH . '/Survey/views/Front/skins/';

    public function __construct(
        private SurveyService          $surveyService,
        private SurveySubmitService    $submitService,
        private AuthContextInterface            $authService,
        private SurveyConfigRepository $configRepo,
    ) {}

    /** 설문 폼 독립 페이지 */
    public function show(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $surveyId = (int) ($params['id'] ?? 0);
        $domainId = $context->getDomainId() ?? 1;
        $request  = $context->getRequest();

        $result = $this->surveyService->getDetail($domainId, $surveyId);
        if ($result->isFailure()) {
            return RedirectResponse::to('/');
        }

        $survey = Survey::fromArray($result->get('survey'));
        if ($survey->getStatus()->value === 'draft') {
            return RedirectResponse::to('/');
        }

        $memberId = $this->authService->id();
        $ip       = $request->getClientIp();

        $canJoin = $this->submitService->canParticipate($surveyId, $domainId, $memberId, $ip);

        return ViewResponse::absoluteView($this->getSkinViewPath($domainId))->withData([
            'survey'        => $result->get('survey'),
            'questions'     => $result->get('questions', []),
            'responseCount' => $result->get('responseCount', 0),
            'canJoin'       => $canJoin->isSuccess(),
            'joinMessage'   => $canJoin->isFailure() ? $canJoin->getMessage() : '',
        ]);
    }

    /**
     * 스킨 뷰 경로 해석 — plugin_survey_configs.skin_name 기준, 없으면 basic 폴백.
     */
    private function getSkinViewPath(int $domainId): string
    {
        $skin = $this->configRepo->getSkinName($domainId);
        $path = self::SKIN_BASE_PATH . $skin . '/Show';

        if (!is_file($path . '.php')) {
            $path = self::SKIN_BASE_PATH . 'basic/Show';
        }

        return $path;
    }

    /** 설문 제출 (AJAX JSON) */
    public function submit(array $params, Context $context): JsonResponse
    {
        $surveyId = (int) ($params['id'] ?? 0);
        $domainId = $context->getDomainId() ?? 1;
        $request  = $context->getRequest();

        $memberId = $this->authService->id();
        $ip       = $request->getClientIp();

        $payload = $request->json() ?? [];
        $answers = $payload['answers'] ?? [];

        $normalized = [];
        foreach ($answers as $qid => $val) {
            $normalized[(int) $qid] = $val;
        }

        $result = $this->submitService->submit($surveyId, $domainId, $normalized, $memberId, $ip);

        // 제출 실패는 서버 오류가 아니라 사용자 조건 안내(필수 미선택·이미 참여·기간 등)
        // → 422로 반환해 프론트가 warning(노랑)으로 표시하게 한다.
        return $result->isSuccess()
            ? JsonResponse::success([], $result->getMessage())
            : JsonResponse::error($result->getMessage(), null, 422);
    }
}
