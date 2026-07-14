<?php
namespace Mublo\Plugin\VisitorStats\Block;

use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Core\Block\Renderer\SkinRendererTrait;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Plugin\VisitorStats\Service\VisitorStatsService;

/**
 * VisitorStatsRenderer — 방문자 통계(숫자형) 블록 렌더러
 *
 * 오늘 / 어제 / 전체(누적) 방문자 3그룹. 각 그룹: 방문자 + 페이지뷰 + 회원.
 * 설정 없이 스킨 선택만. (추이 그래프는 별도 타입 visitor_trend)
 *
 * 스킨에 전달되는 변수(SkinRendererTrait 공통 + 아래):
 * - $groups: [ ['label','visitors','pageviews','members','change'], ... ] (오늘/어제/전체)
 */
class VisitorStatsRenderer implements RendererInterface
{
    use SkinRendererTrait;

    /** 지원 스킨 목록 */
    private const SKINS = ['basic'];

    public function __construct(
        private VisitorStatsService $statsService
    ) {}

    protected function getSkinType(): string
    {
        return 'visitor_stats';
    }

    protected function getSkinBasePath(): string
    {
        return MUBLO_PLUGIN_PATH . '/VisitorStats/views/Block/';
    }

    /**
     * {@inheritdoc}
     */
    public function render(BlockColumn $column): string
    {
        $domainId = $column->getDomainId();
        $skin = $column->getContentSkin() ?: 'basic';
        if (!in_array($skin, self::SKINS, true)) {
            $skin = 'basic';
        }

        try {
            $today = $this->statsService->getSummary($domainId, 'today');
            $yest  = $this->statsService->getSummary($domainId, 'yesterday');
            $cum   = $this->statsService->getCumulativeSummary($domainId);

            $groups = [
                $this->buildGroup('오늘 방문자', $today, (float) ($today['change']['visitors'] ?? 0)),
                $this->buildGroup('어제 방문자', $yest, null),
                $this->buildGroup('전체 방문자', $cum, null),
            ];
        } catch (\Throwable $e) {
            // 통계 테이블 미생성 등 → 0 그룹으로 렌더(칸/레이아웃 유지)
            $groups = [
                $this->buildGroup('오늘 방문자', [], null),
                $this->buildGroup('어제 방문자', [], null),
                $this->buildGroup('전체 방문자', [], null),
            ];
        }

        return $this->renderSkin($column, $skin, ['groups' => $groups]);
    }

    /**
     * 그룹 1개 데이터 정규화
     */
    private function buildGroup(string $label, array $data, ?float $change): array
    {
        return [
            'label'     => $label,
            'visitors'  => (int) ($data['visitors'] ?? 0),
            'pageviews' => (int) ($data['pageviews'] ?? 0),
            'members'   => (int) ($data['memberVisitors'] ?? 0),
            'change'    => $change,
        ];
    }
}
