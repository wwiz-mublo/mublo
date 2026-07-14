<?php

namespace Mublo\Packages\Board\Service;

use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardCommentRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardGroupRepository;

/**
 * Board 관리자 대시보드 통계 서비스
 *
 * 도메인 단위 게시판 현황을 한 번에 집계한다.
 */
class BoardDashboardService
{
    public function __construct(
        private BoardConfigRepository $configRepository,
        private BoardGroupRepository $groupRepository,
        private BoardArticleRepository $articleRepository,
        private BoardCommentRepository $commentRepository,
    ) {}

    /**
     * 최근 N일 활동 추이 (게시글/댓글 일별 건수)
     *
     * @return array<int,array{date:string,label:string,articles:int,comments:int}>
     */
    public function getActivityTrend(int $domainId, int $days = 14): array
    {
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $articleDaily = $this->articleRepository->getDailyCountsByDomain($domainId, $since);
        $commentDaily = $this->commentRepository->getDailyCountsByDomain($domainId, $since);

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $trend[] = [
                'date'     => $date,
                'label'    => date('m/d', strtotime($date)),
                'articles' => $articleDaily[$date] ?? 0,
                'comments' => $commentDaily[$date] ?? 0,
            ];
        }
        return $trend;
    }

    /**
     * 대시보드 전체 데이터 한 번에 로드
     */
    public function getDashboardData(int $domainId): array
    {
        $todayStart = date('Y-m-d 00:00:00');

        return [
            'board_count'     => $this->configRepository->countByDomain($domainId),
            'group_count'     => $this->groupRepository->countByDomain($domainId),
            'article_count'   => $this->articleRepository->countByDomain($domainId),
            'comment_count'   => $this->commentRepository->countByDomain($domainId),
            'today_articles'  => $this->articleRepository->countByDomain($domainId, $todayStart),
            'today_comments'  => $this->commentRepository->countByDomain($domainId, $todayStart),
            'activity_trend'  => $this->getActivityTrend($domainId),
            'top_boards'      => $this->articleRepository->getTopBoardsByArticleCount($domainId, 5),
            'recent_articles' => $this->articleRepository->getRecentByDomain($domainId, 8),
            'recent_comments' => $this->commentRepository->getRecentComments($domainId, 8),
        ];
    }
}
