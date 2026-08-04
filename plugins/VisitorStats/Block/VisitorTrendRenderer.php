<?php
declare(strict_types=1);
namespace Mublo\Plugin\VisitorStats\Block;

use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Core\Block\Renderer\SkinRendererTrait;
use Mublo\Contract\Block\BlockColumnView;
use Mublo\Plugin\VisitorStats\Service\VisitorStatsService;

/**
 * VisitorTrendRenderer — 방문자 추이 블록 렌더러
 *
 * 최근 7일 일별 방문자 추이. 설정 없이 스킨 선택만.
 * (오늘 요약/누적 숫자형은 별도 콘텐츠 타입 visitor_stats / VisitorStatsRenderer 담당)
 *
 * 스킨에 전달되는 변수(SkinRendererTrait 공통 + 아래):
 * - $trend: VisitorStatsService::getTrend(domainId,'last_7_days') — [{date,visitors,...}, ...]
 * - $total: VisitorStatsService::getCumulativeVisitors(domainId) — 누적(전체) 방문자
 */
class VisitorTrendRenderer implements RendererInterface
{
    use SkinRendererTrait;

    /** 지원 스킨 목록 */
    private const SKINS = ['basic'];

    public function __construct(
        private VisitorStatsService $statsService
    ) {}

    protected function getSkinType(): string
    {
        return 'visitor_trend';
    }

    protected function getSkinBasePath(): string
    {
        return MUBLO_PLUGIN_PATH . '/VisitorStats/views/Block/';
    }

    /**
     * {@inheritdoc}
     */
    public function render(BlockColumnView $column): string
    {
        $domainId = $column->getDomainId();
        $skin = $column->getContentSkin() ?: 'basic';
        if (!in_array($skin, self::SKINS, true)) {
            $skin = 'basic';
        }

        try {
            return $this->renderSkin($column, $skin, [
                'trend' => $this->statsService->getTrend($domainId, 'last_7_days'),
                'total' => $this->statsService->getCumulativeVisitors($domainId),
            ]);
        } catch (\Throwable $e) {
            // 조회 실패(테이블 미생성 등)도 "데이터 없음" 대신 최근 7일 0으로 렌더
            $trend = [];
            for ($i = 6; $i >= 0; $i--) {
                $trend[] = [
                    'date'        => date('Y-m-d', strtotime("-{$i} day")),
                    'visitors'    => 0,
                    'pageviews'   => 0,
                    'newVisitors' => 0,
                ];
            }
            return $this->renderSkin($column, $skin, ['trend' => $trend, 'total' => 0]);
        }
    }
}
