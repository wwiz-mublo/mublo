<?php
/**
 * Board - 마이페이지 "내가 쓴 글" 섹션 콘텐츠 partial
 *
 * 코어 마이페이지 셸(Section.php + _layout)이 ob_start 안에서 include한다.
 * 글/댓글 목록 스타일은 Board 패키지 mypage.css가 담당. $this = ViewContext.
 *
 * @var array[] $articles    게시글 목록
 * @var array   $pagination  페이지네이션
 */
$this->assets->addCss('/serve/package/Board/views/Front/Mypage/basic/_assets/css/mypage.css');
?>
<div class="mypage-header">
    <h1 class="mypage-header__title">내가 쓴 글</h1>
    <p class="mypage-header__desc">내가 작성한 게시글 목록입니다.</p>
</div>

<div class="mypage-list-summary">
    전체 <?= number_format($pagination['totalItems']) ?>개의 게시글
</div>

<table class="mypage-list-table">
    <thead>
        <tr>
            <th>게시판</th>
            <th>제목</th>
            <th>조회</th>
            <th>댓글</th>
            <th>작성일</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($articles)): ?>
            <tr class="empty-row">
                <td colspan="5">작성한 게시글이 없습니다.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($articles as $row): ?>
                <?php
                    $boardSlug  = $row['board_slug'] ?? '';
                    $articleId  = $row['article_id'] ?? 0;
                    $articleUrl = $boardSlug ? '/board/' . $boardSlug . '/view/' . $articleId : '#';
                ?>
                <tr>
                    <td class="board-name"><?= htmlspecialchars($row['board_name'] ?? '') ?></td>
                    <td class="article-title">
                        <a href="<?= htmlspecialchars($articleUrl) ?>">
                            <?= htmlspecialchars($row['title'] ?? '') ?>
                        </a>
                    </td>
                    <td class="meta" data-label="조회"><?= number_format($row['view_count'] ?? 0) ?></td>
                    <td class="meta" data-label="댓글"><?= number_format($row['comment_count'] ?? 0) ?></td>
                    <td class="meta"><?= htmlspecialchars(substr($row['created_at'] ?? '', 0, 10)) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?= $this->pagination($pagination) ?>
