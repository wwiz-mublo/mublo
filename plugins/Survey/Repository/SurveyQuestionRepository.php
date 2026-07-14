<?php
namespace Mublo\Plugin\Survey\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\Survey\Entity\SurveyQuestion;
use Mublo\Repository\BaseRepository;

class SurveyQuestionRepository extends BaseRepository
{
    protected string $table = 'survey_questions';
    protected string $entityClass = SurveyQuestion::class;
    protected string $primaryKey = 'question_id';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    protected function getCreatedAtField(): ?string { return null; }
    protected function getUpdatedAtField(): ?string { return null; }

    /** survey_id에 속한 질문을 sort_order 순으로 반환 */
    public function findBySurvey(int $surveyId): array
    {
        return $this->db->table($this->table)
            ->where('survey_id', '=', $surveyId)
            ->orderBy('sort_order', 'ASC')
            ->get();
    }

    public function createQuestion(array $data): int
    {
        return (int) $this->create($data);
    }

    public function updateQuestion(int $questionId, int $surveyId, array $data): int
    {
        return $this->db->table($this->table)
            ->where('question_id', '=', $questionId)
            ->where('survey_id', '=', $surveyId)
            ->update($data);
    }

    public function deleteBySurvey(int $surveyId): int
    {
        return $this->db->table($this->table)
            ->where('survey_id', '=', $surveyId)
            ->delete();
    }

    /** 드래그앤드롭 순서 저장: [[question_id, sort_order], ...] */
    public function updateSortOrders(int $surveyId, array $orders): void
    {
        foreach ($orders as $order) {
            $this->db->table($this->table)
                ->where('question_id', '=', (int) $order['question_id'])
                ->where('survey_id', '=', $surveyId)
                ->update(['sort_order' => (int) $order['sort_order']]);
        }
    }
}
