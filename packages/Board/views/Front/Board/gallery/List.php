<?php
/**
 * Board List View (gallery skin)
 *
 * 게시판 목록 갤러리 스킨 — 썸네일 카드 그리드 레이아웃
 *
 * @var array $board 게시판 설정
 * @var array $items 게시글 목록 (ArticlePresenter 변환 완료)
 * @var array $pagination 페이지네이션
 * @var array $filters 검색 필터 (keyword, search_field)
 * @var bool $canWrite 글쓰기 권한
 *
 * [Presenter 제공 필드]
 * - thumbnail: 썸네일 이미지 경로 (DB 저장)
 * - title_safe: htmlspecialchars 적용된 제목
 * - author_name / author_name_masked: 글쓴이
 * - date_relative / date_compact: 날짜 포맷
 * - view_count_formatted / comment_count_formatted: 통계
 * - badges: ['notice', 'secret', 'new'] 배지 배열
 */

$boardName = htmlspecialchars($board['board_name'] ?? '');
$boardDesc = htmlspecialchars($board['board_description'] ?? '');
$boardSlug = htmlspecialchars($board['board_slug'] ?? '');
$keyword = htmlspecialchars($filters['keyword'] ?? '');
$searchField = $filters['search_field'] ?? 'title';
$useCategory = !empty($board['use_category']);
$categories = $categories ?? [];
$notices = $notices ?? [];
$currentCategoryId = (int) ($filters['category_id'] ?? 0);

$this->assets->addCss('/serve/package/Board/views/Front/Board/gallery/_assets/css/board.css');

// 목록 컨텍스트(페이지/검색/카테고리) 보존용 — 카드 링크에 현재 쿼리 부착
$listQuery = $this->getQueryString();
$listQuerySuffix = $listQuery !== '' ? '?' . htmlspecialchars($listQuery, ENT_QUOTES) : '';
?>

<div class="board-gallery">
    <!-- 게시판 헤더 -->
    <div class="board-gallery__header">
        <h2 class="board-gallery__title"><?= $boardName ?></h2>
        <?php if ($boardDesc): ?>
            <p class="board-gallery__desc"><?= $boardDesc ?></p>
        <?php endif; ?>
    </div>

    <!-- 카테고리 필터 -->
    <?php if ($useCategory && !empty($categories)): ?>
    <div class="board-gallery__category-filter">
        <a href="/board/<?= $boardSlug ?>" class="board-gallery__category-link<?= $currentCategoryId === 0 ? ' board-gallery__category-link--active' : '' ?>">전체</a>
        <?php foreach ($categories as $cat): ?>
            <a href="/board/<?= $boardSlug ?>?category_id=<?= $cat['category_id'] ?>"
               class="board-gallery__category-link<?= $currentCategoryId === $cat['category_id'] ? ' board-gallery__category-link--active' : '' ?>">
                <?= htmlspecialchars($cat['category_name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- 공지 카드 (별도 표시) -->
    <?php if (!empty($notices)): ?>
        <div class="board-gallery__grid board-gallery__grid--notices">
            <?php foreach ($notices as $item):
                $isSecret = in_array('secret', $item['badges']);
                $thumbnail = $item['thumbnail'] ?? '';
                $commentCount = (int) ($item['comment_count'] ?? 0);
            ?>
                <a href="<?= $item['url'] ?><?= $listQuerySuffix ?>" class="board-gallery__card board-gallery__card--notice">
                    <div class="board-gallery__thumb">
                        <?php if ($thumbnail): ?>
                            <img src="<?= htmlspecialchars($thumbnail) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <div class="board-gallery__thumb-empty">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>
                                </svg>
                            </div>
                        <?php endif; ?>
                        <div class="board-gallery__badges">
                            <span class="board-gallery__badge board-gallery__badge--notice">공지</span>
                            <?php if ($item['is_new']): ?>
                                <span class="board-gallery__badge board-gallery__badge--new">N</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="board-gallery__info">
                        <h3 class="board-gallery__card-title"><?= $item['title_safe'] ?></h3>
                        <div class="board-gallery__meta">
                            <span class="board-gallery__author"><?= $item['author_name'] ?></span>
                            <span class="board-gallery__date"><?= $item['date_relative'] ?></span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- 갤러리 그리드 -->
    <?php if (empty($items) && empty($notices)): ?>
        <div class="board-gallery__empty">
            <?= $keyword ? '검색 결과가 없습니다.' : '등록된 글이 없습니다.' ?>
        </div>
    <?php elseif (!empty($items)): ?>
        <div class="board-gallery__grid">
            <?php foreach ($items as $item):
                $isSecret = in_array('secret', $item['badges']);
                $thumbnail = $item['thumbnail'] ?? '';
                $commentCount = (int) ($item['comment_count'] ?? 0);
            ?>
                <a href="<?= $item['url'] ?><?= $listQuerySuffix ?>" class="board-gallery__card">
                    <!-- 썸네일 -->
                    <div class="board-gallery__thumb">
                        <?php if ($thumbnail): ?>
                            <img src="<?= htmlspecialchars($thumbnail) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <div class="board-gallery__thumb-empty">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>
                                </svg>
                            </div>
                        <?php endif; ?>
                        <!-- 배지 -->
                        <?php if ($isSecret || $item['is_new']): ?>
                            <div class="board-gallery__badges">
                                <?php if ($isSecret): ?>
                                    <span class="board-gallery__badge board-gallery__badge--secret">비밀</span>
                                <?php endif; ?>
                                <?php if ($item['is_new']): ?>
                                    <span class="board-gallery__badge board-gallery__badge--new">N</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- 정보 -->
                    <div class="board-gallery__info">
                        <h3 class="board-gallery__card-title">
                            <?= $keyword ? $this->format->highlightKeyword($item['title_safe'], $keyword) : $item['title_safe'] ?>
                        </h3>
                        <div class="board-gallery__meta">
                            <span class="board-gallery__author"><?= $item['author_name'] ?></span>
                            <span class="board-gallery__date"><?= $item['date_relative'] ?></span>
                        </div>
                        <div class="board-gallery__stats">
                            <span class="board-gallery__stat">조회 <?= $item['view_count_formatted'] ?></span>
                            <?php if ($commentCount > 0): ?>
                                <span class="board-gallery__stat">댓글 <?= $item['comment_count_formatted'] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- 하단: 페이지네이션 + 검색 + 글쓰기 -->
    <div class="board-gallery__footer">
        <!-- 페이지네이션 -->
        <div class="board-gallery__pagination">
            <?= $this->pagination($pagination) ?>
        </div>

        <!-- 검색 폼 -->
        <form class="board-gallery__search" method="get" action="/board/<?= $boardSlug ?>">
            <select name="search_field" class="board-gallery__search-select">
                <option value="title" <?= $searchField === 'title' ? 'selected' : '' ?>>제목</option>
                <option value="content" <?= $searchField === 'content' ? 'selected' : '' ?>>내용</option>
                <option value="title_content" <?= $searchField === 'title_content' ? 'selected' : '' ?>>제목+내용</option>
            </select>
            <input type="text" name="keyword" value="<?= $keyword ?>"
                   class="board-gallery__search-input" placeholder="검색어 입력">
            <button type="submit" class="board-gallery__search-btn">검색</button>
        </form>

        <!-- 글쓰기 버튼 (권한 없으면 비활성 노출) -->
        <div class="board-gallery__actions">
            <?php if ($canWrite): ?>
                <a href="/board/<?= $boardSlug ?>/write" class="board-gallery__write-btn">글쓰기</a>
            <?php else: ?>
                <span class="board-gallery__write-btn board-gallery__write-btn--disabled"
                      aria-disabled="true" title="글쓰기 권한이 없습니다">글쓰기</span>
            <?php endif; ?>
        </div>
    </div>
</div>
