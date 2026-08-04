<?php
/**
 * 전체 검색 결과 뷰 (basic skin)
 *
 * @var string $keyword  검색 키워드
 * @var array  $groups   소스별 결과 그룹
 *   - source       : 소스 식별자 ('board', 'mshop' 등)
 *   - source_label : 소스 표시명
 *   - items        : 결과 항목 배열
 *     - title     : 제목
 *     - url       : 링크 URL
 *     - summary   : 요약 (선택)
 *     - thumbnail : 썸네일 URL (선택)
 *     - date      : 날짜 (선택)
 *     - meta      : 부가 정보 — 게시판명 등 (선택)
 *   - total        : 해당 소스 전체 결과 수
 */

$this->assets->addCss('/serve/front/view/search/basic/css/search.css');

$keyword = htmlspecialchars($keyword ?? '');
$groups  = $groups ?? [];
$totalCount = array_sum(array_column($groups, 'total'));
?>

<div class="search-page container">

    <!-- 검색 폼 -->
    <form action="/search" method="get" class="search-form">
        <div class="search-form__inner">
            <i class="bi bi-search search-form__icon"></i>
            <input type="text" name="q" class="search-form__input"
                   placeholder="검색어를 입력하세요"
                   value="<?= $keyword ?>"
                   autocomplete="off">
            <button type="button" class="search-form__clear" onclick="this.closest('form').querySelector('input').value='';this.closest('form').querySelector('input').focus();">
                <i class="bi bi-x-lg"></i>
            </button>
            <button type="submit" class="search-form__btn">검색</button>
        </div>
    </form>

    <?php if ($keyword === ''): ?>
        <div class="search-empty text-center py-5">
            <i class="bi bi-search search-empty__icon"></i>
            <p class="search-empty__text">검색어를 입력해주세요.</p>
        </div>
    <?php elseif (empty($groups) || $totalCount === 0): ?>
        <div class="search-empty text-center py-5">
            <i class="bi bi-inbox search-empty__icon"></i>
            <p class="search-empty__text">'<strong><?= $keyword ?></strong>'에 대한 검색 결과가 없습니다.</p>
            <p class="search-empty__hint">다른 검색어를 입력하거나, 띄어쓰기를 확인해보세요.</p>
        </div>
    <?php else: ?>
        <p class="search-summary">
            '<strong><?= $keyword ?></strong>' 검색 결과
            <span class="search-summary__count"><?= number_format($totalCount) ?>건</span>
        </p>

        <?php foreach ($groups as $group): ?>
            <?php if (empty($group['items'])) continue; ?>
            <section class="search-group">
                <h5 class="search-group__title">
                    <?= htmlspecialchars($group['source_label']) ?>
                    <span class="search-group__count"><?= number_format($group['total']) ?></span>
                </h5>

                <?php
                $viewPath = $group['view_path'] ?? null;
                $moreUrl  = $group['more_url'] ?? null;
                if ($viewPath && is_file($viewPath)):
                ?>
                    <div class="md-grid">
                        <?php foreach ($group['items'] as $device): ?>
                            <?php include $viewPath; ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <ul class="search-result-list">
                        <?php foreach ($group['items'] as $item): ?>
                            <li class="search-result-item">
                                <?php if (!empty($item['thumbnail'])): ?>
                                    <a href="<?= htmlspecialchars($item['url']) ?>" class="search-result-item__thumb-link">
                                        <img src="<?= htmlspecialchars($item['thumbnail']) ?>"
                                             alt="" class="search-result-item__thumb">
                                    </a>
                                <?php endif; ?>
                                <div class="search-result-item__body">
                                    <a href="<?= htmlspecialchars($item['url']) ?>" class="search-result-item__title">
                                        <?= htmlspecialchars($item['title']) ?>
                                    </a>
                                    <?php if (!empty($item['summary'])): ?>
                                        <p class="search-result-item__summary">
                                            <?= htmlspecialchars($item['summary']) ?>
                                        </p>
                                    <?php endif; ?>
                                    <div class="search-result-item__meta">
                                        <?php if (!empty($item['meta'])): ?>
                                            <span class="search-result-item__badge"><?= htmlspecialchars($item['meta']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($item['date'])): ?>
                                            <span class="search-result-item__date"><?= htmlspecialchars($item['date']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($moreUrl && $group['total'] > count($group['items'])): ?>
                    <div class="search-group__more">
                        <a href="<?= htmlspecialchars($moreUrl) ?>">
                            전체 <?= number_format($group['total']) ?>건 더보기 <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

