/**
 * 쿠폰함 (basic 스킨) 뷰 스크립트 — 탭 전환·내 쿠폰/다운로드 목록.
 */
document.addEventListener('DOMContentLoaded', function() {
    var tabs = document.querySelectorAll('.shop-coupon__tab');
    var tabMy = document.getElementById('tabMy');
    var tabDl = document.getElementById('tabDownload');

    // 내 쿠폰함 탭(사용가능/사용완료/기간만료)별 표시 문구
    var MY_TABS = {
        available: { status: '사용가능', empty: '사용 가능한 쿠폰이 없습니다.' },
        used:      { status: '사용완료', empty: '사용한 쿠폰이 없습니다.' },
        expired:   { status: '만료',     empty: '만료된 쿠폰이 없습니다.' }
    };

    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            tabs.forEach(function(t) { t.classList.remove('shop-coupon__tab--active'); });
            this.classList.add('shop-coupon__tab--active');
            var target = this.dataset.tab;

            if (target === 'download') {
                tabMy.style.display = 'none';
                tabDl.style.display = '';
            } else {
                tabDl.style.display = 'none';
                tabMy.style.display = '';
                loadMyCoupons(target);
            }
        });
    });

    // 할인 표시 헬퍼
    function discountLabel(coupon) {
        if (coupon.discount_type === 'PERCENTAGE') {
            var label = coupon.discount_value + '%';
            if (coupon.max_discount) label += ' (최대 ' + Number(coupon.max_discount).toLocaleString() + '원)';
            return label;
        }
        return Number(coupon.discount_value).toLocaleString() + '원';
    }

    // 적용 대상 표시
    function methodLabel(coupon) {
        var m = { ORDER: '주문 할인', GOODS: '상품 할인', CATEGORY: '카테고리 할인', SHIPPING: '배송비 할인' };
        return m[coupon.coupon_method] || '할인';
    }

    // 사용가능 탭 조건 텍스트
    function conditionText(coupon) {
        var parts = [];
        if (coupon.min_order_amount > 0) parts.push(Number(coupon.min_order_amount).toLocaleString() + '원 이상 주문 시');
        if (coupon.valid_until) parts.push(coupon.valid_until.substring(0, 10) + '까지');
        return parts.join(' · ') || '제한 없음';
    }

    // 탭별 카드 하단 정보 텍스트
    function infoText(coupon, tab) {
        if (tab === 'used') {
            var used = [];
            if (coupon.used_at) used.push(coupon.used_at.substring(0, 10) + ' 사용');
            if (coupon.used_amount > 0) used.push(Number(coupon.used_amount).toLocaleString() + '원 할인');
            if (coupon.order_no) used.push('주문 ' + coupon.order_no);
            return used.join(' · ') || '사용 완료';
        }
        if (tab === 'expired') {
            return coupon.valid_until ? coupon.valid_until.substring(0, 10) + ' 만료' : '기간 만료';
        }
        return conditionText(coupon);
    }

    // 내 쿠폰 렌더 (사용가능/사용완료/기간만료 공용)
    function renderMyCoupons(coupons, tab) {
        var container = document.getElementById('myList');
        document.getElementById('myLoading').style.display = 'none';

        if (!coupons || coupons.length === 0) {
            container.innerHTML = '<div class="shop-coupon__empty">' + MY_TABS[tab].empty + '</div>';
            return;
        }

        var inactive = (tab !== 'available');
        container.innerHTML = coupons.map(function(c) {
            return '<div class="shop-coupon__card' + (inactive ? ' shop-coupon__card--inactive' : '') + '">'
                + '<div class="shop-coupon__card-left">'
                + '<div class="shop-coupon__card-name">' + (c.name || '쿠폰') + '</div>'
                + '<div class="shop-coupon__card-discount">' + discountLabel(c) + ' ' + methodLabel(c) + '</div>'
                + '<div class="shop-coupon__card-info">'
                + '<span>' + infoText(c, tab) + '</span>'
                + '</div></div>'
                + '<div class="shop-coupon__card-right">'
                + '<span class="shop-coupon__card-status">' + MY_TABS[tab].status + '</span>'
                + '</div></div>';
        }).join('');
    }

    // 다운로드 가능 쿠폰 렌더
    function renderDownloadable(coupons) {
        var container = document.getElementById('dlList');
        document.getElementById('dlLoading').style.display = 'none';

        if (!coupons || coupons.length === 0) {
            container.innerHTML = '<div class="shop-coupon__empty">다운로드 가능한 쿠폰이 없습니다.</div>';
            return;
        }

        container.innerHTML = coupons.map(function(c) {
            return '<div class="shop-coupon__card">'
                + '<div class="shop-coupon__card-left">'
                + '<div class="shop-coupon__card-name">' + (c.name || '쿠폰') + '</div>'
                + '<div class="shop-coupon__card-discount">' + discountLabel(c) + ' ' + methodLabel(c) + '</div>'
                + '<div class="shop-coupon__card-info">'
                + '<span>' + conditionText(c) + '</span>'
                + '</div></div>'
                + '<div class="shop-coupon__card-right">'
                + '<button type="button" class="shop-coupon__card-btn btn-download" data-id="' + c.coupon_group_id + '">받기</button>'
                + '</div></div>';
        }).join('');
    }

    // 데이터 로드
    function loadMyCoupons(tab) {
        tab = tab || 'available';
        var loading = document.getElementById('myLoading');
        loading.style.display = '';
        document.getElementById('myList').innerHTML = '';
        MubloRequest.requestQuery('/shop/api/coupons/my', { tab: tab }).then(function(res) {
            renderMyCoupons((res.data && res.data.coupons) || [], tab);
        });
    }

    function loadDownloadable() {
        MubloRequest.requestQuery('/shop/api/coupons/downloadable').then(function(res) {
            renderDownloadable((res.data && res.data.coupons) || []);
        });
    }

    loadMyCoupons('available');
    loadDownloadable();

    // 다운로드 버튼
    document.getElementById('dlList').addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-download');
        if (!btn) return;

        btn.disabled = true;
        btn.textContent = '처리 중...';

        MubloRequest.requestJson('/shop/api/coupons/download', {
            coupon_group_id: parseInt(btn.dataset.id)
        }).then(function() {
            btn.textContent = '완료';
            loadDownloadable();
        }).catch(function() {
            btn.disabled = false;
            btn.textContent = '받기';
        });
    });

    // 프로모션 코드 등록
    var promoInput = document.getElementById('promoCodeInput');
    var promoBtn = document.getElementById('btnRegisterPromo');

    promoBtn.addEventListener('click', function() {
        var code = promoInput.value.trim();
        if (!code) { MubloRequest.showAlert('프로모션 코드를 입력해주세요.', 'warning'); return; }

        promoBtn.disabled = true;
        promoBtn.textContent = '등록 중...';

        MubloRequest.requestJson('/shop/api/coupons/register', { code: code }).then(function(res) {
            MubloRequest.showAlert(res.message || '쿠폰이 등록되었습니다.', 'success');
            promoInput.value = '';
            // 등록 후 사용가능 탭으로 전환
            var availTab = document.querySelector('.shop-coupon__tab[data-tab="available"]');
            if (availTab) availTab.click();
        }).catch(function() {}).finally(function() {
            promoBtn.disabled = false;
            promoBtn.textContent = '등록';
        });
    });

    promoInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); promoBtn.click(); }
    });
});
