<?php
namespace Mublo\Plugin\Survey\Block;

use Mublo\Core\Block\BlockItemsProviderInterface;
use Mublo\Plugin\Survey\Repository\SurveyRepository;

class SurveyItemsProvider implements BlockItemsProviderInterface
{
    public function __construct(private SurveyRepository $surveyRepository) {}

    public function getItems(int $domainId): array
    {
        $rows = $this->surveyRepository->findAllByDomain($domainId);

        return array_map(static function (array $row): array {
            $status = match($row['status'] ?? 'draft') {
                'active' => '진행중',
                'closed' => '종료',
                default  => '초안',
            };
            return [
                'id'    => (string) $row['survey_id'],
                'label' => $row['title'] . ' [' . $status . ']',
            ];
        }, $rows);
    }
}
