<?php
namespace Mublo\Plugin\Survey\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\BaseRepository;

class SurveyResponseRepository extends BaseRepository
{
    protected string $table = 'survey_responses';
    protected string $primaryKey = 'response_id';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    protected function getCreatedAtField(): ?string { return null; }
    protected function getUpdatedAtField(): ?string { return null; }

    public function countBySurvey(int $surveyId): int
    {
        return (int) $this->db->table($this->table)
            ->where('survey_id', '=', $surveyId)
            ->count();
    }

    /** 회원 중복 참여 확인 */
    public function existsByMember(int $surveyId, int $memberId): bool
    {
        return $this->db->table($this->table)
            ->where('survey_id', '=', $surveyId)
            ->where('member_id', '=', $memberId)
            ->count() > 0;
    }

    /** 비회원 IP 중복 참여 확인 */
    public function existsByIp(int $surveyId, string $ip): bool
    {
        return $this->db->table($this->table)
            ->where('survey_id', '=', $surveyId)
            ->whereNull('member_id')
            ->where('ip_address', '=', $ip)
            ->count() > 0;
    }

    public function createResponse(array $data): int
    {
        return (int) $this->create($data);
    }

    public function deleteBySurvey(int $surveyId): int
    {
        return $this->db->table($this->table)
            ->where('survey_id', '=', $surveyId)
            ->delete();
    }

    /** 결과 집계용: 설문의 모든 응답 ID 목록 */
    public function findIdsBySurvey(int $surveyId): array
    {
        $rows = $this->db->table($this->table)
            ->where('survey_id', '=', $surveyId)
            ->get();
        return array_column($rows, 'response_id');
    }
}
