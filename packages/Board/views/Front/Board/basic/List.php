<?php
/**
 * Board List View (basic skin)
 *
 * 게시판 목록 기본 스킨
 *
 * @var array $board 게시판 설정
 * @var array $items 게시글 목록 (ArticlePresenter 변환 완료)
 * @var array $pagination 페이지네이션
 * @var array $filters 검색 필터 (keyword, search_field)
 * @var array $categories 카테고리 목록 (use_category 시)
 * @var bool $canWrite 글쓰기 권한
 *
 * [Presenter 제공 필드]
 * - author_name / author_name_masked: 글쓴이 (원본/마스킹, 이스케이프 완료)
 * - is_member: 회원 여부
 * - title_safe: htmlspecialchars 적용된 제목
 * - url: 게시글 상세 URL
 * - date_short / date_relative / date_compact: 날짜 포맷
 * - view_count_formatted / comment_count_formatted: 포맷된 통계
 * - badges: ['notice', 'secret', 'new'] 배지 배열
 * - is_new: 24시간 이내 신규 여부
 */

$boardName = htmlspecialchars($board['board_name'] ?? '');
$boardDesc = htmlspecialchars($board['board_description'] ?? '');
$boardSlug = htmlspecialchars($board['board_slug'] ?? '');
$keyword = htmlspecialchars($filters['keyword'] ?? '');
$searchField = $filters['search_field'] ?? 'title';
$currentPage = $pagination['currentPage'] ?? 1;
$totalItems = $pagination['totalItems'] ?? 0;
$perPage = $pagination['perPage'] ?? 20;
$useCategory = !empty($board['use_category']);
$categories = $categories ?? [];
$notices = $notices ?? [];
$currentCategoryId = (int) ($filters['category_id'] ?? 0);

// 카테고리 ID → 이름 매핑
$categoryMap = [];
foreach ($categories as $cat) {
    $categoryMap[$cat['category_id']] = $cat['category_name'];
}

$this->assets->addCss('/serve/package/Board/views/Front/Board/basic/_assets/css/board.css');

// 목록 컨텍스트(페이지/검색/카테고리) 보존용 — 글 링크에 현재 쿼리 부착
$listQuery = $this->getQueryString();
$listQuerySuffix = $listQuery !== '' ? '?' . htmlspecialchars($listQuery, ENT_QUOTES) : '';
?>

<div class="board-list">
    <!-- 게시판 헤더 -->
    <div class="board-list__header">
        <h2 class="board-list__title"><?= $boardName ?></h2>
        <?php if ($boardDesc): ?>
            <p class="board-list__desc"><?= $boardDesc ?></p>
        <?php endif; ?>
    </div>

    <!-- 카테고리 필터 -->
    <?php if ($useCategory && !empty($categories)): ?>
    <div class="board-list__category-filter">
        <a href="/board/<?= $boardSlug ?>" class="board-list__category-link<?= $currentCategoryId === 0 ? ' board-list__category-link--active' : '' ?>">전체</a>
        <?php foreach ($categories as $cat): ?>
            <a href="/board/<?= $boardSlug ?>?category_id=<?= $cat['category_id'] ?>"
               class="board-list__category-link<?= $currentCategoryId === $cat['category_id'] ? ' board-list__category-link--active' : '' ?>">
                <?= htmlspecialchars($cat['category_name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- 게시글 목록 -->
    <div class="board-list__table-wrap">
        <table class="board-list__table">
            <thead>
                <tr>
                    <th class="board-list__th board-list__th--no">번호</th>
                    <?php if ($useCategory): ?>
                    <th class="board-list__th board-list__th--category">카테고리</th>
                    <?php endif; ?>
                    <th class="board-list__th board-list__th--title">제목</th>
                    <th class="board-list__th board-list__th--author">글쓴이</th>
                    <th class="board-list__th board-list__th--date">날짜</th>
                    <th class="board-list__th board-list__th--views">조회</th>
                </tr>
            </thead>
            <tbody>
                <?php $colSpan = $useCategory ? 6 : 5; ?>
                <?php // 공지글 (상단 고정) ?>
                <?php foreach ($notices as $item): ?>
                    <tr class="board-list__row board-list__row--notice">
                        <td class="board-list__td board-list__td--no">
                            <span class="board-list__badge board-list__badge--notice">공지</span>
                        </td>
                        <?php if ($useCategory): ?>
                        <td class="board-list__td board-list__td--category">
                            <?= htmlspecialchars($categoryMap[$item['category_id'] ?? 0] ?? '') ?>
                        </td>
                        <?php endif; ?>
                        <td class="board-list__td board-list__td--title">
                            <?php if (in_array('secret', $item['badges'])): ?>
                                <span class="board-list__icon board-list__icon--secret" title="비밀글">🔒</span>
                            <?php endif; ?>
                            <a href="<?= $item['url'] ?><?= $listQuerySuffix ?>" class="board-list__link board-list__link--notice">
                                <?= $item['title_safe'] ?>
                            </a>
                            <?php if ((int) ($item['comment_count'] ?? 0) > 0): ?>
                                <span class="board-list__comment-count">[<?= $item['comment_count_formatted'] ?>]</span>
                            <?php endif; ?>
                            <?php if ($item['is_new']): ?>
                                <span class="board-list__badge board-list__badge--new">N</span>
                            <?php endif; ?>
                        </td>
                        <td class="board-list__td board-list__td--author"><?= $item['author_name'] ?></td>
                        <td class="board-list__td board-list__td--date"><?= $item['date_relative'] ?></td>
                        <td class="board-list__td board-list__td--views"><?= $item['view_count_formatted'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php // 일반 게시글 ?>
                <?php if (empty($items) && empty($notices)): ?>
                    <tr>
                        <td class="board-list__empty" colspan="<?= $colSpan ?>">
                            <?= $keyword ? '검색 결과가 없습니다.' : '등록된 글이 없습니다.' ?>
                        </td>
                    </tr>
                <?php elseif (empty($items)): ?>
                    <?php // 공지만 있고 일반글이 없을 때 — 아무것도 출력하지 않음 ?>
                <?php else: ?>
                    <?php
                        $rowNum = $totalItems - (($currentPage - 1) * $perPage);
                    ?>
                    <?php foreach ($items as $item): ?>
                        <tr class="board-list__row">
                            <td class="board-list__td board-list__td--no">
                                <?= $rowNum-- ?>
                            </td>
                            <?php if ($useCategory): ?>
                            <td class="board-list__td board-list__td--category">
                                <?= htmlspecialchars($categoryMap[$item['category_id'] ?? 0] ?? '') ?>
                            </td>
                            <?php endif; ?>
                            <td class="board-list__td board-list__td--title">
                                <?php if (in_array('secret', $item['badges'])): ?>
                                    <span class="board-list__icon board-list__icon--secret" title="비밀글">🔒</span>
                                <?php endif; ?>
                                <a href="<?= $item['url'] ?><?= $listQuerySuffix ?>" class="board-list__link">
                                    <?= $keyword ? $this->format->highlightKeyword($item['title_safe'], $keyword) : $item['title_safe'] ?>
                                </a>
                                <?php if ((int) ($item['comment_count'] ?? 0) > 0): ?>
                                    <span class="board-list__comment-count">[<?= $item['comment_count_formatted'] ?>]</span>
                                <?php endif; ?>
                                <?php if ($item['is_new']): ?>
                                    <span class="board-list__badge board-list__badge--new">N</span>
                                <?php endif; ?>
                            </td>
                            <td class="board-list__td board-list__td--author"><?= $item['author_name'] ?></td>
                            <td class="board-list__td board-list__td--date"><?= $item['date_relative'] ?></td>
                            <td class="board-list__td board-list__td--views"><?= $item['view_count_formatted'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 하단: 페이지네이션 + 검색 + 글쓰기 -->
    <div class="board-list__footer">
        <!-- 페이지네이션 -->
        <div class="board-list__pagination">
            <?= $this->pagination($pagination) ?>
        </div>

        <!-- 검색 폼 -->
        <form class="board-list__search" method="get" action="/board/<?= $boardSlug ?>">
            <select name="search_field" class="board-list__search-select">
                <option value="title" <?= $searchField === 'title' ? 'selected' : '' ?>>제목</option>
                <option value="content" <?= $searchField === 'content' ? 'selected' : '' ?>>내용</option>
                <option value="title_content" <?= $searchField === 'title_content' ? 'selected' : '' ?>>제목+내용</option>
            </select>
            <input type="text" name="keyword" value="<?= $keyword ?>"
                   class="board-list__search-input" placeholder="검색어 입력">
            <button type="submit" class="board-list__search-btn">검색</button>
        </form>

        <!-- 글쓰기 버튼 (권한 없으면 비활성 노출) -->
        <div class="board-list__actions">
            <?php if ($canWrite): ?>
                <a href="/board/<?= $boardSlug ?>/write" class="board-list__write-btn">글쓰기</a>
            <?php else: ?>
                <span class="board-list__write-btn board-list__write-btn--disabled"
                      aria-disabled="true" title="글쓰기 권한이 없습니다">글쓰기</span>
            <?php endif; ?>
        </div>
    </div>
</div>
