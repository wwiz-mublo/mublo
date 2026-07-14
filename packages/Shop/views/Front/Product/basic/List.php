<?php
/**
 * 상품 목록 (프론트) — 모던 쇼핑몰 레이아웃
 *
 * @var array $products 상품 목록 (ProductPresenter 변환 완료)
 * @var array $pagination 페이지네이션
 * @var array $categoryTree 카테고리 트리 (표준 규격: code, label, link, children)
 * @var array $filters 현재 필터 (category_code, keyword, sort)
 */

$keyword = htmlspecialchars($filters['keyword'] ?? '');
$currentCode = $filters['category_code'] ?? '';
$sorts = ['newest' => '최신순', 'price_asc' => '낮은가격순', 'price_desc' => '높은가격순', 'popular' => '인기순'];
$wishlistedIds = array_map('intval', $wishlistedIds ?? []);
$wishlistedSet = array_flip($wishlistedIds);

// 안전한 URL 생성
$buildUrl = function (array $overrides = []) use ($filters) {
    $params = array_merge($filters, $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return '/shop/products' . ($params ? '?' . http_build_query($params) : '');
};

// 트리에서 현재 카테고리명 + 루트 코드 찾기 (재귀)
$currentCategoryName = '';
$activeRootCode = '';
$findInTree = function (array $nodes, string $rootCode = '') use (&$findInTree, $currentCode, &$currentCategoryName, &$activeRootCode) {
    foreach ($nodes as $node) {
        $code = $node['code'] ?? '';
        $thisRoot = $rootCode ?: $code;
        if ($code === $currentCode) {
            $currentCategoryName = $node['label'] ?? '';
            $activeRootCode = $thisRoot;
            return true;
        }
        if (!empty($node['children']) && $findInTree($node['children'], $thisRoot)) {
            return true;
        }
    }
    return false;
};
if ($currentCode !== '') {
    $findInTree($categoryTree ?? []);
}

$this->assets->addCss('/serve/package/Shop/views/Front/Product/basic/_assets/css/product-list.css');
?>

<div class="spl">
    <!-- ================================================
         카테고리 내비게이션 (데스크탑: 상단 바 / 모바일: 드로어)
         ================================================ -->

    <!-- 모바일 필터 버튼 -->
    <button class="spl-filter-toggle" type="button" onclick="document.getElementById('splDrawer').classList.add('spl-drawer--open')">
        <i class="bi bi-funnel"></i>
        카테고리<?php if ($currentCategoryName): ?> : <strong><?= htmlspecialchars($currentCategoryName) ?></strong><?php endif; ?>
    </button>

    <!-- 데스크탑 카테고리 바 -->
    <nav class="spl-catnav">
        <a href="/shop/products"
           class="spl-catnav__item <?= $currentCode === '' ? 'spl-catnav__item--active' : '' ?>">전체</a>
        <?php foreach ($categoryTree ?? [] as $root): ?>
            <?php $rootCode = $root['code'] ?? ''; ?>
            <?php $hasChildren = !empty($root['children']); ?>
            <div class="spl-catnav__group <?= $activeRootCode === $rootCode ? 'spl-catnav__group--active' : '' ?>">
                <a href="<?= $buildUrl(['category_code' => $rootCode, 'page' => '']) ?>"
                   class="spl-catnav__item <?= $currentCode === $rootCode ? 'spl-catnav__item--active' : '' ?>"><?= htmlspecialchars($root['label'] ?? '') ?><?php if ($hasChildren): ?> <i class="bi bi-chevron-down spl-catnav__arrow"></i><?php endif; ?></a>
                <?php if ($hasChildren): ?>
                    <div class="spl-catnav__dropdown">
                        <?php foreach ($root['children'] as $child): ?>
                            <a href="<?= $buildUrl(['category_code' => $child['code'] ?? '', 'page' => '']) ?>"
                               class="spl-catnav__dropdown-item <?= $currentCode === ($child['code'] ?? '') ? 'spl-catnav__dropdown-item--active' : '' ?>"><?= htmlspecialchars($child['label'] ?? '') ?></a>
                            <?php if (!empty($child['children'])): ?>
                                <?php foreach ($child['children'] as $grandchild): ?>
                                    <a href="<?= $buildUrl(['category_code' => $grandchild['code'] ?? '', 'page' => '']) ?>"
                                       class="spl-catnav__dropdown-item spl-catnav__dropdown-item--sub <?= $currentCode === ($grandchild['code'] ?? '') ? 'spl-catnav__dropdown-item--active' : '' ?>"><?= htmlspecialchars($grandchild['label'] ?? '') ?></a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </nav>

    <!-- 모바일 드로어 -->
    <div class="spl-drawer" id="splDrawer">
        <div class="spl-drawer__backdrop" onclick="this.parentElement.classList.remove('spl-drawer--open')"></div>
        <div class="spl-drawer__panel">
            <div class="spl-drawer__header">
                <span class="spl-drawer__title">카테고리</span>
                <button class="spl-drawer__close" onclick="document.getElementById('splDrawer').classList.remove('spl-drawer--open')">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="spl-drawer__body">
                <a href="/shop/products"
                   class="spl-drawer__item <?= $currentCode === '' ? 'spl-drawer__item--active' : '' ?>"
                   onclick="document.getElementById('splDrawer').classList.remove('spl-drawer--open')">전체</a>
                <?php foreach ($categoryTree ?? [] as $root): ?>
                    <?php $rootCode = $root['code'] ?? ''; ?>
                    <?php $hasChildren = !empty($root['children']); ?>
                    <?php $isRootOpen = $activeRootCode === $rootCode; ?>
                    <div class="spl-drawer__group <?= $isRootOpen ? 'spl-drawer__group--open' : '' ?>">
                        <div class="spl-drawer__group-header">
                            <a href="<?= $buildUrl(['category_code' => $rootCode, 'page' => '']) ?>"
                               class="spl-drawer__item <?= $currentCode === $rootCode ? 'spl-drawer__item--active' : '' ?>"><?= htmlspecialchars($root['label'] ?? '') ?></a>
                            <?php if ($hasChildren): ?>
                                <button class="spl-drawer__toggle" type="button"
                                        onclick="this.closest('.spl-drawer__group').classList.toggle('spl-drawer__group--open')">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php if ($hasChildren): ?>
                            <div class="spl-drawer__children">
                                <?php foreach ($root['children'] as $child): ?>
                                    <a href="<?= $buildUrl(['category_code' => $child['code'] ?? '', 'page' => '']) ?>"
                                       class="spl-drawer__child <?= $currentCode === ($child['code'] ?? '') ? 'spl-drawer__child--active' : '' ?>"><?= htmlspecialchars($child['label'] ?? '') ?></a>
                                    <?php if (!empty($child['children'])): ?>
                                        <?php foreach ($child['children'] as $grandchild): ?>
                                            <a href="<?= $buildUrl(['category_code' => $grandchild['code'] ?? '', 'page' => '']) ?>"
                                               class="spl-drawer__child spl-drawer__child--depth2 <?= $currentCode === ($grandchild['code'] ?? '') ? 'spl-drawer__child--active' : '' ?>"><?= htmlspecialchars($grandchild['label'] ?? '') ?></a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ================================================
         툴바: 건수 + 검색 + 정렬
         ================================================ -->
    <div class="spl-toolbar">
        <div class="spl-toolbar__left">
            <span class="spl-toolbar__count">
                총 <strong><?= number_format($pagination['totalItems'] ?? 0) ?></strong>개
            </span>
            <?php if ($currentCategoryName): ?>
                <span class="spl-toolbar__current-cat">
                    <i class="bi bi-tag"></i> <?= htmlspecialchars($currentCategoryName) ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="spl-toolbar__right">
            <form method="get" action="/shop/products" class="spl-toolbar__search">
                <?php if ($currentCode !== ''): ?>
                    <input type="hidden" name="category_code" value="<?= htmlspecialchars($currentCode) ?>">
                <?php endif; ?>
                <?php if (!empty($filters['sort']) && $filters['sort'] !== 'newest'): ?>
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($filters['sort']) ?>">
                <?php endif; ?>
                <input type="text" name="keyword" class="spl-toolbar__search-input"
                       placeholder="상품명 검색" value="<?= $keyword ?>">
                <button type="submit" class="spl-toolbar__search-btn">
                    <i class="bi bi-search"></i>
                </button>
            </form>

            <select onchange="location.href=this.value" class="spl-toolbar__sort">
                <?php foreach ($sorts as $key => $label): ?>
                    <option value="<?= $buildUrl(['sort' => $key, 'page' => '']) ?>"
                            <?= ($filters['sort'] ?? 'newest') === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (!empty($keyword)): ?>
        <div class="spl-search-info">
            '<strong><?= $keyword ?></strong>' 검색 결과
            <a href="<?= $buildUrl(['keyword' => '', 'page' => '']) ?>" class="spl-search-info__clear">초기화</a>
        </div>
    <?php endif; ?>

    <!-- ================================================
         상품 그리드
         ================================================ -->
    <?php if (empty($products)): ?>
        <div class="spl-empty">
            <i class="bi bi-bag-x"></i>
            <?php if ($keyword): ?>
                <p>'<strong><?= $keyword ?></strong>'에 대한 검색 결과가 없습니다.</p>
            <?php else: ?>
                <p>등록된 상품이 없습니다.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="spl-grid">
            <?php foreach ($products as $product): ?>
                <div class="spl-card <?= ($product['is_soldout'] ?? false) ? 'spl-card--soldout' : '' ?>">
                    <a href="<?= $product['url'] ?>" class="spl-card__thumb">
                        <?php if (!empty($product['main_image_url'])): ?>
                            <img src="<?= htmlspecialchars($product['main_image_url']) ?>"
                                 class="spl-card__img"
                                 alt="<?= $product['goods_name_safe'] ?>"
                                 loading="lazy">
                        <?php else: ?>
                            <div class="spl-card__img spl-card__img--empty">
                                <i class="bi bi-image"></i>
                            </div>
                        <?php endif; ?>

                        <?php if ($product['is_soldout'] ?? false): ?>
                            <div class="spl-card__soldout">SOLD OUT</div>
                        <?php endif; ?>

                        <?php if (!empty($product['badges'])): ?>
                            <div class="spl-card__badges">
                                <?php foreach ($product['badges'] as $badge): ?>
                                    <?php if ($badge === 'new'): ?>
                                        <span class="spl-badge spl-badge--new">NEW</span>
                                    <?php elseif ($badge === 'sale'): ?>
                                        <span class="spl-badge spl-badge--sale">SALE</span>
                                    <?php elseif ($badge === 'soldout'): ?>
                                    <?php else: ?>
                                        <span class="spl-badge spl-badge--custom"><?= $badge ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (($product['has_discount'] ?? false) && !($product['is_soldout'] ?? false)): ?>
                            <span class="spl-card__discount-tag"><?= $product['discount_percent'] ?>%</span>
                        <?php endif; ?>

                        <?php $isWished = isset($wishlistedSet[(int) $product['goods_id']]); ?>
                        <button type="button"
                                class="spl-card__wish<?= $isWished ? ' spl-card__wish--active' : '' ?>"
                                title="찜하기"
                                data-goods-id="<?= $product['goods_id'] ?>">
                            <i class="bi <?= $isWished ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                        </button>
                    </a>

                    <div class="spl-card__body">
                        <a href="<?= $product['url'] ?>" class="spl-card__name">
                            <?= $keyword ? $this->format->highlightKeyword($product['goods_name_safe'], $keyword) : $product['goods_name_safe'] ?>
                        </a>

                        <div class="spl-card__price">
                            <?php if ($product['has_discount'] ?? false): ?>
                                <del class="spl-card__price-origin"><?= $product['display_price_formatted'] ?>원</del>
                            <?php endif; ?>
                            <span class="spl-card__price-current"><?= $product['sales_price_formatted'] ?>원</span>
                        </div>

                        <?php if ($product['has_reward'] ?? false): ?>
                            <div class="spl-card__reward">
                                <i class="bi bi-coin"></i> <?= number_format($product['point_amount']) ?>P 적립
                            </div>
                        <?php endif; ?>

                        <?php if ($product['has_reviews'] ?? false): ?>
                            <div class="spl-card__review">
                                <span class="spl-card__stars">
                                    <?php
                                    $rating = $product['average_rating'] ?? 0;
                                    for ($i = 1; $i <= 5; $i++):
                                        if ($i <= floor($rating)): ?>
                                            <i class="bi bi-star-fill"></i>
                                        <?php elseif ($i - $rating < 1 && $i - $rating > 0): ?>
                                            <i class="bi bi-star-half"></i>
                                        <?php else: ?>
                                            <i class="bi bi-star"></i>
                                        <?php endif;
                                    endfor; ?>
                                </span>
                                <span class="spl-card__review-count">(<?= $product['review_count_formatted'] ?>)</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- 페이지네이션 -->
    <?php if (!empty($pagination) && ($pagination['totalPages'] ?? 1) > 1): ?>
        <div class="spl-pagination">
            <?= $this->pagination($pagination) ?>
        </div>
    <?php endif; ?>
</div>

<script src="<?= asset('/serve/package/Shop/views/Front/Product/basic/_assets/js/product-list.js') ?>"></script>
