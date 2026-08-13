<?php
/**
 * 상품 목록
 *
 * View Context 접근:
 * - $this->columns() : ListColumnBuilder 팩토리
 * - $this->listRenderHelper : ListRenderHelper 인스턴스
 * - $this->pagination($data) : 페이지네이션 렌더링
 * - $this->component($name, $data) : 컴포넌트 렌더링
 *
 * @var string $pageTitle 페이지 제목
 * @var array $products 상품 목록
 * @var array $mainImages 상품 대표이미지 (goods_id 키)
 * @var array $shippingMethods 배송 템플릿 유형 맵 (shipping_id => shipping_method)
 * @var int $defaultShippingTemplateId 기본 배송 템플릿 ID (미지정 상품용)
 * @var array $pagination 페이지네이션
 * @var array $categories 카테고리 트리
 * @var array $filters 현재 필터
 */

// 카테고리 코드 → 경로명(브레드크럼) 맵
$categoryPathMap = [];
foreach ($categories ?? [] as $cat) {
    $categoryPathMap[$cat['category_code']] = $cat['path_name'] ?? $cat['name'] ?? '';
}

// 현재 검색/페이지 상태를 상세 화면에 넘길 return 쿼리로 직렬화
// (상세 → 목록 복귀 시 검색값 유지용)
$listParams = array_filter([
    'keyword' => $filters['keyword'] ?? '',
    'search_field' => $filters['search_field'] ?? '',
    'category_code' => $filters['category_code'] ?? '',
    'is_active' => $filters['is_active'] ?? '',
    'page' => (($pagination['currentPage'] ?? 1) > 1) ? (string) $pagination['currentPage'] : '',
], fn($v) => $v !== '' && $v !== null);
$returnQs = http_build_query($listParams);
$returnSuffix = $returnQs !== '' ? '?return=' . urlencode($returnQs) : '';

// 컬럼 정의
$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'goods_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('goods_id', '번호', ['_th_attr' => ['style' => 'width:60px', 'class' => 'text-nowrap text-center'], '_td_attr' => ['class' => 'text-nowrap text-center']])
    ->callback('goods_name', '상품명', function ($row) use ($mainImages, $categoryPathMap, $returnSuffix) {
        $id = $row['goods_id'];
        $name = htmlspecialchars($row['goods_name'] ?? '');

        // 대표이미지 썸네일 (없으면 placeholder)
        $img = $mainImages[$id] ?? null;
        $thumbUrl = $img['thumbnail_url'] ?? $img['image_url'] ?? null;
        if ($thumbUrl) {
            $thumb = '<img src="' . htmlspecialchars($thumbUrl) . '" alt="" class="rounded" style="width:48px;height:48px;object-fit:cover;">';
        } else {
            $thumb = '<span class="d-inline-flex align-items-center justify-content-center rounded border border-secondary-subtle bg-secondary-subtle text-secondary" style="width:48px;height:48px;"><i class="bi bi-card-image"></i></span>';
        }

        // 상품명 + 배지
        $nameHtml = '<a href="/admin/shop/products/' . $id . '/edit' . $returnSuffix . '" class="fw-medium">' . $name . '</a>';
        if (!empty($row['goods_badge'])) {
            $badge = htmlspecialchars($row['goods_badge']);
            $nameHtml .= ' <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">' . $badge . '</span>';
        }
        // 프론트 상품 뷰 (새창)
        $slug = $row['goods_slug'] ?? '';
        $frontUrl = "/shop/products/{$id}" . ($slug !== '' ? '/' . rawurlencode($slug) : '');
        $nameHtml .= ' <a href="' . htmlspecialchars($frontUrl) . '" target="_blank" rel="noopener" title="상품 보기" class="text-muted ms-1"><i class="bi bi-box-arrow-up-right"></i></a>';

        // 카테고리 브레드크럼
        $path = $categoryPathMap[$row['category_code'] ?? ''] ?? '';
        $crumb = $path !== ''
            ? htmlspecialchars(str_replace('>', ' › ', $path))
            : '미분류';
        $crumbHtml = '<div class="small text-muted"><i class="bi bi-folder2"></i> ' . $crumb . '</div>';

        return '<div class="d-flex align-items-center gap-2">'
            . '<div class="flex-shrink-0">' . $thumb . '</div>'
            . '<div class="flex-grow-1">' . $nameHtml . $crumbHtml . '</div>'
            . '</div>';
    }, ['_th_attr' => ['class' => 'w-100'], '_td_attr' => ['class' => 'text-wrap w-100']])
    ->callback('display_price', '판매가', function ($row) {
        $price = number_format($row['display_price'] ?? 0);
        $html = "<div>{$price}</div>";
        $origin = (int) ($row['origin_price'] ?? 0);
        if ($origin > 0) {
            $html .= '<div class="small text-muted text-decoration-line-through">' . number_format($origin) . '</div>';
        }
        return $html;
    }, ['_th_attr' => ['class' => 'text-end text-nowrap', 'style' => 'width:100px'], '_td_attr' => ['class' => 'text-end text-nowrap']])
    ->callback('shipping', '배송', function ($row) use ($shippingMethods, $defaultShippingTemplateId) {
        // 묶음/개별 (상품 직접 값)
        $apply = ($row['shipping_apply_type'] ?? 'COMBINED') === 'SEPARATE' ? '개별' : '묶음';
        // 템플릿 유형: 상품에 미지정이면 쇼핑몰 기본 배송 템플릿을 따름(구분 표기용)
        $isDefaultTpl = (int) ($row['shipping_template_id'] ?? 0) <= 0;
        $tid = $isDefaultTpl ? $defaultShippingTemplateId : (int) $row['shipping_template_id'];
        $method = $shippingMethods[$tid] ?? '';
        $methodLabel = $method !== ''
            ? (\Mublo\Packages\Shop\Enum\ShippingMethod::tryFrom($method)?->label() ?? $method)
            : '-';
        // 쇼핑몰 기본 설정이면 muted, 상품 개별 지정이면 기본 색
        $methodHtml = $isDefaultTpl
            ? '<div class="text-muted" title="쇼핑몰 기본 배송">' . htmlspecialchars($methodLabel) . '</div>'
            : '<div title="상품 개별 배송">' . htmlspecialchars($methodLabel) . '</div>';
        return $methodHtml
            . '<div class="small text-muted"><i class="bi bi-box-seam"></i> ' . $apply . '</div>';
    }, ['_th_attr' => ['class' => 'text-center text-nowrap', 'style' => 'width:90px'], '_td_attr' => ['class' => 'text-center text-nowrap']])
    ->callback('stock_quantity', '재고', function ($row) {
        // 옵션 상품은 재고가 옵션/조합에 있어 상품레벨은 NULL → 별도 표기 (집계는 추후)
        if (($row['option_mode'] ?? 'NONE') !== 'NONE') {
            return '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">옵션별</span>';
        }
        $stock = $row['stock_quantity'] ?? null;
        if ($stock === null) {
            return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">무한</span>';  // 미관리 = 무한판매
        }
        if ((int) $stock <= 0) {
            return '<span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">품절</span>';
        }
        return number_format((int) $stock);
    }, ['_th_attr' => ['class' => 'text-center text-nowrap', 'style' => 'width:72px'], '_td_attr' => ['class' => 'text-center text-nowrap']])
    ->callback('option_mode', '옵션', function ($row) {
        $mode = $row['option_mode'] ?? 'NONE';
        if ($mode === 'NONE') {
            return '<span class="text-muted">-</span>';
        }
        $label = \Mublo\Packages\Shop\Enum\OptionMode::tryFrom($mode)?->label() ?? $mode;
        return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">' . htmlspecialchars($label) . '</span>';
    }, ['_th_attr' => ['class' => 'text-center text-nowrap', 'style' => 'width:72px'], '_td_attr' => ['class' => 'text-center text-nowrap']])
    ->callback('is_active', '상태', function ($row) {
        if ($row['is_active']) {
            return '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">판매중</span>';
        }
        return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">중지</span>';
    }, ['_th_attr' => ['class' => 'text-center text-nowrap', 'style' => 'width:64px'], '_td_attr' => ['class' => 'text-center text-nowrap']])
    ->callback('hit', '조회', function ($row) {
        return number_format($row['hit'] ?? 0);
    }, ['_th_attr' => ['class' => 'text-center text-nowrap', 'style' => 'width:60px'], '_td_attr' => ['class' => 'text-center text-nowrap']])
    ->callback('wishlist_count', '찜', function ($row) {
        return number_format($row['wishlist_count'] ?? 0);
    }, ['_th_attr' => ['class' => 'text-center text-nowrap', 'style' => 'width:60px'], '_td_attr' => ['class' => 'text-center text-nowrap']])
    ->callback('sold_count', '판매', function ($row) {
        return number_format($row['sold_count'] ?? 0);
    }, ['_th_attr' => ['class' => 'text-center text-nowrap', 'style' => 'width:60px'], '_td_attr' => ['class' => 'text-center text-nowrap']])
    ->callback('inquiry_count', '문의', function ($row) {
        return number_format($row['inquiry_count'] ?? 0);
    }, ['_th_attr' => ['class' => 'text-center text-nowrap', 'style' => 'width:60px'], '_td_attr' => ['class' => 'text-center text-nowrap']])
    ->callback('review_count', '후기', function ($row) {
        return number_format($row['review_count'] ?? 0);
    }, ['_th_attr' => ['class' => 'text-center text-nowrap', 'style' => 'width:60px'], '_td_attr' => ['class' => 'text-center text-nowrap']])
    ->callback('rating_avg', '평점', function ($row) {
        $avg = (float) ($row['rating_avg'] ?? 0);
        if ($avg <= 0) {
            return '<span class="text-muted">-</span>';
        }
        return '<i class="bi bi-star-fill text-warning"></i> ' . number_format($avg, 1);
    }, ['_th_attr' => ['class' => 'text-center text-nowrap', 'style' => 'width:72px'], '_td_attr' => ['class' => 'text-center text-nowrap']])
    ->actions('actions', '관리', function ($row) use ($returnSuffix) {
        $id = $row['goods_id'];
        return '
            <a href="/admin/shop/products/' . $id . '/edit' . $returnSuffix . '" class="btn btn-sm btn-default">수정</a>
            <button type="button" class="btn btn-sm btn-default" onclick="deleteProduct(' . $id . ')">삭제</button>
        ';
    }, ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:110px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '상품 관리') ?></h3>
            <p>쇼핑몰 상품을 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/shop/products/create<?= $returnSuffix ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> 상품 등록
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 검색 영역 -->
        <form method="get" name="fsearch" id="fsearch" class="mb-2">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/shop/products">전체</a></span>
                        <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? 0) ?></b> 건</span>
                    </span>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <div class="col col-xl-auto">
                            <select name="category_code" class="form-select form-select-sm">
                                <option value="">전체 카테고리</option>
                                <?php foreach ($categories ?? [] as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['category_code']) ?>"
                                        <?= ($filters['category_code'] ?? '') === $cat['category_code'] ? 'selected' : '' ?>>
                                    <?= str_repeat('─ ', ($cat['depth'] ?? 1) - 1) ?><?= htmlspecialchars($cat['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col col-xl-auto">
                            <select name="is_active" class="form-select form-select-sm">
                                <option value="">전체 상태</option>
                                <option value="1" <?= ($filters['is_active'] ?? '') === '1' ? 'selected' : '' ?>>판매중</option>
                                <option value="0" <?= ($filters['is_active'] ?? '') === '0' ? 'selected' : '' ?>>판매중지</option>
                            </select>
                        </div>
                        <div class="col col-xl-auto">
                            <div class="search-wrapper">
                                <label for="search_keyword" class="visually-hidden">검색</label>
                                <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm"
                                       placeholder="상품명 검색"
                                       value="<?= htmlspecialchars($filters['keyword'] ?? '') ?>">
                                <i class="bi bi-search search-icon"></i>
                                <?php if (!empty($filters['keyword'])): ?>
                                <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/shop/products'"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-default">
                                <i class="bi bi-search"></i> 검색
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <!-- 상품 목록 폼 -->
        <form name="flist" id="flist">
            <div class="table-responsive">
                <?= $this->listRenderHelper
                    ->setColumns($columns)
                    ->setRows($products)
                    ->setSkin('table/basic')
                    ->setWrapAttr(['class' => 'table table-hover align-middle'])
                    ->showHeader(true)
                    ->render() ?>
            </div>

            <!-- 하단 액션바 + 페이지네이션 -->
            <div class="row gx-2 justify-content-between align-items-center my-2">
                <div class="col-auto">
                    <div class="d-flex gap-1">
                        <button
                            type="button"
                            class="btn btn-sm btn-default mublo-submit"
                            data-target="/admin/shop/products/listDelete"
                            data-callback="afterBulkDelete"
                            data-confirm="선택한 상품을 삭제하시겠습니까?"
                        >
                            <i class="d-inline d-md-none bi bi-trash"></i>
                            <span class="d-none d-md-inline">선택 삭제</span>
                        </button>
                    </div>
                </div>
                <div class="col-auto">
                    <?= $this->pagination($pagination) ?>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// 전체 선택
document.addEventListener('DOMContentLoaded', function() {
    const checkAll = document.querySelector('input[name="chk_all"]');
    if (checkAll) {
        checkAll.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="chk[]"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = checkAll.checked;
            });
        });
    }
});

// 상품 삭제 (단건)
function deleteProduct(goodsId) {
    if (!confirm('정말 이 상품을 삭제하시겠습니까?\n\n이 작업은 되돌릴 수 없습니다.')) {
        return;
    }

    MubloRequest.requestJson('/admin/shop/products/' + goodsId + '/delete', {}, { method: 'POST', loading: true })
        .then(function(data) {
            if (data.result === 'success') {
                location.reload();
            } else {
                alert(data.message || '삭제에 실패했습니다.');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            alert('삭제 중 오류가 발생했습니다.');
        });
}

// 일괄 삭제 후 콜백
function afterBulkDelete(data) {
    if (data.result === 'success') {
        alert(data.message || '삭제되었습니다.');
        location.reload();
    } else {
        alert(data.message || '삭제에 실패했습니다.');
    }
}
</script>
