/**
 * 상품 상세 (basic 스킨) 뷰 스크립트 — 옵션 핸들러·갤러리·찜/장바구니 등.
 * 서버 데이터는 뷰의 <script type="application/json" id="spv-product-data">에서 로드.
 * 공용 모듈 ShopProductOption.js가 먼저 로드돼 있어야 함.
 */
(function () {
    'use strict';

    var __d = {};
    try { __d = JSON.parse(document.getElementById('spv-product-data').textContent || '{}'); } catch (e) {}
    var goodsId = __d.goodsId;
    var basePrice = __d.basePrice;
    var optionMode = __d.optionMode || 'NONE';
    var options = __d.options || [];
    var combos = __d.combos || [];

    // 옵션 핸들러 초기화
    var optionHandler = new ShopProductOption({
        container: '#spv-option-area',
        basePrice: basePrice,
        optionMode: optionMode,
        options: options,
        combos: combos,
        onUpdate: function(state) {
            document.getElementById('spv-total-price').textContent = state.grandTotal.toLocaleString();
            document.getElementById('spv-total-qty').textContent = state.totalQuantity;
        }
    });
    optionHandler.init();

    // 전역 참조 (장바구니 모달에서 접근 가능)
    window.shopOptionHandler = optionHandler;

    // 갤러리 썸네일 + 크게보기(라이트박스)
    (function initGallery() {
        var mainImg = document.getElementById('spv-main-img');
        var thumbs = Array.prototype.slice.call(document.querySelectorAll('.shop-product-view__thumb'));

        // 원본 이미지 URL 목록 (썸네일 없으면 메인 1장)
        var images = thumbs.length
            ? thumbs.map(function(b) { return b.dataset.image; })
            : (mainImg ? [mainImg.getAttribute('src')] : []);
        if (!mainImg || !images.length) return;

        var current = thumbs.findIndex(function(b) {
            return b.classList.contains('shop-product-view__thumb--active');
        });
        if (current < 0) current = 0;
        var single = images.length < 2;

        // 메인 이미지 + 썸네일 활성 상태 동기화
        function selectImage(idx) {
            if (idx < 0 || idx >= images.length) return;
            current = idx;
            mainImg.src = images[idx];
            thumbs.forEach(function(b, i) {
                b.classList.toggle('shop-product-view__thumb--active', i === idx);
            });
        }

        thumbs.forEach(function(btn, i) {
            btn.addEventListener('click', function() { selectImage(i); });
        });

        // 라이트박스 DOM 생성
        var box = document.createElement('div');
        box.className = 'shop-lightbox';
        box.inert = true; // 닫힌 상태: 보조기기·포커스에서 제외 (aria-hidden 포커스 경고 방지)
        box.innerHTML =
            '<button type="button" class="shop-lightbox__close" aria-label="닫기">&times;</button>' +
            '<button type="button" class="shop-lightbox__nav shop-lightbox__nav--prev" aria-label="이전">&#10094;</button>' +
            '<img class="shop-lightbox__img" src="" alt="">' +
            '<button type="button" class="shop-lightbox__nav shop-lightbox__nav--next" aria-label="다음">&#10095;</button>' +
            '<div class="shop-lightbox__counter"></div>';
        document.body.appendChild(box);

        var boxImg = box.querySelector('.shop-lightbox__img');
        var counter = box.querySelector('.shop-lightbox__counter');
        if (single) {
            box.querySelectorAll('.shop-lightbox__nav').forEach(function(n) { n.style.display = 'none'; });
            counter.style.display = 'none';
        }

        function render() {
            boxImg.src = images[current];
            boxImg.alt = mainImg.alt || '';
            counter.textContent = (current + 1) + ' / ' + images.length;
        }
        function open() {
            render();
            box.inert = false;
            box.classList.add('shop-lightbox--open');
            document.body.style.overflow = 'hidden';
        }
        function close() {
            // 포커스가 박스 안에 있으면 먼저 빼낸 뒤 숨김 (inert/포커스 경고 방지)
            if (box.contains(document.activeElement)) document.activeElement.blur();
            box.classList.remove('shop-lightbox--open');
            box.inert = true;
            document.body.style.overflow = '';
        }
        function go(delta) {
            selectImage((current + delta + images.length) % images.length);
            render();
        }

        mainImg.addEventListener('click', open);
        box.querySelector('.shop-lightbox__close').addEventListener('click', close);
        box.querySelector('.shop-lightbox__nav--prev').addEventListener('click', function(e) { e.stopPropagation(); go(-1); });
        box.querySelector('.shop-lightbox__nav--next').addEventListener('click', function(e) { e.stopPropagation(); go(1); });
        box.addEventListener('click', function(e) { if (e.target === box) close(); });
        document.addEventListener('keydown', function(e) {
            if (!box.classList.contains('shop-lightbox--open')) return;
            if (e.key === 'Escape') close();
            else if (!single && e.key === 'ArrowLeft') go(-1);
            else if (!single && e.key === 'ArrowRight') go(1);
        });
    })();

    // 탭 전환
    var reviewsLoaded = false;
    var inquiryLoaded = false;

    function activateTab(tab) {
        document.querySelectorAll('.shop-product-view__tab-btn').forEach(function(b) {
            b.classList.toggle('shop-product-view__tab-btn--active', b.dataset.tab === tab);
        });
        document.querySelectorAll('.shop-product-view__tab-content').forEach(function(c) {
            c.classList.toggle('shop-product-view__tab-content--active', c.dataset.tabContent === tab);
        });
        var content = document.querySelector('[data-tab-content="' + tab + '"]');
        if (!content) return;

        if (tab === 'reviews' && !reviewsLoaded) {
            reviewsLoaded = true;
            loadReviews(1);
        }
        if (tab === 'inquiry' && !inquiryLoaded) {
            inquiryLoaded = true;
            loadInquiries(1);
        }
    }

    document.querySelectorAll('.shop-product-view__tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            activateTab(this.dataset.tab);
        });
    });

    // 초기 활성 탭이 lazy 콘텐츠(구매후기/상품문의)면 로드 트리거
    var initialActive = document.querySelector('.shop-product-view__tab-btn--active');
    if (initialActive) { activateTab(initialActive.dataset.tab); }

    // 상단 평점 요약의 "구매후기 N건" 링크 → 구매후기 탭으로 이동
    var reviewLink = document.querySelector('.shop-product-view__review-link');
    if (reviewLink) {
        reviewLink.addEventListener('click', function(e) {
            e.preventDefault();
            activateTab('reviews');
            document.getElementById('spv-tabs').scrollIntoView({ behavior: 'smooth' });
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function starHtml(rating) {
        rating = Math.round(rating) || 0;
        var s = '';
        for (var i = 1; i <= 5; i++) {
            s += '<span class="spv-star' + (i <= rating ? ' spv-star--on' : '') + '">★</span>';
        }
        return s;
    }

    function paginationHtml(p, onclickName) {
        var totalPages = p.totalPages || 1;
        var current = p.currentPage || 1;
        if (totalPages <= 1) return '';
        var span = 5;
        var start = Math.max(1, current - Math.floor(span / 2));
        var end = Math.min(totalPages, start + span - 1);
        start = Math.max(1, end - span + 1);
        var html = '<div class="spv-pager">';
        if (current > 1) {
            html += '<button type="button" onclick="' + onclickName + '(' + (current - 1) + ')">이전</button>';
        }
        for (var i = start; i <= end; i++) {
            html += '<button type="button" class="' + (i === current ? 'is-active' : '')
                + '" onclick="' + onclickName + '(' + i + ')">' + i + '</button>';
        }
        if (current < totalPages) {
            html += '<button type="button" onclick="' + onclickName + '(' + (current + 1) + ')">다음</button>';
        }
        return html + '</div>';
    }

    /* ================= 구매후기 ================= */

    function loadReviews(page) {
        var container = document.getElementById('spv-reviews');
        container.innerHTML = '<p class="shop-product-view__empty">구매후기를 불러오는 중...</p>';

        MubloRequest.requestQuery('/shop/api/reviews', { goods_id: goodsId, page: page })
            .then(function(res) {
                renderReviews(container, res.data || {});
            })
            .catch(function() {
                container.innerHTML = '<p class="shop-product-view__empty">구매후기를 불러올 수 없습니다.</p>';
            });
    }
    window.spvReviewPage = loadReviews;

    function renderReviews(container, data) {
        var items = data.items || [];
        var summary = data.summary || { average: 0, total: 0, distribution: {} };
        var pagination = data.pagination || {};
        var total = summary.total || 0;

        var html = '<div class="spv-review">';

        html += '<div class="spv-review__summary">'
            + '<div class="spv-review__avg-box">'
            + '<div class="spv-review__avg">' + Number(summary.average || 0).toFixed(1) + '</div>'
            + '<div class="spv-review__avg-stars">' + starHtml(summary.average) + '</div>'
            + '<div class="spv-review__avg-count">' + total.toLocaleString() + '개의 후기</div>'
            + '</div>'
            + '<div class="spv-review__bars">';
        for (var r = 5; r >= 1; r--) {
            var cnt = (summary.distribution && summary.distribution[r]) ? summary.distribution[r] : 0;
            var pct = total > 0 ? Math.round((cnt / total) * 100) : 0;
            html += '<div class="spv-review__bar-row">'
                + '<span class="spv-review__bar-label">' + r + '점</span>'
                + '<span class="spv-review__bar"><span class="spv-review__bar-fill" style="width:' + pct + '%"></span></span>'
                + '<span class="spv-review__bar-cnt">' + cnt + '</span>'
                + '</div>';
        }
        html += '</div></div>';

        if (items.length === 0) {
            html += '<p class="shop-product-view__empty">아직 작성된 구매후기가 없습니다.<br>상품 구매 후 마이페이지에서 후기를 남겨주세요.</p>';
        } else {
            html += '<ul class="spv-review__list">';
            items.forEach(function(it) {
                html += '<li class="spv-review__item">'
                    + '<div class="spv-review__head">'
                    + '<span class="spv-review__stars">' + starHtml(it.rating) + '</span>'
                    + (it.is_photo ? '<span class="spv-review__badge">포토</span>' : '')
                    + (it.author ? '<span class="spv-review__author">' + escapeHtml(it.author) + '</span>' : '')
                    + '<span class="spv-review__date">' + escapeHtml(it.created_at) + '</span>'
                    + '</div>'
                    + '<div class="spv-review__content">' + escapeHtml(it.content) + '</div>';
                if (it.images && it.images.length) {
                    html += '<div class="spv-review__images">';
                    it.images.forEach(function(img) {
                        html += '<a href="' + encodeURI(img) + '" target="_blank" rel="noopener">'
                            + '<img src="' + encodeURI(img) + '" alt="후기 이미지" loading="lazy"></a>';
                    });
                    html += '</div>';
                }
                if (it.admin_reply) {
                    html += '<div class="spv-review__reply">'
                        + '<div class="spv-review__reply-label">판매자 답변</div>'
                        + '<div class="spv-review__reply-text">' + escapeHtml(it.admin_reply) + '</div>'
                        + '</div>';
                }
                html += '</li>';
            });
            html += '</ul>';
            html += paginationHtml(pagination, 'spvReviewPage');
        }

        html += '</div>';
        container.innerHTML = html;
    }

    /* ================= 상품문의 ================= */

    function loadInquiries(page) {
        var container = document.getElementById('spv-inquiry');
        container.innerHTML = '<p class="shop-product-view__empty">상품문의를 불러오는 중...</p>';

        MubloRequest.requestQuery('/shop/api/inquiries', { goods_id: goodsId, page: page })
            .then(function(res) {
                renderInquiries(container, res.data || {});
            })
            .catch(function() {
                container.innerHTML = '<p class="shop-product-view__empty">상품문의를 불러올 수 없습니다.</p>';
            });
    }
    window.spvInquiryPage = loadInquiries;

    function renderInquiries(container, data) {
        var items = data.items || [];
        var pagination = data.pagination || {};
        var isLoggedIn = !!data.is_logged_in;
        var total = pagination.totalItems || 0;

        var html = '<div class="spv-qna">';
        html += '<div class="spv-qna__head">'
            + '<span class="spv-qna__title">상품문의 <strong>' + total.toLocaleString() + '</strong></span>'
            + '<button type="button" class="spv-qna__write-btn" id="spv-qna-write-btn">문의 작성</button>'
            + '</div>';

        // 작성 폼
        html += '<form class="spv-qna__form" id="spv-qna-form" style="display:none">'
            + '<div class="spv-qna__form-row">'
            + '<select name="inquiry_type" class="spv-qna__select">'
            + '<option value="PRODUCT">상품문의</option>'
            + '<option value="STOCK">재고문의</option>'
            + '<option value="DELIVERY">배송문의</option>'
            + '<option value="OTHER">기타</option>'
            + '</select>'
            + '<label class="spv-qna__secret"><input type="checkbox" name="is_secret" value="1"> 비밀글</label>'
            + '</div>'
            + '<input type="text" name="title" class="spv-qna__input" maxlength="100" placeholder="제목을 입력하세요" required>'
            + '<textarea name="content" class="spv-qna__textarea" rows="4" placeholder="문의 내용을 입력하세요" required></textarea>'
            + '<div class="spv-qna__form-actions">'
            + '<button type="button" class="spv-qna__cancel" id="spv-qna-cancel">취소</button>'
            + '<button type="submit" class="spv-qna__submit">등록하기</button>'
            + '</div>'
            + '</form>';

        if (items.length === 0) {
            html += '<p class="shop-product-view__empty">등록된 상품문의가 없습니다.</p>';
        } else {
            html += '<ul class="spv-qna__list">';
            items.forEach(function(it) {
                html += '<li class="spv-qna__item">'
                    + '<div class="spv-qna__item-head" data-qna-toggle="' + it.inquiry_id + '">'
                    + '<span class="spv-qna__type">' + escapeHtml(it.type_label) + '</span>'
                    + (it.is_replied
                        ? '<span class="spv-qna__status spv-qna__status--done">답변완료</span>'
                        : '<span class="spv-qna__status spv-qna__status--wait">답변대기</span>')
                    + (it.is_secret ? '<span class="spv-qna__lock" title="비밀글">🔒</span>' : '')
                    + '<span class="spv-qna__subject">' + escapeHtml(it.title) + '</span>'
                    + '<span class="spv-qna__author">' + escapeHtml(it.author) + '</span>'
                    + '<span class="spv-qna__date">' + escapeHtml(it.created_at) + '</span>'
                    + '</div>';
                if (it.can_view) {
                    html += '<div class="spv-qna__body" id="spv-qna-body-' + it.inquiry_id + '">'
                        + '<div class="spv-qna__q">' + escapeHtml(it.content) + '</div>';
                    if (it.reply) {
                        html += '<div class="spv-qna__a">'
                            + '<div class="spv-qna__a-label">판매자 답변</div>'
                            + '<div class="spv-qna__a-text">' + escapeHtml(it.reply) + '</div>'
                            + '</div>';
                    }
                    if (it.is_owner && !it.is_replied) {
                        html += '<div class="spv-qna__item-actions">'
                            + '<button type="button" class="spv-qna__delete" data-qna-delete="' + it.inquiry_id + '">삭제</button>'
                            + '</div>';
                    }
                    html += '</div>';
                }
                html += '</li>';
            });
            html += '</ul>';
            html += paginationHtml(pagination, 'spvInquiryPage');
        }

        html += '</div>';
        container.innerHTML = html;

        bindInquiryEvents(container, isLoggedIn);
    }

    function bindInquiryEvents(container, isLoggedIn) {
        var form = container.querySelector('#spv-qna-form');
        var writeBtn = container.querySelector('#spv-qna-write-btn');
        var cancelBtn = container.querySelector('#spv-qna-cancel');

        if (writeBtn) {
            writeBtn.addEventListener('click', function() {
                if (!isLoggedIn) {
                    MubloRequest.showConfirm('로그인이 필요합니다.', function() {
                        location.href = '/login';
                    }, { type: 'warning', confirmText: '로그인', cancelText: '닫기' });
                    return;
                }
                var open = form.style.display !== 'none';
                form.style.display = open ? 'none' : 'block';
                if (!open) form.querySelector('[name="title"]').focus();
            });
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                form.reset();
                form.style.display = 'none';
            });
        }
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var title = form.querySelector('[name="title"]').value.trim();
                var content = form.querySelector('[name="content"]').value.trim();
                if (!title || !content) {
                    MubloRequest.showAlert('제목과 내용을 모두 입력해주세요.', 'warning');
                    return;
                }
                var fd = new FormData();
                fd.append('formData[goods_id]', goodsId);
                fd.append('formData[inquiry_type]', form.querySelector('[name="inquiry_type"]').value);
                fd.append('formData[title]', title);
                fd.append('formData[content]', content);
                fd.append('formData[is_secret]', form.querySelector('[name="is_secret"]').checked ? '1' : '0');

                MubloRequest.sendRequest({
                    method: 'POST',
                    url: '/shop/inquiries/store',
                    payloadType: MubloRequest.PayloadType.FORM,
                    data: fd,
                    loading: true
                }).then(function(res) {
                    MubloRequest.showToast(res.message || '문의가 등록되었습니다.', 'success');
                    loadInquiries(1);
                }).catch(function() {});
            });
        }

        container.querySelectorAll('[data-qna-toggle]').forEach(function(head) {
            head.addEventListener('click', function() {
                var id = this.getAttribute('data-qna-toggle');
                var body = container.querySelector('#spv-qna-body-' + id);
                if (body) body.classList.toggle('is-open');
            });
        });

        container.querySelectorAll('[data-qna-delete]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var id = this.getAttribute('data-qna-delete');
                MubloRequest.showConfirm('문의를 삭제하시겠습니까?', function() {
                    MubloRequest.requestJson('/shop/inquiries/delete', { inquiry_id: Number(id) })
                        .then(function() {
                            MubloRequest.showToast('문의가 삭제되었습니다.', 'success');
                            loadInquiries(1);
                        }).catch(function() {});
                }, { type: 'warning' });
            });
        });
    }

    // 장바구니
    var cartBtn = document.getElementById('spv-btn-cart');
    if (cartBtn) {
        cartBtn.addEventListener('click', function() {
            if (optionMode !== 'NONE' && optionHandler.selectedItems.length === 0) {
                MubloRequest.showAlert('옵션을 선택해주세요.', 'info');
                return;
            }

            var data = optionHandler.getSubmitData();
            data.goods_id = goodsId;
            data.action = 'cart';

            MubloRequest.requestJson('/shop/cart/add', data).then(function(res) {
                MubloRequest.showConfirm('장바구니에 담았습니다. 장바구니로 이동하시겠습니까?', function() {
                    location.href = '/shop/cart';
                }, { cancelText: '닫기' });
            });
        });
    }

    // 바로구매
    var buyBtn = document.getElementById('spv-btn-buy');
    if (buyBtn) {
        buyBtn.addEventListener('click', function() {
            if (optionMode !== 'NONE' && optionHandler.selectedItems.length === 0) {
                MubloRequest.showAlert('옵션을 선택해주세요.', 'info');
                return;
            }

            var data = optionHandler.getSubmitData();
            data.goods_id = goodsId;
            data.action = 'direct';

            MubloRequest.requestJson('/shop/cart/add', data).then(function(res) {
                if (res.data && res.data.redirect) {
                    location.href = res.data.redirect;
                } else {
                    location.href = '/shop/checkout';
                }
            });
        });
    }

    // 찜하기
    var wishBtn = document.getElementById('spv-btn-wish');
    if (wishBtn) {
        wishBtn.addEventListener('click', function() {
            MubloRequest.requestJson('/shop/api/wishlist/toggle', { goods_id: goodsId }).then(function(res) {
                wishBtn.textContent = res.data && res.data.wishlisted ? '\u2665' : '\u2661';
                wishBtn.classList.toggle('shop-product-view__btn--wished', !!(res.data && res.data.wishlisted));
            });
        });
    }
})();
