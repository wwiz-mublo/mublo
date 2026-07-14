<?php
namespace Mublo\Plugin\Survey\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\BaseRepository;

class SurveyAnswerRepository extends BaseRepository
{
    protected string $table = 'survey_answers';
    protected string $primaryKey = 'answer_id';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    protected function getCreatedAtField(): ?string { return null; }
    protected function getUpdatedAtField(): ?string { return null; }

    public function createAnswer(array $data): int
    {
        return (int) $this->create($data);
    }

    /** 한 응답 세션의 모든 답변 일괄 저장 */
    public function createBulk(array $rows): void
    {
        foreach ($rows as $row) {
            $this->create($row);
        }
    }

    /**
     * 결과 집계: 질문별 답변 집계
     * 반환: [question_id => ['text_answers'=>[], 'opt_counts'=>[optIndex=>count]]]
     */
    public function aggregateBySurveyQuestions(array $questionIds): array
    {
        if (empty($questionIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $rows = $this->db->select(
            "SELECT question_id, answer_text, answer_opts
               FROM {$this->table}
              WHERE question_id IN ({$placeholders})",
            $questionIds
        );

        $result = [];
        foreach ($rows as $row) {
            $qid = (int) $row['question_id'];
            if (!isset($result[$qid])) {
                $result[$qid] = ['text_answers' => [], 'opt_counts' => []];
            }

            if ($row['answer_opts'] !== null) {
                $opts = json_decode($row['answer_opts'], true) ?? [];
                foreach ($opts as $idx) {
                    $result[$qid]['opt_counts'][(int) $idx] =
                        ($result[$qid]['opt_counts'][(int) $idx] ?? 0) + 1;
                }
            } elseif ($row['answer_text'] !== null && $row['answer_text'] !== '') {
                $result[$qid]['text_answers'][] = $row['answer_text'];
            }
        }

        return $result;
    }

    public function deleteByResponseIds(array $responseIds): void
    {
        if (empty($responseIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($responseIds), '?'));
        $this->db->execute(
            "DELETE FROM {$this->table} WHERE response_id IN ({$placeholders})",
            $responseIds
        );
    }
}
