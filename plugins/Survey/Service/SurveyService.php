<?php
namespace Mublo\Plugin\Survey\Service;

use Mublo\Core\Result\Result;
use Mublo\Plugin\Survey\Enum\SurveyStatus;
use Mublo\Plugin\Survey\Enum\QuestionType;
use Mublo\Plugin\Survey\Repository\SurveyRepository;
use Mublo\Plugin\Survey\Repository\SurveyQuestionRepository;
use Mublo\Plugin\Survey\Repository\SurveyResponseRepository;
use Mublo\Plugin\Survey\Repository\SurveyAnswerRepository;

class SurveyService
{
    public function __construct(
        private SurveyRepository         $surveyRepo,
        private SurveyQuestionRepository $questionRepo,
        private SurveyResponseRepository $responseRepo,
        private SurveyAnswerRepository   $answerRepo,
    ) {}

    public function getList(int $domainId, int $page = 1, int $perPage = 20, string $keyword = ''): Result
    {
        $data = $this->surveyRepo->findByDomain($domainId, $page, $perPage, $keyword);
        return Result::success('', [
            'items'       => $data['items'],
            'totalItems'  => $data['total'],
            'currentPage' => $page,
            'perPage'     => $perPage,
            'totalPages'  => (int) ceil(($data['total'] ?: 0) / $perPage),
        ]);
    }

    public function getDetail(int $domainId, int $surveyId): Result
    {
        $survey = $this->surveyRepo->findScoped($surveyId, $domainId);
        if (!$survey) {
            return Result::failure('설문을 찾을 수 없습니다.');
        }

        $questions     = $this->questionRepo->findBySurvey($surveyId);
        $questions     = array_map([$this, 'normalizeQuestion'], $questions);
        $responseCount = $this->responseRepo->countBySurvey($surveyId);

        return Result::success('', [
            'survey'        => $survey,
            'questions'     => $questions,
            'responseCount' => $responseCount,
        ]);
    }

    public function create(int $domainId, array $input): Result
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            return Result::failure('설문 제목은 필수입니다.');
        }

        $statusValue = $input['status'] ?? SurveyStatus::Draft->value;
        if (!in_array($statusValue, array_column(SurveyStatus::cases(), 'value'), true)) {
            $statusValue = SurveyStatus::Draft->value;
        }

        $surveyId = $this->surveyRepo->createSurvey([
            'domain_id'       => $domainId,
            'title'           => $title,
            'description'     => trim((string) ($input['description'] ?? '')),
            'status'          => $statusValue,
            'allow_anonymous' => (int) (bool) ($input['allow_anonymous'] ?? true),
            'allow_duplicate' => (int) (bool) ($input['allow_duplicate'] ?? false),
            'response_limit'  => max(0, (int) ($input['response_limit'] ?? 0)),
            'start_at'        => $this->normalizeDate($input['start_at'] ?? null),
            'end_at'          => $this->normalizeDate($input['end_at'] ?? null),
        ]);

        $questions = $input['questions'] ?? [];
        $this->saveQuestions($surveyId, $questions);

        return Result::success('설문이 생성되었습니다.', ['survey_id' => $surveyId]);
    }

    public function update(int $domainId, int $surveyId, array $input): Result
    {
        $survey = $this->surveyRepo->findScoped($surveyId, $domainId);
        if (!$survey) {
            return Result::failure('설문을 찾을 수 없습니다.');
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            return Result::failure('설문 제목은 필수입니다.');
        }

        $statusValue = $input['status'] ?? $survey['status'];
        if (!in_array($statusValue, array_column(SurveyStatus::cases(), 'value'), true)) {
            $statusValue = $survey['status'];
        }

        $this->surveyRepo->updateSurvey($surveyId, $domainId, [
            'title'           => $title,
            'description'     => trim((string) ($input['description'] ?? '')),
            'status'          => $statusValue,
            'allow_anonymous' => (int) (bool) ($input['allow_anonymous'] ?? true),
            'allow_duplicate' => (int) (bool) ($input['allow_duplicate'] ?? false),
            'response_limit'  => max(0, (int) ($input['response_limit'] ?? 0)),
            'start_at'        => $this->normalizeDate($input['start_at'] ?? null),
            'end_at'          => $this->normalizeDate($input['end_at'] ?? null),
        ]);

        $questions = $input['questions'] ?? [];
        $this->questionRepo->deleteBySurvey($surveyId);
        $this->saveQuestions($surveyId, $questions);

        return Result::success('설문이 수정되었습니다.', ['survey_id' => $surveyId]);
    }

    public function changeStatus(int $domainId, int $surveyId, string $status): Result
    {
        if (!in_array($status, array_column(SurveyStatus::cases(), 'value'), true)) {
            return Result::failure('유효하지 않은 상태값입니다.');
        }

        $survey = $this->surveyRepo->findScoped($surveyId, $domainId);
        if (!$survey) {
            return Result::failure('설문을 찾을 수 없습니다.');
        }

        $this->surveyRepo->updateSurvey($surveyId, $domainId, ['status' => $status]);
        return Result::success(SurveyStatus::from($status)->label() . ' 상태로 변경되었습니다.');
    }

    public function delete(int $domainId, int $surveyId): Result
    {
        $survey = $this->surveyRepo->findScoped($surveyId, $domainId);
        if (!$survey) {
            return Result::failure('설문을 찾을 수 없습니다.');
        }

        // 연관 데이터 삭제
        $responseIds = $this->responseRepo->findIdsBySurvey($surveyId);
        $this->answerRepo->deleteByResponseIds($responseIds);
        $this->responseRepo->deleteBySurvey($surveyId);
        $this->questionRepo->deleteBySurvey($surveyId);
        $this->surveyRepo->deleteSurvey($surveyId, $domainId);

        return Result::success('설문이 삭제되었습니다.');
    }

    /** 드래그앤드롭 순서 변경 */
    public function updateQuestionOrder(int $domainId, int $surveyId, array $orders): Result
    {
        if (!$this->surveyRepo->findScoped($surveyId, $domainId)) {
            return Result::failure('설문을 찾을 수 없습니다.');
        }

        $this->questionRepo->updateSortOrders($surveyId, $orders);
        return Result::success('순서가 저장되었습니다.');
    }

    // -------------------------------------------------------------------------

    private function saveQuestions(int $surveyId, array $questions): void
    {
        foreach ($questions as $idx => $q) {
            $type = QuestionType::tryFrom($q['type'] ?? '') ?? QuestionType::Text;
            $options = $type->hasOptions() ? ($q['options'] ?? []) : null;

            $this->questionRepo->createQuestion([
                'survey_id'   => $surveyId,
                'type'        => $type->value,
                'title'       => trim((string) ($q['title'] ?? '')),
                'description' => trim((string) ($q['description'] ?? '')),
                'options'     => $options !== null ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
                'required'    => (int) (bool) ($q['required'] ?? true),
                'sort_order'  => $idx,
            ]);
        }
    }

    private function normalizeQuestion(array $row): array
    {
        if (isset($row['options']) && is_string($row['options'])) {
            $row['options'] = json_decode($row['options'], true) ?? [];
        }
        return $row;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (empty($value) || !is_string($value)) {
            return null;
        }
        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }
}
