<?php

namespace Mublo\Packages\Board\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Mypage\MypageSectionBuildingEvent;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardCommentRepository;
use Mublo\Packages\Board\Repository\BoardReactionRepository;

/**
 * 마이페이지 섹션 구독자 (Board)
 *
 * Board가 "내가 쓴 글/댓글"을 마이페이지 섹션으로 등록한다.
 * 코어는 셸(액자)만 렌더하고, 콘텐츠 partial(그림)·데이터는 Board가 제공.
 *   - /mypage/board        (허브 "마이보드" — 글/댓글 요약)
 *   - /mypage/board/articles
 *   - /mypage/board/comments
 */
class MypageSectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private BoardArticleRepository $articleRepository,
        private BoardCommentRepository $commentRepository,
        private BoardReactionRepository $reactionRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            MypageSectionBuildingEvent::class => 'onMypageSectionBuilding',
        ];
    }

    public function onMypageSectionBuilding(MypageSectionBuildingEvent $event): void
    {
        $viewDir = dirname(__DIR__) . '/views/Front/Mypage';

        $event->setSource('board');

        // 허브 "마이보드" (/mypage/board) — 게시판별 글/댓글 통계 + 그래프 대시보드.
        // (내가 쓴 글/댓글 목록은 아래 섹션이 담당. 허브는 지표 + 게시판 직접 링크만)
        $event->registerHub(
            '마이보드',
            $this->skinView($viewDir, 'Index.php'),
            fn (int $memberId, int $domainId): array => array_merge(
                $this->buildBoardStats($memberId, $domainId),
                $this->buildActivityTrend($memberId, $domainId)
            )
        );

        $event->registerSection(
            'articles',
            '내가 쓴 글',
            $this->skinView($viewDir, 'Articles.php'),
            function (int $memberId, int $domainId, int $page, int $perPage): array {
                $r = $this->articleRepository->getByMember($memberId, $domainId, $page, $perPage);
                return ['articles' => $r['items'], 'pagination' => $r['pagination']];
            }
        );

        $event->registerSection(
            'comments',
            '내가 쓴 댓글',
            $this->skinView($viewDir, 'Comments.php'),
            function (int $memberId, int $domainId, int $page, int $perPage): array {
                $r = $this->commentRepository->getByMember($memberId, $domainId, $page, $perPage);
                return ['comments' => $r['items'], 'pagination' => $r['pagination']];
            }
        );
    }

    /**
     * 마이페이지 뷰 스킨 해석 (basic 폴백).
     *
     * Board는 마이페이지 스킨 설정 소스가 없어 현재는 항상 basic으로 폴백한다.
     * 훗날 스킨 설정이 생기면 이 지점에서 선택 스킨을 읽어 경로를 만들고,
     * 선택 스킨에 해당 파일이 없으면 basic으로 폴백한다.
     */
    private function skinView(string $viewBase, string $file): string
    {
        $skin = 'basic'; // 스킨 설정 없음 → basic 폴백
        $path = $viewBase . '/' . $skin . '/' . $file;

        return is_file($path) ? $path : $viewBase . '/basic/' . $file;
    }

    /**
     * 게시판별 글/댓글 수를 합쳐 활동 대시보드용 데이터로 빌드.
     * 활동량(글+댓글) 많은 게시판 순으로 정렬.
     */
    private function buildBoardStats(int $memberId, int $domainId): array
    {
        $articleCounts = $this->articleRepository->getMemberCountsByBoard($memberId, $domainId);
        $commentCounts = $this->commentRepository->getMemberCountsByBoard($memberId, $domainId);

        $boards = [];
        foreach ($articleCounts as $r) {
            $boards[(int) $r['board_id']] = [
                'board_name' => $r['board_name'] ?? '',
                'board_slug' => $r['board_slug'] ?? '',
                'articles'   => (int) $r['cnt'],
                'comments'   => 0,
            ];
        }
        foreach ($commentCounts as $r) {
            $bid = (int) $r['board_id'];
            if (!isset($boards[$bid])) {
                $boards[$bid] = [
                    'board_name' => $r['board_name'] ?? '',
                    'board_slug' => $r['board_slug'] ?? '',
                    'articles'   => 0,
                    'comments'   => 0,
                ];
            }
            $boards[$bid]['comments'] = (int) $r['cnt'];
        }

        $boards = array_values($boards);
        usort($boards, fn ($a, $b) => ($b['articles'] + $b['comments']) <=> ($a['articles'] + $a['comments']));

        return [
            'boards'         => $boards,
            'totalArticles'  => array_sum(array_column($boards, 'articles')),
            'totalComments'  => array_sum(array_column($boards, 'comments')),
            'totalReactions' => $this->reactionRepository->countByMemberInDomain($memberId, $domainId),
        ];
    }

    /**
     * 최근 N일 일별 활동 추이(글/댓글/반응). 빈 날은 0으로 채워 연속 시계열 보장.
     */
    private function buildActivityTrend(int $memberId, int $domainId, int $days = 30): array
    {
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

        $articles  = $this->articleRepository->getMemberDailyCounts($memberId, $domainId, $since);
        $comments  = $this->commentRepository->getMemberDailyCounts($memberId, $domainId, $since);
        $reactions = $this->reactionRepository->getMemberDailyCounts($memberId, $domainId, $since);

        $labels = $aSeries = $cSeries = $rSeries = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $key      = date('Y-m-d', strtotime('-' . $i . ' days'));
            $labels[] = date('n/j', strtotime($key));
            $aSeries[] = $articles[$key]  ?? 0;
            $cSeries[] = $comments[$key]  ?? 0;
            $rSeries[] = $reactions[$key] ?? 0;
        }

        return [
            'trendLabels'    => $labels,
            'trendArticles'  => $aSeries,
            'trendComments'  => $cSeries,
            'trendReactions' => $rSeries,
        ];
    }
}
