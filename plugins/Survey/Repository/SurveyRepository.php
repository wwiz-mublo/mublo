<?php
namespace Mublo\Plugin\Survey\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\Survey\Entity\Survey;
use Mublo\Repository\BaseRepository;

class SurveyRepository extends BaseRepository
{
    protected string $table = 'surveys';
    protected string $entityClass = Survey::class;
    protected string $primaryKey = 'survey_id';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    public function findScoped(int $surveyId, int $domainId): ?array
    {
        return $this->db->table($this->table)
            ->where('survey_id', '=', $surveyId)
            ->where('domain_id', '=', $domainId)
            ->first();
    }

    public function findByDomain(int $domainId, int $page = 1, int $perPage = 20, string $keyword = ''): array
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId);

        if ($keyword !== '') {
            $query->where('title', 'LIKE', '%' . $keyword . '%');
        }

        $total = $query->count();
        $items = $query->orderBy('survey_id', 'DESC')
            ->forPage($page, $perPage)
            ->get();

        return ['items' => $items, 'total' => $total];
    }

    /** 블록 ItemsProvider용: 도메인의 전체 설문 목록 (최대 200개) */
    public function findAllByDomain(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('survey_id', 'DESC')
            ->forPage(1, 200)
            ->get();
    }

    public function createSurvey(array $data): int
    {
        return (int) $this->create($data);
    }

    public function updateSurvey(int $surveyId, int $domainId, array $data): int
    {
        return $this->db->table($this->table)
            ->where('survey_id', '=', $surveyId)
            ->where('domain_id', '=', $domainId)
            ->update($data);
    }

    public function deleteSurvey(int $surveyId, int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('survey_id', '=', $surveyId)
            ->where('domain_id', '=', $domainId)
            ->delete();
    }
}
