<?php
/**
 * Board - 마이페이지 "내가 쓴 댓글" 섹션 콘텐츠 partial
 *
 * 코어 마이페이지 셸(Section.php + _layout)이 ob_start 안에서 include한다.
 * 글/댓글 목록 스타일은 Board 패키지 mypage.css가 담당. $this = ViewContext.
 *
 * @var array[] $comments    댓글 목록
 * @var array   $pagination  페이지네이션
 */
$this->assets->addCss('/serve/package/Board/views/Front/Mypage/basic/_assets/css/mypage.css');
?>
<div class="mypage-header">
    <h1 class="mypage-header__title">내가 쓴 댓글</h1>
    <p class="mypage-header__desc">내가 작성한 댓글 목록입니다.</p>
</div>

<div class="mypage-list-summary">
    전체 <?= number_format($pagination['totalItems']) ?>개의 댓글
</div>

<?php if (empty($comments)): ?>
    <div class="empty-state">작성한 댓글이 없습니다.</div>
<?php else: ?>
    <div class="mypage-comments-list">
        <?php foreach ($comments as $row): ?>
            <?php
                $boardSlug  = $row['board_slug'] ?? '';
                $articleId  = $row['article_id'] ?? 0;
                $articleUrl = $boardSlug ? '/board/' . $boardSlug . '/view/' . $articleId : '#';
                $excerpt    = mb_substr(strip_tags($row['content'] ?? ''), 0, 120);
                if (mb_strlen($row['content'] ?? '') > 120) {
                    $excerpt .= '...';
                }
            ?>
            <div class="comment-item">
                <div class="comment-article">
                    <span class="board-badge"><?= htmlspecialchars($row['board_name'] ?? '') ?></span>
                    원글:
                    <a href="<?= htmlspecialchars($articleUrl) ?>">
                        <?= htmlspecialchars($row['article_title'] ?? '(삭제된 게시글)') ?>
                    </a>
                </div>
                <div class="comment-content"><?= htmlspecialchars($excerpt) ?></div>
                <div class="comment-date"><?= htmlspecialchars(substr($row['created_at'] ?? '', 0, 16)) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?= $this->pagination($pagination) ?>
