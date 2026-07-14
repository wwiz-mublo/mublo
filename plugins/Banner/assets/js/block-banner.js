/**
 * Banner Block Item Selector (레퍼런스 구현)
 *
 * Core의 MubloDualListbox 컴포넌트를 재사용하는 가장 심플한 패턴.
 * 새 Plugin/Package 개발 시 이 파일을 복사하여 시작할 수 있습니다.
 *
 * 계약:
 *   window.MubloBlock{Type} = {
 *       init(containerEl, data),      // 초기화
 *       getSelectedItems(),           // 선택된 아이템 반환
 *       destroy()                     // 정리
 *   }
 *
 * 의존성:
 *   - window.MubloDualListbox  (Core: blockrow-form.js에서 제공)
 *   - window.MubloRequest      (Core: 전역 AJAX 유틸리티)
 *
 * 엔드포인트:
 *   - /admin/banner/block-items  (Banner 자체 라우트 — Core 비의존)
 */
(function() {
    'use strict';

    /** @type {DualListbox|null} */
    let dualListbox = null;

    /** @type {Array<Object>} AJAX로 받은 배너 전체 데이터 */
    let bannerData = [];

    window.MubloBlockBanner = {
        /** 미리보기 즉시 갱신 여부 */
        livePreview: true,

        /**
         * 초기화 — Core가 Plugin JS 로드 후 호출
         *
         * @param {HTMLElement} containerEl  렌더링할 DOM 컨테이너
         * @param {Object} data
         * @param {Array} data.selectedItems   기존 선택된 배너 (ID 배열 또는 객체 배열)
         * @param {number} data.domainId       현재 도메인 ID
         * @param {Object} data.config         content_config (미사용, 향후 확장용)
         */
        async init(containerEl, data) {
            const selectedItems = data.selectedItems || [];
            const domainId = data.domainId || 1;
            const onChanged = data.onChanged || null;

            // 기존 선택값에서 ID 추출 (객체 배열이면 id 필드, 문자열이면 그대로)
            const selectedIds = selectedItems.map(item =>
                typeof item === 'object' ? String(item.id) : String(item)
            );

            // 로딩 표시
            containerEl.innerHTML =
                '<div class="text-center text-muted py-3">' +
                '<div class="spinner-border spinner-border-sm"></div> 배너 목록 로딩 중...</div>';

            try {
                // Banner 자체 엔드포인트로 배너 목록 조회
                const response = await MubloRequest.requestJson(
                    '/admin/banner/block-items?domain_id=' + domainId
                );

                if (response.success && response.data?.items) {
                    // 배너 전체 데이터 보관
                    bannerData = response.data.items;

                    containerEl.innerHTML = '';

                    // Core의 DualListbox 컴포넌트 사용
                    dualListbox = new MubloDualListbox(containerEl, {
                        available: bannerData,
                        selected: selectedIds,
                        leftTitle: '사용 가능한 배너',
                        rightTitle: '선택된 배너',
                        onChanged: onChanged,
                    });
                } else {
                    containerEl.innerHTML =
                        '<p class="text-muted">선택 가능한 배너가 없습니다.</p>';
                }
            } catch (error) {
                console.error('배너 목록 로드 실패:', error);
                containerEl.innerHTML =
                    '<p class="text-danger">배너 목록을 불러오는데 실패했습니다.</p>';
            }
        },

        /**
         * 선택된 아이템 반환 — Core가 saveColumnSettings() 시 호출
         *
         * 새 저장값은 배너 스냅샷이 아닌 ID 배열만 canonical form으로 반환합니다.
         *
         * @returns {Array<string>} 선택된 배너 ID 배열
         */
        getSelectedItems() {
            if (!dualListbox) return [];

            return dualListbox.getSelected();
        },

        /**
         * 정리 — Core가 다른 콘텐츠 타입으로 전환 시 호출
         */
        destroy() {
            dualListbox = null;
            bannerData = [];
        }
    };
})();
