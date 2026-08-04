<?php
/**
 * Community Feed View (basic skin)
 *
 * 커뮤니티 통합 피드 기본 스킨
 *
 * @var array $items 게시글 목록 (ArticlePresenter 변환 완료)
 * @var array $pagination 페이지네이션
 * @var array $filters 검색 필터 (keyword, search_field)
 * @var array $groups 게시판 그룹 목록
 * @var string|null $currentGroup 현재 그룹 slug (null=전체)
 * @var string $sortBy 현재 정렬 (latest|popular)
 *
 * [Presenter 제공 필드]
 * - author_name / author_name_masked: 글쓴이 (원본/마스킹, 이스케이프 완료)
 * - is_member: 회원 여부
 * - title_safe: htmlspecialchars 적용된 제목
 * - url: 게시글 상세 URL
 * - date_relative / date_short: 날짜 포맷
 * - view_count_formatted / comment_count_formatted: 포맷된 통계
 * - badges: 배지 배열
 * - is_new: 신규 여부
 * - board_name / board_slug / group_name: 커뮤니티 전용
 */

$keyword = htmlspecialchars($filters['keyword'] ?? '');
$searchField = $filters['search_field'] ?? 'title';

$baseUrl = $currentGroup
    ? '/community/group/' . htmlspecialchars($currentGroup)
    : '/community';

$this->assets->addCss('/serve/package/Board/views/Front/Community/basic/_assets/css/community.css');
?>

<div class="community-feed">
    <!-- 헤더 -->
    <div class="community-feed__header">
        <h2 class="community-feed__title">커뮤니티</h2>
    </div>

    <!-- 그룹 탭 -->
    <?php if (!empty($groups)): ?>
    <div class="community-feed__groups">
        <a href="/community"
           class="community-feed__group-tab<?= $currentGroup === null ? ' community-feed__group-tab--active' : '' ?>">
            전체
        </a>
        <?php foreach ($groups as $group): ?>
            <a href="/community/group/<?= htmlspecialchars($group['group_slug']) ?>"
               class="community-feed__group-tab<?= $currentGroup === ($group['group_slug'] ?? '') ? ' community-feed__group-tab--active' : '' ?>">
                <?= htmlspecialchars($group['group_name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- 정렬 탭 -->
    <div class="community-feed__sort">
        <a href="<?= $baseUrl ?>"
           class="community-feed__sort-tab<?= $sortBy === 'latest' ? ' community-feed__sort-tab--active' : '' ?>">
            최신글
        </a>
        <a href="<?= $baseUrl ?>?sort=popular"
           class="community-feed__sort-tab<?= $sortBy === 'popular' ? ' community-feed__sort-tab--active' : '' ?>">
            인기글
        </a>
    </div>

    <!-- 게시글 목록 -->
    <div class="community-feed__list">
        <?php if (empty($items)): ?>
            <div class="community-feed__empty">
                <?= $keyword ? '검색 결과가 없습니다.' : '등록된 글이 없습니다.' ?>
            </div>
        <?php else: ?>
            <table class="community-feed__table">
                <thead>
                    <tr>
                        <th class="community-feed__th community-feed__th--board">게시판</th>
                        <th class="community-feed__th community-feed__th--title">제목</th>
                        <th class="community-feed__th community-feed__th--author">글쓴이</th>
                        <th class="community-feed__th community-feed__th--date">날짜</th>
                        <th class="community-feed__th community-feed__th--views">조회</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item):
                        $isNotice = in_array('notice', $item['badges']);
                        $boardSlugSafe = htmlspecialchars($item['board_slug'] ?? '');
                    ?>
                        <tr class="community-feed__row<?= $isNotice ? ' community-feed__row--notice' : '' ?>">
                            <td class="community-feed__td community-feed__td--board">
                                <a href="/board/<?= $boardSlugSafe ?>" class="community-feed__board-link">
                                    <?= htmlspecialchars($item['board_name'] ?? '') ?>
                                </a>
                            </td>
                            <td class="community-feed__td community-feed__td--title">
                                <?php if ($isNotice): ?>
                                    <span class="community-feed__badge community-feed__badge--notice">공지</span>
                                <?php endif; ?>
                                <?php if (in_array('secret', $item['badges'])): ?>
                                    <span class="community-feed__icon community-feed__icon--secret">🔒</span>
                                <?php endif; ?>
                                <a href="<?= $item['url'] ?>" class="community-feed__link">
                                    <?= $keyword ? $this->format->highlightKeyword($item['title_safe'], $keyword) : $item['title_safe'] ?>
                                </a>
                                <?php if ((int) ($item['comment_count'] ?? 0) > 0): ?>
                                    <span class="community-feed__comment-count">[<?= $item['comment_count_formatted'] ?>]</span>
                                <?php endif; ?>
                                <?php if ($item['is_new']): ?>
                                    <span class="community-feed__badge community-feed__badge--new">N</span>
                                <?php endif; ?>
                            </td>
                            <td class="community-feed__td community-feed__td--author"><?= $item['author_name'] ?></td>
                            <td class="community-feed__td community-feed__td--date"><?= $item['date_relative'] ?></td>
                            <td class="community-feed__td community-feed__td--views"><?= $item['view_count_formatted'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- 하단: 페이지네이션 + 검색 -->
    <div class="community-feed__footer">
        <div class="community-feed__pagination">
            <?= $this->pagination($pagination) ?>
        </div>

        <form class="community-feed__search" method="get" action="<?= $baseUrl ?>">
            <?php if ($sortBy !== 'latest'): ?>
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>">
            <?php endif; ?>
            <select name="search_field" class="community-feed__search-select">
                <option value="title" <?= $searchField === 'title' ? 'selected' : '' ?>>제목</option>
                <option value="content" <?= $searchField === 'content' ? 'selected' : '' ?>>내용</option>
                <option value="title_content" <?= $searchField === 'title_content' ? 'selected' : '' ?>>제목+내용</option>
            </select>
            <input type="text" name="keyword" value="<?= $keyword ?>"
                   class="community-feed__search-input" placeholder="검색어 입력">
            <button type="submit" class="community-feed__search-btn">검색</button>
        </form>
    </div>
</div>
