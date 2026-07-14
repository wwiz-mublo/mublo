<?php

namespace Mublo\Plugin\Survey\Service;

use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Infrastructure\Database\Database;

final class SurveyDataResetter implements DataResettableInterface
{
    public function __construct(private Database $db) {}
    public function getResetCategories(): array
    {
        return [
            new DataResetCategory('survey_responses', '설문 응답', '설문 응답과 답변을 삭제합니다. (설문 정의 보존)', 'bi-bar-chart', false),
            new DataResetCategory('surveys', '설문 전체 데이터', '응답과 설문·문항 정의를 모두 삭제합니다. (설정 보존)', 'bi-clipboard-data'),
        ];
    }
    public function reset(string $category, int $domainId): DataResetResult
    {
        return match ($category) {
            'survey_responses' => $this->resetResponses($domainId),
            'surveys' => $this->resetSurveys($domainId),
            default => new DataResetResult(details: '알 수 없는 카테고리'),
        };
    }
    private function resetResponses(int $domainId): DataResetResult
    {
        $cleared = 0;
        if ($this->db->tableExists('survey_answers') && $this->db->tableExists('survey_responses') && $this->db->tableExists('surveys')) {
            $this->db->execute('DELETE a FROM survey_answers a INNER JOIN survey_responses r ON r.response_id = a.response_id INNER JOIN surveys s ON s.survey_id = r.survey_id WHERE s.domain_id = ?', [$domainId]);
            $cleared++;
        }
        if ($this->db->tableExists('survey_responses') && $this->db->tableExists('surveys')) {
            $this->db->execute('DELETE r FROM survey_responses r INNER JOIN surveys s ON s.survey_id = r.survey_id WHERE s.domain_id = ?', [$domainId]);
            $cleared++;
        }
        return new DataResetResult($cleared, details: '설문 응답·답변 삭제 (설문 정의 보존)');
    }
    private function resetSurveys(int $domainId): DataResetResult
    {
        $response = $this->resetResponses($domainId);
        $cleared = $response->tablesCleared;
        if ($this->db->tableExists('survey_questions') && $this->db->tableExists('surveys')) {
            $this->db->execute('DELETE q FROM survey_questions q INNER JOIN surveys s ON s.survey_id = q.survey_id WHERE s.domain_id = ?', [$domainId]);
            $cleared++;
        }
        if ($this->db->tableExists('surveys')) {
            $this->db->execute('DELETE FROM surveys WHERE domain_id = ?', [$domainId]);
            $cleared++;
        }
        return new DataResetResult($cleared, details: '설문 응답 및 설문·문항 정의 삭제 (설정 보존)');
    }
}
