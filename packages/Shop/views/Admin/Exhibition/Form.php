<?php
/**
 * 기획전 등록/수정 폼
 *
 * @var string     $pageTitle
 * @var array|null $exhibition
 * @var array      $items
 */
$isEdit = !empty($exhibition);
$eid    = (int) ($exhibition['exhibition_id'] ?? 0);
$items  = $items ?? [];
?>
<form id="exhibitionForm">
    <input type="hidden" name="formData[exhibition_id]" value="<?= $eid ?>">
    <div class="page-container form-container">
        <div class="page-title">
            <div class="page-title-text">
                <h3><?= htmlspecialchars($pageTitle) ?></h3>
                <p>기획전 정보와 연결 상품·노출 기간을 설정합니다.</p>
            </div>
            <div class="page-title-actions">
                <a href="/admin/shop/exhibitions" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-list"></i> 목록
                </a>
                <button type="button" class="btn btn-sm btn-primary btn-save-exhibition">
                    <i class="bi bi-check-lg"></i> <?= $isEdit ? '수정' : '등록' ?>
                </button>
            </div>
        </div>

        <div class="page-block row">
            <!-- 기본 정보 -->
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-info-circle text-pastel-blue"></i>
                        <span>기본 정보</span>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">기획전 제목 <span class="text-danger">*</span></label>
                            <input type="text" name="formData[title]" class="form-control"
                                   value="<?= htmlspecialchars($exhibition['title'] ?? '') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">슬러그 (URL)</label>
                            <input type="text" id="exhibitionSlug" name="formData[slug]" class="form-control"
                                   placeholder="url-friendly-slug"
                                   value="<?= htmlspecialchars($exhibition['slug'] ?? '') ?>">
                            <div class="form-text">영문 소문자·숫자·하이픈(-)만 가능합니다. 비우면 번호(ID) 기반 URL을 사용합니다.</div>
                            <div id="slugPreview" class="form-text d-none">
                                <code><span class="text-muted">/shop/exhibitions/</span><span id="slugPreviewText" class="text-success"></span></code>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">설명</label>
                            <textarea name="formData[description]" class="form-control" rows="3"><?= htmlspecialchars($exhibition['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- 배너 이미지 -->
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-image text-pastel-green"></i>
                        <span>배너 이미지</span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?php
                            $banners = [
                                'pc'     => ['label' => 'PC 배너',   'field' => 'banner_image',        'urlId' => 'bannerPcUrl',     'previewId' => 'bannerPcPreview'],
                                'mobile' => ['label' => '모바일 배너', 'field' => 'banner_mobile_image', 'urlId' => 'bannerMobileUrl', 'previewId' => 'bannerMobilePreview'],
                            ];
                            foreach ($banners as $bkey => $b):
                                $cur = $exhibition[$b['field']] ?? '';
                            ?>
                            <div class="col-md-6">
                                <label class="form-label"><?= $b['label'] ?></label>
                                <input type="hidden" name="formData[<?= $b['field'] ?>]" id="<?= $b['urlId'] ?>" value="<?= htmlspecialchars($cur) ?>">
                                <div class="d-flex align-items-start gap-2">
                                    <div id="<?= $b['previewId'] ?>" class="border rounded bg-body-tertiary d-flex align-items-center justify-content-center flex-shrink-0 position-relative" style="width:120px;height:72px;overflow:hidden;">
                                        <?php if ($cur !== ''): ?>
                                        <img src="<?= htmlspecialchars($cur) ?>" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;">
                                        <?php else: ?>
                                        <i class="bi bi-image text-muted fs-4"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <input type="file" class="form-control banner-file" data-target="<?= $bkey ?>" accept="image/jpeg,image/png,image/gif,image/webp">
                                        <div class="mt-1">
                                            <button type="button" class="btn btn-outline-secondary banner-remove" data-target="<?= $bkey ?>">제거</button>
                                            <span class="form-text banner-status ms-1" data-target="<?= $bkey ?>"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- 연결 상품 -->
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-box-seam text-pastel-purple"></i>
                        <span>연결 상품 <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle ms-1" id="itemCount"><?= count($items) ?></span></span>
                        <button type="button" class="btn btn-sm btn-default ms-auto" id="btnOpenSearch">
                            <i class="bi bi-plus-lg"></i> 상품/카테고리 추가
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:50px" class="text-center">#</th>
                                        <th style="width:70px" class="text-center">유형</th>
                                        <th>대상</th>
                                        <th style="width:50px" class="text-center">삭제</th>
                                    </tr>
                                </thead>
                                <tbody id="selectedItems"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 설정 패널 -->
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-gear text-pastel-orange"></i>
                        <span>설정</span>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">활성화</label>
                            <select name="formData[is_active]" class="form-select">
                                <option value="1" <?= ($exhibition['is_active'] ?? 1) ? 'selected' : '' ?>>활성</option>
                                <option value="0" <?= isset($exhibition['is_active']) && !$exhibition['is_active'] ? 'selected' : '' ?>>비활성</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">정렬 순서</label>
                            <input type="number" name="formData[sort_order]" class="form-control"
                                   value="<?= (int) ($exhibition['sort_order'] ?? 0) ?>" min="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">시작일</label>
                            <input type="datetime-local" name="formData[start_date]" class="form-control"
                                   value="<?= !empty($exhibition['start_date']) ? str_replace(' ', 'T', substr($exhibition['start_date'], 0, 16)) : '' ?>">
                        </div>
                        <div class="mb-0">
                            <label class="form-label">종료일</label>
                            <input type="datetime-local" name="formData[end_date]" class="form-control"
                                   value="<?= !empty($exhibition['end_date']) ? str_replace(' ', 'T', substr($exhibition['end_date'], 0, 16)) : '' ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 하단 버튼 -->
        <div class="d-flex justify-content-center gap-2">
            <a href="/admin/shop/exhibitions" class="btn btn-secondary">목록</a>
            <button type="button" class="btn btn-primary btn-save-exhibition">
                <?= $isEdit ? '수정' : '등록' ?>
            </button>
        </div>
    </div>
</form>

<!-- 상품/카테고리 추가 모달 -->
<div class="modal fade" id="exhPickerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">상품 / 카테고리 추가</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="닫기"></button>
            </div>
            <div class="modal-body">
                <div class="exh-modal-tabs">
                    <button type="button" class="exh-modal-tab active" data-tab="goods">상품 검색</button>
                    <button type="button" class="exh-modal-tab" data-tab="category">카테고리 선택</button>
                </div>
                <div id="exhTabGoods">
                    <input type="text" id="exhSearchInput" class="form-control mb-2" placeholder="상품명으로 검색...">
                    <div id="exhSearchResults" class="exh-search-results"><div class="text-center text-muted py-4">로딩 중...</div></div>
                    <div id="exhSearchPaging" class="text-center mt-2"></div>
                </div>
                <div id="exhTabCategory" style="display:none">
                    <div id="exhCategoryList" class="exh-search-results"><div class="text-center text-muted py-4">로딩 중...</div></div>
                </div>
            </div>
            <div class="modal-footer">
                <span id="exhSelectedCount" class="text-muted small me-auto"></span>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">확인</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    // ── 슬러그 URL 미리보기 (입력 시 실시간, 소문자 반영) ──
    (function() {
        var slugInput = document.getElementById('exhibitionSlug');
        var preview = document.getElementById('slugPreview');
        var previewText = document.getElementById('slugPreviewText');
        if (slugInput && preview && previewText) {
            var updateSlugPreview = function() {
                // 허용 문자만 실시간 필터 (소문자·숫자·하이픈), 비허용 문자·대문자 자동 정제
                var cleaned = slugInput.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
                if (cleaned !== slugInput.value) { slugInput.value = cleaned; }
                if (cleaned === '') { preview.classList.add('d-none'); return; }
                previewText.textContent = cleaned;
                preview.classList.remove('d-none');
            };
            slugInput.addEventListener('input', updateSlugPreview);
            updateSlugPreview();
        }
    })();

    // ── 배너 이미지 즉시 업로드 + 미리보기 ──
    (function() {
        var map = {
            pc:     { url: 'bannerPcUrl',     preview: 'bannerPcPreview' },
            mobile: { url: 'bannerMobileUrl', preview: 'bannerMobilePreview' }
        };
        function setPreview(target, url) {
            var box = document.getElementById(map[target].preview);
            if (box) {
                box.innerHTML = url
                    ? '<img src="' + url + '" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;">'
                    : '<i class="bi bi-image text-muted fs-4"></i>';
            }
        }
        function setStatus(target, msg, cls) {
            var s = document.querySelector('.banner-status[data-target="' + target + '"]');
            if (s) { s.textContent = msg; s.className = 'form-text banner-status ms-1 ' + (cls || ''); }
        }
        document.querySelectorAll('.banner-file').forEach(function(input) {
            input.addEventListener('change', function() {
                var target = input.dataset.target;
                var file = input.files && input.files[0];
                if (!file) return;
                var fd = new FormData();
                fd.append('fileData[banner]', file);
                setStatus(target, '업로드 중...', 'text-muted');
                MubloRequest.sendRequest({
                    method: 'POST',
                    url: '/admin/shop/exhibitions/upload-banner',
                    payloadType: MubloRequest.PayloadType.FORM,
                    data: fd
                }).then(function(res) {
                    var url = res && res.data && res.data.url;
                    if (!url) { setStatus(target, '업로드 실패', 'text-danger'); return; }
                    document.getElementById(map[target].url).value = url;
                    setPreview(target, url);
                    setStatus(target, '업로드 완료', 'text-success');
                }).catch(function() {
                    setStatus(target, '업로드 실패', 'text-danger');
                });
                input.value = '';
            });
        });
        document.querySelectorAll('.banner-remove').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var target = btn.dataset.target;
                document.getElementById(map[target].url).value = '';
                setPreview(target, '');
                setStatus(target, '', '');
            });
        });
    })();

    // ── 선택 항목 통합 관리 (goods + category) ──
    var selectedItems = <?= json_encode(array_values(array_map(function($i) {
        if (($i['target_type'] ?? '') === 'category') {
            return [
                'target_type'   => 'category',
                'category_code' => $i['category_code'] ?? '',
                'category_name' => $i['category_name'] ?? $i['category_code'] ?? '',
            ];
        }
        return [
            'target_type' => 'goods',
            'goods_id'    => (int) ($i['goods_id'] ?? 0),
            'goods_name'  => $i['goods_name'] ?? '',
            'sell_price'  => $i['display_price'] ?? $i['sell_price'] ?? '',
            'thumb_url'   => $i['product_image'] ?? $i['thumb_url'] ?? '',
        ];
    }, $items)), JSON_UNESCAPED_UNICODE) ?>;

    // 빠른 중복 체크용 Set
    var selectedGoodsIds = new Set(selectedItems.filter(function(i) { return i.target_type === 'goods'; }).map(function(i) { return String(i.goods_id); }));
    var selectedCatCodes = new Set(selectedItems.filter(function(i) { return i.target_type === 'category'; }).map(function(i) { return i.category_code; }));

    function updateItemCount() {
        document.getElementById('itemCount').textContent = selectedItems.length;
    }

    function renderSelectedTable() {
        var tbody = document.getElementById('selectedItems');
        if (!selectedItems.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">' +
                '<i class="bi bi-inbox" style="font-size:1.5rem;opacity:.3"></i>' +
                '<div class="small mt-1">상품/카테고리를 추가하세요</div></td></tr>';
            updateItemCount();
            return;
        }
        tbody.innerHTML = selectedItems.map(function(item, i) {
            var key = item.target_type === 'goods' ? 'goods-' + item.goods_id : 'cat-' + item.category_code;
            if (item.target_type === 'category') {
                return '<tr data-key="' + key + '">' +
                    '<td class="text-center text-muted">' + (i + 1) + '</td>' +
                    '<td class="text-center"><span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">카테고리</span></td>' +
                    '<td><i class="bi bi-folder me-1 text-muted"></i>' + (item.category_name || item.category_code) + '</td>' +
                    '<td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-item"><i class="bi bi-x-lg"></i></button></td></tr>';
            }
            var thumb = item.thumb_url
                ? '<img src="' + item.thumb_url + '" style="width:28px;height:28px;object-fit:cover;border-radius:3px;vertical-align:middle" class="me-1">'
                : '';
            return '<tr data-key="' + key + '">' +
                '<td class="text-center text-muted">' + (i + 1) + '</td>' +
                '<td class="text-center"><span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">상품</span></td>' +
                '<td>' + thumb + (item.goods_name || '#' + item.goods_id) + ' <span class="text-muted small">#' + item.goods_id + '</span></td>' +
                '<td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-item"><i class="bi bi-x-lg"></i></button></td></tr>';
        }).join('');
        updateItemCount();
    }

    renderSelectedTable();

    // 삭제 이벤트 위임
    document.getElementById('selectedItems').addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-remove-item');
        if (!btn) return;
        var tr = btn.closest('tr');
        var key = tr.dataset.key;
        selectedItems = selectedItems.filter(function(item) {
            var k = item.target_type === 'goods' ? 'goods-' + item.goods_id : 'cat-' + item.category_code;
            return k !== key;
        });
        // Set 재구성
        selectedGoodsIds = new Set(selectedItems.filter(function(i) { return i.target_type === 'goods'; }).map(function(i) { return String(i.goods_id); }));
        selectedCatCodes = new Set(selectedItems.filter(function(i) { return i.target_type === 'category'; }).map(function(i) { return i.category_code; }));
        renderSelectedTable();
    });

    // ── 저장 ──
    function saveExhibition() {
        var form = document.getElementById('exhibitionForm');
        var formData = new FormData(form);

        var payload = {};
        for (var pair of formData.entries()) {
            payload[pair[0]] = pair[1];
        }

        var items = selectedItems.map(function(item, i) {
            if (item.target_type === 'category') {
                return { target_type: 'category', category_code: item.category_code, sort_order: i };
            }
            return { target_type: 'goods', goods_id: parseInt(item.goods_id), sort_order: i };
        });

        var sendData = { formData: {}, items: items };
        Object.keys(payload).forEach(function(key) {
            var m = key.match(/^formData\[(.+)\]$/);
            if (m) sendData.formData[m[1]] = payload[key];
        });

        MubloRequest.requestJson('/admin/shop/exhibitions/store', sendData, { loading: true })
            .then(function() {
                location.href = '/admin/shop/exhibitions';
            });
    }
    document.querySelectorAll('.btn-save-exhibition').forEach(function(btn) {
        btn.addEventListener('click', saveExhibition);
    });

    // ── 모달: 상품 검색 + 카테고리 선택 ──
    var searchTimer = null;
    var cachedCategories = null;

    // 모달 내부 핸들러 (정적 마크업이라 1회만 바인딩)
    document.querySelectorAll('.exh-modal-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.exh-modal-tab').forEach(function(t) { t.classList.remove('active'); });
            this.classList.add('active');
            var isGoods = this.dataset.tab === 'goods';
            document.getElementById('exhTabGoods').style.display = isGoods ? '' : 'none';
            document.getElementById('exhTabCategory').style.display = isGoods ? 'none' : '';
            if (!isGoods && !cachedCategories) loadCategories();
        });
    });

    document.getElementById('exhSearchInput').addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() { doSearch(1); }, 300);
    });

    var exhPickerModalEl = document.getElementById('exhPickerModal');
    document.getElementById('btnOpenSearch').addEventListener('click', function() {
        bootstrap.Modal.getOrCreateInstance(exhPickerModalEl).show();
    });
    exhPickerModalEl.addEventListener('shown.bs.modal', function() {
        document.getElementById('exhSearchInput').focus();
        doSearch(1);
        updateModalCount();
    });

    function updateModalCount() {
        var el = document.getElementById('exhSelectedCount');
        if (el) el.textContent = selectedItems.length + '개 선택됨';
    }

    // ── 상품 검색 ──
    function doSearch(page) {
        var input = document.getElementById('exhSearchInput');
        var keyword = input ? input.value.trim() : '';
        var url = '/admin/shop/block-items?page=' + page + (keyword ? '&keyword=' + encodeURIComponent(keyword) : '');

        var container = document.getElementById('exhSearchResults');
        container.innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm"></span></div>';

        MubloRequest.requestQuery(url).then(function(res) {
            var items = (res.data && res.data.items) || [];
            var pagination = (res.data && res.data.pagination) || {};

            if (!items.length) {
                container.innerHTML = '<div class="text-center text-muted py-4">검색 결과가 없습니다.</div>';
                document.getElementById('exhSearchPaging').innerHTML = '';
                return;
            }

            container.innerHTML = items.map(function(p) {
                var gid = p.id || p.goods_id;
                var name = p.label || p.goods_name || '';
                var thumb = p.main_image_url || p.thumb_url || '';
                var price = p.price || '';
                var isSelected = selectedGoodsIds.has(String(gid));
                var thumbHtml = thumb
                    ? '<img src="' + thumb + '">'
                    : '<div class="exh-no-img"><i class="bi bi-image text-muted" style="font-size:.7rem"></i></div>';
                return '<div class="exh-product-row' + (isSelected ? ' is-selected' : '') + '" data-goods-id="' + gid + '" data-name="' + name.replace(/"/g, '&quot;') + '" data-price="' + price + '" data-thumb="' + thumb + '">' +
                    thumbHtml +
                    '<div class="exh-info"><div class="exh-name">' + name + '</div><div class="exh-meta">#' + gid + ' / ' + price + '</div></div>' +
                    '<i class="bi bi-check-circle-fill exh-check"></i></div>';
            }).join('');

            // 페이징
            var totalPages = parseInt(pagination.totalPages) || 1;
            var currentPage = parseInt(pagination.currentPage) || 1;
            var pagingEl = document.getElementById('exhSearchPaging');
            if (totalPages > 1) {
                var btns = '';
                var start = Math.max(1, currentPage - 3);
                var end = Math.min(totalPages, currentPage + 3);
                if (start > 1) btns += '<button type="button" class="btn btn-sm btn-outline-secondary mx-1 exh-page-btn" data-page="1">1</button><span class="mx-1">...</span>';
                for (var i = start; i <= end; i++) {
                    btns += '<button type="button" class="btn btn-sm ' + (i === currentPage ? 'btn-default' : 'btn-outline-secondary') + ' mx-1 exh-page-btn" data-page="' + i + '">' + i + '</button>';
                }
                if (end < totalPages) btns += '<span class="mx-1">...</span><button type="button" class="btn btn-sm btn-outline-secondary mx-1 exh-page-btn" data-page="' + totalPages + '">' + totalPages + '</button>';
                pagingEl.innerHTML = btns;
            } else {
                pagingEl.innerHTML = '';
            }

            // 상품 클릭
            container.querySelectorAll('.exh-product-row').forEach(function(row) {
                row.addEventListener('click', function() {
                    var gid = String(this.dataset.goodsId);
                    if (selectedGoodsIds.has(gid)) {
                        selectedGoodsIds.delete(gid);
                        selectedItems = selectedItems.filter(function(it) { return !(it.target_type === 'goods' && String(it.goods_id) === gid); });
                        this.classList.remove('is-selected');
                    } else {
                        selectedGoodsIds.add(gid);
                        selectedItems.push({ target_type: 'goods', goods_id: parseInt(gid), goods_name: this.dataset.name, sell_price: this.dataset.price, thumb_url: this.dataset.thumb });
                        this.classList.add('is-selected');
                    }
                    updateModalCount();
                    renderSelectedTable();
                });
            });

            // 페이지 버튼
            pagingEl.querySelectorAll('.exh-page-btn').forEach(function(btn) {
                btn.addEventListener('click', function() { doSearch(parseInt(this.dataset.page)); });
            });
        });
    }

    // ── 카테고리 로드 ──
    function loadCategories() {
        var container = document.getElementById('exhCategoryList');
        container.innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm"></span></div>';

        MubloRequest.requestQuery('/admin/shop/block-items?include_categories=1&page=1').then(function(res) {
            cachedCategories = (res.data && res.data.categories) || [];

            if (!cachedCategories.length) {
                container.innerHTML = '<div class="text-center text-muted py-4">등록된 카테고리가 없습니다.</div>';
                return;
            }

            renderCategoryList(container);
        });
    }

    function renderCategoryList(container) {
        container.innerHTML = cachedCategories.map(function(cat) {
            var code = cat.code || '';
            var name = cat.name || '';
            var pathCode = cat.path_code || '';
            var depth = pathCode ? (pathCode.split('>').length - 1) : 0;
            var indent = depth > 0 ? '<span class="exh-cat-indent">' + '&mdash;'.repeat(depth) + '</span>' : '';
            var isSelected = selectedCatCodes.has(code);

            return '<div class="exh-cat-row' + (isSelected ? ' is-selected' : '') + '" data-code="' + code + '" data-name="' + name.replace(/"/g, '&quot;') + '">' +
                indent +
                '<i class="bi bi-folder' + (isSelected ? '-fill' : '') + ' text-muted" style="font-size:.85rem"></i>' +
                '<span style="flex:1">' + name + '</span>' +
                '<span class="text-muted" style="font-size:.75rem">' + code + '</span>' +
                '<i class="bi bi-check-circle-fill exh-check"></i></div>';
        }).join('');

        container.querySelectorAll('.exh-cat-row').forEach(function(row) {
            row.addEventListener('click', function() {
                var code = this.dataset.code;
                var name = this.dataset.name;
                if (selectedCatCodes.has(code)) {
                    selectedCatCodes.delete(code);
                    selectedItems = selectedItems.filter(function(it) { return !(it.target_type === 'category' && it.category_code === code); });
                    this.classList.remove('is-selected');
                } else {
                    selectedCatCodes.add(code);
                    selectedItems.push({ target_type: 'category', category_code: code, category_name: name });
                    this.classList.add('is-selected');
                }
                updateModalCount();
                renderSelectedTable();
            });
        });
    }
})();
</script>
