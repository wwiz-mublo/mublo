<?php
/**
 * Block Skin: boardgroup/basic-tab
 *
 * 게시판 그룹 탭 스킨
 *
 * MubloItemLayout 비적용:
 *   탭 전환 구조 — 각 게시판 패널이 독립적이므로 레이아웃 모듈 불필요.
 *
 * @var array $titleConfig 타이틀 설정
 * @var string $titlePartial 타이틀 파셜 경로
 * @var array $contentConfig 콘텐츠 설정
 * @var \Mublo\Entity\Block\BlockColumn $column 블록 칸 엔티티
 * @var \Mublo\Entity\Board\BoardGroup|null $group 게시판 그룹
 * @var array $boardsData 게시판별 데이터 [{board, articles}, ...]
 * @var string $displayType tab|list|grid
 */

$showDate = $contentConfig['show_date'] ?? true;
$showComment = $contentConfig['show_comment_count'] ?? true;

if ($assets) {
    $assets->addCss('/serve/package/Board/views/Block/boardgroup/basic-tab/style.css');
    $assets->addJs('/serve/package/Board/views/Block/boardgroup/basic-tab/script.js');
}
?>
<div class="block-boardgroup block-boardgroup--board-basic-tab">
    <?php include $titlePartial; ?>

    <!-- 콘텐츠 영역 -->
    <div class="block-boardgroup__content block-body">
        <?php if (empty($boardsData)): ?>
        <p class="block-boardgroup__empty">
            <i class="bi bi-inbox block-boardgroup__empty-icon"></i>
            <span>등록된 글이 없습니다.</span>
        </p>
        <?php else: ?>
        <div class="block-boardgroup__tabs">
            <?php foreach ($boardsData as $index => $data): ?>
                <?php
                $board = $data['board'];
                $boardId = $board->getBoardId();
                $boardName = htmlspecialchars($board->getBoardName());
                $activeClass = $index === 0 ? 'active' : '';
                ?>
                <button type="button"
                    class="block-boardgroup__tab <?= $activeClass ?>"
                    data-target="board-panel-<?= $boardId ?>">
                    <?= $boardName ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="block-boardgroup__panels">
            <?php foreach ($boardsData as $index => $data): ?>
                <?php
                $board = $data['board'];
                $articles = $data['articles'];
                $boardId = $board->getBoardId();
                $activeClass = $index === 0 ? 'active' : '';
                ?>
                <div id="board-panel-<?= $boardId ?>" class="block-boardgroup__panel <?= $activeClass ?>">
                    <?php if (empty($articles)): ?>
                        <p class="block-boardgroup__empty">
                            <i class="bi bi-inbox block-boardgroup__empty-icon"></i>
                            <span>등록된 글이 없습니다.</span>
                        </p>
                    <?php else: ?>
                        <ul class="block-boardgroup__list">
                            <?php foreach ($articles as $article): ?>
                            <li class="block-boardgroup__item">
                                <a href="<?= htmlspecialchars($article['url']) ?>" class="block-boardgroup__link">
                                    <!-- 1줄: 제목 · 뱃지 -->
                                    <span class="block-boardgroup__head">
                                        <span class="block-boardgroup__title">
                                            <?php if (!empty($article['is_new'])): ?>
                                            <span class="block-boardgroup__badge-new">N</span>
                                            <?php endif; ?>
                                            <?= $article['title_safe'] ?>
                                        </span>
                                    </span>
                                    <!-- 2줄: 작성자 · 작성일 ····· 댓글수(끝) -->
                                    <span class="block-boardgroup__meta">
                                        <span class="block-boardgroup__author"><?= $article['author_name'] ?></span>
                                        <?php if ($showDate && !empty($article['date_short'])): ?>
                                        <span class="block-boardgroup__sep">·</span><span class="block-boardgroup__date"><?= $article['date_short'] ?></span>
                                        <?php endif; ?>
                                        <?php if ($showComment && ($article['comment_count'] ?? 0) > 0): ?>
                                        <span class="block-boardgroup__comment">[<?= $article['comment_count'] ?>]</span>
                                        <?php endif; ?>
                                    </span>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
