<?php

namespace Mublo\Packages\Board\Widget;

use Mublo\Core\Dashboard\AbstractDashboardWidget;
use Mublo\Packages\Board\Repository\BoardArticleRepository;

class RecentNoticesWidget extends AbstractDashboardWidget
{
    private BoardArticleRepository $articleRepo;
    private \Closure|int|null $domainIdResolver;
    private ?int $boardId;

    public function __construct(BoardArticleRepository $articleRepo, \Closure|int|null $domainId = null, ?int $boardId = null)
    {
        $this->articleRepo = $articleRepo;
        $this->domainIdResolver = $domainId;
        $this->boardId = $boardId;
    }

    private function getDomainId(): ?int
    {
        if ($this->domainIdResolver instanceof \Closure) {
            return ($this->domainIdResolver)();
        }
        return $this->domainIdResolver;
    }

    public function id(): string
    {
        return 'board.recent_notices';
    }

    public function title(): string
    {
        return '최근 공지사항';
    }

    public function defaultSlot(): int
    {
        return 2;
    }

    public function render(): string
    {
        $notices = $this->fetchNotices(5);

        if (empty($notices)) {
            return '<div class="text-center py-4">'
                 . '<div class="pastel-icon-sky rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width:48px;height:48px"><i class="bi bi-megaphone" style="font-size:20px"></i></div>'
                 . '<div class="text-muted small">등록된 공지사항이 없습니다.</div>'
                 . '</div>';
        }

        $html = '<div class="list-group list-group-flush">';
        foreach ($notices as $notice) {
            $title = htmlspecialchars($notice['title'] ?? '', ENT_QUOTES, 'UTF-8');
            $author = htmlspecialchars($notice['author_name'] ?? '', ENT_QUOTES, 'UTF-8');
            $date = $this->formatDate($notice['created_at'] ?? '');
            $views = number_format((int) ($notice['view_count'] ?? 0));
            $articleId = (int) ($notice['article_id'] ?? 0);

            $html .= '<a href="/admin/board/article/view/' . $articleId . '" class="list-group-item list-group-item-action px-0 py-2">';
            $html .= '<div class="d-flex justify-content-between align-items-start">';
            $html .= '<div class="me-2 text-truncate">';
            $html .= '<div class="text-truncate small fw-medium">' . $title . '</div>';
            $html .= '<div class="text-muted" style="font-size:0.7rem">' . $author . '</div>';
            $html .= '</div>';
            $html .= '<div class="text-end flex-shrink-0">';
            $html .= '<div class="text-muted" style="font-size:0.7rem">' . $date . '</div>';
            $html .= '<div class="text-muted" style="font-size:0.65rem"><i class="bi bi-eye"></i> ' . $views . '</div>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</a>';
        }
        $html .= '</div>';

        return $html;
    }

    private function fetchNotices(int $limit): array
    {
        try {
            $domainId = $this->getDomainId();

            if ($this->boardId && $domainId) {
                // 특정 게시판의 공지사항
                $entities = $this->articleRepo->getNotices($domainId, $this->boardId, $limit);
                return array_map(fn($e) => (array) $e, $entities);
            }

            // boardId 미지정 시: 전체 게시판에서 최근 공지 조회
            $db = $this->articleRepo->getDb();
            $query = $db->table('board_articles')
                ->where('is_notice', '=', 1)
                ->where('status', '=', 'published')
                ->orderBy('created_at', 'DESC')
                ->limit($limit);

            if ($domainId) {
                $query->where('domain_id', '=', $domainId);
            }

            return $query->get();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function formatDate(string $datetime): string
    {
        if (!$datetime) return '';

        $ts = strtotime($datetime);
        if ($ts === false) return '';

        $today = strtotime('today');
        if ($ts >= $today) {
            return date('H:i', $ts);
        }

        $thisYear = date('Y');
        if (date('Y', $ts) === $thisYear) {
            return date('m-d', $ts);
        }

        return date('Y-m-d', $ts);
    }
}
