(function () {
    'use strict';

    function checked(value, fallback) {
        var resolved = value === undefined || value === null ? fallback : value;
        return resolved === true || resolved === 1 || resolved === '1' || resolved === 'true';
    }

    function selectField(id, label, options, value) {
        var html = '<div class="mb-3"><label class="form-label" for="' + id + '">' + label + '</label>';
        html += '<select class="form-select" id="' + id + '">';
        options.forEach(function (option) {
            html += '<option value="' + option[0] + '"' + (option[0] === value ? ' selected' : '') + '>' + option[1] + '</option>';
        });
        return html + '</select></div>';
    }

    function checkboxField(id, label, value, fallback) {
        return '<div class="form-check mb-2">'
            + '<input class="form-check-input" type="checkbox" id="' + id + '"' + (checked(value, fallback) ? ' checked' : '') + '>'
            + '<label class="form-check-label" for="' + id + '">' + label + '</label></div>';
    }

    function numberField(id, label, value, min, max, help) {
        return '<div class="mb-3"><label class="form-label" for="' + id + '">' + label + '</label>'
            + '<input class="form-control" type="number" id="' + id + '" value="' + value + '" min="' + min + '" max="' + max + '">'
            + (help ? '<div class="form-text">' + help + '</div>' : '') + '</div>';
    }

    function settingsHtml(type, config) {
        var prefix = 'manual_block_' + type;
        var html = '<div class="manual-block-admin border rounded p-3 mb-3"><div class="fw-semibold mb-3">매뉴얼 블록 설정</div>';

        if (type === 'manual_books') {
            html += selectField(prefix + '_layout', '배치 방식', [['grid', '카드 그리드'], ['list', '세로 목록']], config.layout || 'grid');
            html += checkboxField(prefix + '_show_description', '책 설명 표시', config.show_description, true);
            html += checkboxField(prefix + '_show_link', '이동 화살표 표시', config.show_link, true);
        } else if (type === 'manual_toc') {
            html += numberField(prefix + '_max_depth', '표시 깊이', parseInt(config.max_depth, 10) || 0, 0, 12, '0이면 모든 하위 단계를 표시합니다.');
            html += checkboxField(prefix + '_show_description', '책 설명 표시', config.show_description, true);
            html += checkboxField(prefix + '_show_root_link', '전체 매뉴얼 링크 표시', config.show_root_link, true);
        } else if (type === 'manual_page') {
            html += selectField(prefix + '_display_mode', '표시 방식', [['full', '전체 본문'], ['excerpt', '요약'], ['card', '링크 카드']], config.display_mode || 'full');
            html += numberField(prefix + '_excerpt_length', '요약 글자 수', parseInt(config.excerpt_length, 10) || 240, 40, 1000, '요약 방식에서만 사용합니다.');
            html += checkboxField(prefix + '_show_book_title', '책 제목 표시', config.show_book_title, true);
            html += checkboxField(prefix + '_show_more_link', '문서 전체 보기 링크 표시', config.show_more_link, true);
        } else if (type === 'manual_recent') {
            html += selectField(prefix + '_layout', '배치 방식', [['list', '세로 목록'], ['cards', '카드 그리드']], config.layout || 'list');
            html += checkboxField(prefix + '_show_book_title', '책 제목 표시', config.show_book_title, true);
            html += checkboxField(prefix + '_show_updated_at', '수정일 표시', config.show_updated_at, true);
            html += checkboxField(prefix + '_show_excerpt', '본문 요약 표시', config.show_excerpt, false);
            html += numberField(prefix + '_excerpt_length', '요약 글자 수', parseInt(config.excerpt_length, 10) || 160, 40, 1000, '본문 요약을 켰을 때 사용합니다.');
        }

        return html + '</div>';
    }

    function createModule(type, leftTitle, rightTitle, emptyHint) {
        var root = null;
        var dualListbox = null;
        var selectedItems = [];
        var requestId = 0;

        return {
            async init(containerEl, data) {
                requestId += 1;
                var currentRequest = requestId;
                var config = data.config || {};
                var selected = (data.selectedItems || []).map(function (item) {
                    return String(typeof item === 'object' ? item.id : item);
                });
                selectedItems = selected;

                containerEl.innerHTML = settingsHtml(type, config)
                    + '<div class="manual-block-admin__hint form-text mb-2">' + emptyHint + '</div>'
                    + '<div class="manual-block-admin__items text-center text-muted py-3">'
                    + '<span class="spinner-border spinner-border-sm"></span> 목록 로딩 중...</div>';
                root = containerEl;
                var itemsHost = root.querySelector('.manual-block-admin__items');

                try {
                    var response = await MubloRequest.requestJson('/admin/block-row/get-content-items?content_type=' + encodeURIComponent(type));
                    if (currentRequest !== requestId || !root) return;
                    var items = response.success && response.data && Array.isArray(response.data.items)
                        ? response.data.items : [];
                    itemsHost.className = 'manual-block-admin__items';
                    itemsHost.innerHTML = '';
                    if (!items.length) {
                        itemsHost.innerHTML = '<p class="text-muted mb-0">선택 가능한 활성 매뉴얼이 없습니다.</p>';
                        return;
                    }
                    dualListbox = new MubloDualListbox(itemsHost, {
                        available: items,
                        selected: selected,
                        maxItems: data.maxItems || 0,
                        leftTitle: leftTitle,
                        rightTitle: rightTitle
                    });
                    this._dualListbox = dualListbox;
                } catch (error) {
                    if (currentRequest !== requestId || !root) return;
                    itemsHost.innerHTML = '<p class="text-danger mb-0">매뉴얼 목록을 불러오지 못했습니다.</p>';
                }
            },

            getSelectedItems() {
                return dualListbox ? dualListbox.getSelected() : selectedItems;
            },

            getConfig() {
                if (!root) return {};
                var prefix = '#manual_block_' + type;
                var value = function (suffix, fallback) {
                    var element = root.querySelector(prefix + suffix);
                    return element ? element.value : fallback;
                };
                var isChecked = function (suffix, fallback) {
                    var element = root.querySelector(prefix + suffix);
                    return element ? element.checked : fallback;
                };

                if (type === 'manual_books') return {
                    layout: value('_layout', 'grid'),
                    show_description: isChecked('_show_description', true),
                    show_link: isChecked('_show_link', true)
                };
                if (type === 'manual_toc') return {
                    max_depth: parseInt(value('_max_depth', '0'), 10) || 0,
                    show_description: isChecked('_show_description', true),
                    show_root_link: isChecked('_show_root_link', true)
                };
                if (type === 'manual_page') return {
                    display_mode: value('_display_mode', 'full'),
                    excerpt_length: parseInt(value('_excerpt_length', '240'), 10) || 240,
                    show_book_title: isChecked('_show_book_title', true),
                    show_more_link: isChecked('_show_more_link', true)
                };
                return {
                    layout: value('_layout', 'list'),
                    show_book_title: isChecked('_show_book_title', true),
                    show_updated_at: isChecked('_show_updated_at', true),
                    show_excerpt: isChecked('_show_excerpt', false),
                    excerpt_length: parseInt(value('_excerpt_length', '160'), 10) || 160
                };
            },

            destroy() {
                requestId += 1;
                if (dualListbox && typeof dualListbox.destroy === 'function') dualListbox.destroy();
                dualListbox = null;
                selectedItems = [];
                this._dualListbox = null;
                root = null;
            }
        };
    }

    window.MubloBlockManualBooks = createModule('manual_books', '사용 가능한 매뉴얼', '표시할 매뉴얼', '선택하지 않으면 모든 활성 매뉴얼을 표시합니다.');
    window.MubloBlockManualToc = createModule('manual_toc', '사용 가능한 매뉴얼', '목차를 표시할 매뉴얼', '목차를 표시할 매뉴얼 한 권을 선택하세요.');
    window.MubloBlockManualPage = createModule('manual_page', '사용 가능한 페이지', '표시할 페이지', '표시할 페이지 하나를 선택하세요.');
    window.MubloBlockManualRecent = createModule('manual_recent', '사용 가능한 매뉴얼', '최근 문서 대상', '선택하지 않으면 모든 활성 매뉴얼에서 최근 문서를 찾습니다.');
})();
