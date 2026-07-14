<?php
/**
 * QnA 프론트 - 내 문의 목록 (basic 스킨)
 *
 * @var array $items
 * @var int   $total
 * @var int   $page
 * @var int   $perPage
 */
use Mublo\Plugin\Qna\Enum\InquiryStatus;

$items     = $items ?? [];
$total     = (int) ($total ?? 0);
$page      = max(1, (int) ($page ?? 1));
$perPage   = max(1, (int) ($perPage ?? 20));
$totalPage = max(1, (int) ceil($total / $perPage));

$this->assets->addCss('/serve/plugin/Qna/views/Front/skins/basic/style.css');
?>
<div class="qna-page">
    <div class="qna-page__header">
        <div class="qna-page__title-wrap">
            <i class="bi bi-patch-check qna-page__icon"></i>
            <h1 class="qna-page__title">질문과 답변</h1>
        </div>
        <p class="qna-page__subtitle">궁금한 점을 남겨주세요</p>
    </div>

    <div class="qna-actions">
        <a href="/qna/write" class="qna-btn qna-btn--primary">문의하기</a>
    </div>

    <?php if (empty($items)): ?>
        <div class="qna-empty">등록된 Q&amp;A가 없습니다.</div>
    <?php else: ?>
        <div class="qna-table">
            <div class="qna-table__head">
                <span>상태</span>
                <span>제목</span>
                <span class="qna-table__col-date">작성일</span>
            </div>
            <?php foreach ($items as $row):
                $st    = InquiryStatus::tryFrom($row['status'] ?? 'open');
                $rc    = (int) ($row['reply_count'] ?? 0);
                $date  = trim((string) ($row['created_at'] ?? ''));
                $date  = $date !== '' ? substr($date, 0, 10) : '';
            ?>
            <a class="qna-table__row" href="/qna/<?= (int) $row['post_id'] ?>">
                <span class="qna-table__status">
                    <span class="qna-badge qna-badge--<?= $st?->badgeClass() ?>"><?= $st?->label() ?></span>
                </span>
                <span class="qna-table__title">
                    <?php if (!empty($row['is_secret'])): ?><i class="bi bi-lock-fill qna-table__lock"></i><?php endif; ?>
                    <span class="qna-table__subject"><?= htmlspecialchars($row['title'] ?? '(제목 없음)') ?></span>
                    <?php if ($rc > 0): ?><span class="qna-table__count"><?= $rc ?></span><?php endif; ?>
                </span>
                <span class="qna-table__date"><?= htmlspecialchars($date) ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPage > 1): ?>
        <nav class="qna-pager">
            <a class="<?= $page <= 1 ? 'is-disabled' : '' ?>" href="/qna?page=<?= max(1, $page - 1) ?>">이전</a>
            <?php
            $start = max(1, $page - 2);
            $end   = min($totalPage, $start + 4);
            $start = max(1, $end - 4);
            for ($p = $start; $p <= $end; $p++): ?>
                <a class="<?= $p === $page ? 'is-active' : '' ?>" href="/qna?page=<?= $p ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a class="<?= $page >= $totalPage ? 'is-disabled' : '' ?>" href="/qna?page=<?= min($totalPage, $page + 1) ?>">다음</a>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
