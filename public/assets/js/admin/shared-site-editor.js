(function () {
    'use strict';

    const config = window.MubloSharedEditorConfig || {};
    const groups = config.contexts || {};
    const contexts = ['screen', 'menus', 'pages']
        .flatMap((key) => Array.isArray(groups[key]) ? groups[key] : [])
        .filter((item) => item && item.id && item.preview_url)
        .map((item) => ({id: String(item.id), label: String(item.label || item.id), site_url: String(item.preview_url)}));
    if (config.initialContext) {
        const index = contexts.findIndex((item) => item.id === config.initialContext);
        if (index > 0) contexts.unshift(contexts.splice(index, 1)[0]);
    }

    async function request(path) {
        const response = await fetch(path, {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.success === false) {
            throw new Error(payload.message || '편집 데이터를 불러오지 못했습니다.');
        }
        return payload.data === undefined ? payload : payload.data;
    }

    function selectedContext(id) {
        return contexts.find((item) => item.id === id) || contexts[0] || {id: 'screen:main', label: '메인화면', site_url: '/'};
    }

    function contents(row) {
        return (Array.isArray(row.columns) ? row.columns : []).flatMap((column) => {
            if (Array.isArray(column.contents) && column.contents.length) {
                return column.contents.map((content) => ({
                    type: String(content.content_type || 'unknown'),
                    label: String(content.label || content.content_type || '콘텐츠'),
                    editable: false
                }));
            }
            return [{
                type: String(column.content_type || 'unknown'),
                label: String(column.label || column.content_type || '콘텐츠'),
                editable: false
            }];
        });
    }

    const api = {
        contexts: async () => contexts,
        structure: async (contextId) => {
            const context = selectedContext(contextId);
            const data = await request('/admin/block-editor/rows?context=' + encodeURIComponent(context.id));
            return (Array.isArray(data.rows) ? data.rows : []).map((row, index) => ({
                resource_id: 'block_row:' + row.row_id,
                title: String(row.admin_title || ('행 #' + row.row_id)),
                position: String((row.target && row.target.position) || 'index'),
                location: context.id.indexOf('page:') === 0
                    ? {kind: 'page', page_id: context.id.slice(5)}
                    : {kind: 'screen_slot', position: String((row.target && row.target.position) || 'index')},
                order: Number(row.sort_order || index),
                visible: Boolean(row.is_active),
                editable: false,
                admin_path: String(row.edit_url || ('/admin/block-row/edit?id=' + row.row_id)),
                contents: contents(row)
            }));
        },
        status: async (contextId) => ({missing: [], outdated: false, site_url: selectedContext(contextId).site_url, editor_bridge: false}),
        sessions: async () => [],
        apply: async () => { throw new Error('적용할 변경이 없습니다.'); },
        restore: async () => { throw new Error('되돌릴 변경이 없습니다.'); },
        openAdvancedEditor: async (path) => { window.location.assign(path); }
    };

    const root = document.getElementById('mubloSharedEditor');
    if (!root || !window.MubloSiteEditor || typeof window.MubloSiteEditor.mount !== 'function') {
        if (root) root.textContent = '공통 편집기 파일을 불러오지 못했습니다.';
        return;
    }
    window.MubloSiteEditor.mount(root, {
        api: api,
        title: 'Mublo 블록 에디터',
        contextLabel: contexts[0] ? contexts[0].label : '메인화면',
        terms: {placeholder: 'AI 설정 후 사용할 수 있습니다.', examples: [], positionLabels: {index: '메인 화면'}},
        describeError: function (reason) { return {message: reason instanceof Error ? reason.message : '요청을 처리하지 못했습니다.'}; }
    });
}());
