<?php
namespace Mublo\Plugin\Survey\Service;

use Mublo\Core\Result\Result;
use Mublo\Plugin\Survey\Enum\QuestionType;
use Mublo\Plugin\Survey\Repository\SurveyRepository;
use Mublo\Plugin\Survey\Repository\SurveyQuestionRepository;
use Mublo\Plugin\Survey\Repository\SurveyResponseRepository;
use Mublo\Plugin\Survey\Repository\SurveyAnswerRepository;

class SurveyResultService
{
    public function __construct(
        private SurveyRepository         $surveyRepo,
        private SurveyQuestionRepository  $questionRepo,
        private SurveyResponseRepository  $responseRepo,
        private SurveyAnswerRepository    $answerRepo,
    ) {}

    /**
     * 설문 결과 집계 반환
     *
     * 반환 data 구조:
     * [
     *   'survey'          => [...],
     *   'total_responses' => int,
     *   'questions'       => [
     *     [
     *       'question_id' => int,
     *       'type'        => string,
     *       'title'       => string,
     *       'options'     => [...],   // radio/checkbox/select일 때
     *       'stats'       => [        // 선택형: [label, count, pct]
     *                                 // 텍스트형: [text, ...]
     *                                 // rating: avg
     *         ...
     *       ],
     *     ],
     *   ],
     * ]
     */
    public function getStats(int $domainId, int $surveyId): Result
    {
        $survey = $this->surveyRepo->findScoped($surveyId, $domainId);
        if (!$survey) {
            return Result::failure('설문을 찾을 수 없습니다.');
        }

        $questions = $this->questionRepo->findBySurvey($surveyId);
        $totalResponses = $this->responseRepo->countBySurvey($surveyId);

        $questionIds = array_column($questions, 'question_id');
        $aggregated  = $this->answerRepo->aggregateBySurveyQuestions($questionIds);

        $result = [];
        foreach ($questions as $q) {
            $qid  = (int) $q['question_id'];
            $type = QuestionType::from($q['type']);
            $raw  = $aggregated[$qid] ?? ['text_answers' => [], 'opt_counts' => []];

            $options = [];
            if (is_string($q['options'] ?? null)) {
                $options = json_decode($q['options'], true) ?? [];
            } elseif (is_array($q['options'] ?? null)) {
                $options = $q['options'];
            }

            $result[] = [
                'question_id' => $qid,
                'type'        => $type->value,
                'title'       => $q['title'],
                'description' => $q['description'] ?? '',
                'options'     => $options,
                'stats'       => $this->buildStats($type, $options, $raw, $totalResponses),
            ];
        }

        return Result::success('', [
            'survey'          => $survey,
            'total_responses' => $totalResponses,
            'questions'       => $result,
        ]);
    }

    // -------------------------------------------------------------------------

    private function buildStats(QuestionType $type, array $options, array $raw, int $totalResponses): array
    {
        if ($type->hasOptions()) {
            return $this->buildChoiceStats($options, $raw['opt_counts'], $totalResponses);
        }

        if ($type === QuestionType::Rating) {
            return $this->buildRatingStats($raw['text_answers']);
        }

        // text / textarea: 최근 답변 최대 50개 반환
        return array_slice($raw['text_answers'], 0, 50);
    }

    /** radio/checkbox/select 집계 → [label, count, pct] */
    private function buildChoiceStats(array $options, array $optCounts, int $totalResponses): array
    {
        $stats = [];
        foreach ($options as $idx => $label) {
            $count = $optCounts[$idx] ?? 0;
            $pct   = $totalResponses > 0 ? round($count / $totalResponses * 100, 1) : 0.0;
            $stats[] = [
                'label' => (string) $label,
                'count' => $count,
                'pct'   => $pct,
            ];
        }
        return $stats;
    }

    /** rating 집계 → [avg, distribution[1..5]] */
    private function buildRatingStats(array $textAnswers): array
    {
        if (empty($textAnswers)) {
            return ['avg' => 0, 'distribution' => []];
        }

        $dist  = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $total = 0;
        $sum   = 0;

        foreach ($textAnswers as $val) {
            $score = (int) $val;
            if ($score >= 1 && $score <= 5) {
                $dist[$score]++;
                $sum += $score;
                $total++;
            }
        }

        return [
            'avg'          => $total > 0 ? round($sum / $total, 2) : 0,
            'distribution' => $dist,
        ];
    }
}
