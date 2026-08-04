/**
 * Pluggable editor adapters for block content types.
 *
 * BlockRowForm only dispatches lifecycle hooks. Core and extensions can register
 * a new adapter without adding content-type or adapter-name branches to the form.
 */
(function (global) {
    'use strict';

    const adapters = new Map();

    const field = id => document.getElementById(id);
    const show = id => { const element = field(id); if (element) element.style.display = 'block'; };

    const registry = {
        register(name, adapter) {
            if (!name || !adapter || typeof adapter !== 'object') {
                throw new TypeError('Block editor adapter requires a name and implementation.');
            }
            if (adapters.has(name)) {
                throw new Error(`Block editor adapter is already registered: ${name}`);
            }
            adapters.set(name, Object.freeze({ ...adapter }));
        },
        get(name) {
            return adapters.get(name) || null;
        },
    };

    registry.register('html', {
        activate(form) {
            show('html_editor_wrapper');
            form.initBlockHtmlLandingEditor();
        },
        loadConfig(form, config) {
            const css = field('modal_html_css');
            const js = field('modal_html_js');
            if (css) css.value = config.css || '';
            if (js) js.value = config.js || '';
            form.injectBlockHtmlEditorCss();

            setTimeout(() => {
                const editor = form.blockHtmlVisualEditor
                    || (typeof BlockHtmlEditor !== 'undefined' ? BlockHtmlEditor.getVisual?.('modal_html_content') : null);
                if (editor) editor.setHTML(config.html || '');
                else if (field('modal_html_content')) field('modal_html_content').value = config.html || '';
            }, 100);

            ['css', 'js'].forEach(name => {
                if (!config[name]) return;
                const collapse = field(`html_${name}_collapse`);
                if (collapse && !collapse.classList.contains('show') && typeof bootstrap !== 'undefined') {
                    new bootstrap.Collapse(collapse, { toggle: true });
                }
            });
        },
        collectConfig(form, config) {
            const editor = form.blockHtmlVisualEditor
                || (typeof BlockHtmlEditor !== 'undefined' ? BlockHtmlEditor.getVisual?.('modal_html_content') : null);
            if (editor) {
                editor.sync?.();
                config.html = editor.getHTML();
            } else if (field('modal_html_content')) {
                config.html = field('modal_html_content').value;
            }
            const css = field('modal_html_css')?.value.trim() || '';
            const js = field('modal_html_js')?.value.trim() || '';
            if (css) config.css = css;
            if (js) config.js = js;
        },
    });

    registry.register('include', {
        activate() { show('include_path_wrapper'); },
        loadConfig(form, config) {
            let path = config.include_path || config.file || '';
            if (path.includes('/')) path = path.split('/').pop();
            if (path.includes('\\')) path = path.split('\\').pop();
            if (field('modal_include_path')) field('modal_include_path').value = path;
        },
        collectConfig(form, config) {
            config.include_path = field('modal_include_path')?.value || '';
        },
    });

    registry.register('image', {
        ownsItems: true,
        activate(form) {
            show('image_config_wrapper');
            form.initImageItems();
            form.toggleSlideOptions();
        },
        loadItems(form, items, config) {
            const images = Array.isArray(items) && items.length > 0 && items[0]?.pc_image !== undefined
                ? items
                : (config.images || []);
            form.initImageItems(images);
        },
        collectItems(form, columnIndex) {
            const items = form.getImageItems();
            form.attachPendingFilesToForm(columnIndex);
            return items;
        },
    });

    registry.register('movie', {
        activate(form) {
            show('movie_config_wrapper');
            form.initVideoTypeChange();
        },
        loadConfig(form, config) {
            let videoType = config.video_type || config.type || 'youtube';
            if (videoType === 'video') videoType = 'url';
            if (field('modal_video_type')) field('modal_video_type').value = videoType;
            if (field('modal_video_url')) field('modal_video_url').value = config.video_url || config.video_id || '';
            if (field('modal_video_autoplay')) field('modal_video_autoplay').checked = config.autoplay || false;
            if (field('modal_video_muted')) field('modal_video_muted').checked = config.muted !== false;
            form.updateVideoPreview();
        },
        collectConfig(form, config) {
            const videoType = field('modal_video_type')?.value || 'youtube';
            const videoUrl = field('modal_video_url')?.value || '';
            let videoId = videoUrl;
            if (videoType === 'youtube') videoId = form.extractYouTubeId(videoUrl) || videoUrl;
            if (videoType === 'vimeo') videoId = form.extractVimeoId(videoUrl) || videoUrl;
            Object.assign(config, {
                video_type: videoType,
                type: videoType === 'url' ? 'video' : videoType,
                video_url: videoUrl,
                video_id: videoId,
                autoplay: field('modal_video_autoplay')?.checked || false,
                muted: field('modal_video_muted')?.checked !== false,
            });
        },
    });

    registry.register('outlogin', {
        activate() { show('outlogin_config_wrapper'); },
        loadConfig(form, config) {
            if (field('modal_outlogin_show_pc')) field('modal_outlogin_show_pc').checked = config.show_pc !== false;
            if (field('modal_outlogin_show_mobile')) field('modal_outlogin_show_mobile').checked = config.show_mobile !== false;
        },
        collectConfig(form, config) {
            config.show_pc = field('modal_outlogin_show_pc')?.checked !== false;
            config.show_mobile = field('modal_outlogin_show_mobile')?.checked !== false;
        },
    });

    registry.register('menu', {
        activate() { show('menu_config_wrapper'); },
        loadConfig(form, config) {
            if (field('modal_menu_scope')) field('modal_menu_scope').value = config.scope === 'current' ? 'current' : 'all';
            if (field('modal_menu_orientation')) field('modal_menu_orientation').value = config.orientation === 'vertical' ? 'vertical' : 'horizontal';
            if (field('modal_menu_depth')) field('modal_menu_depth').value = String(parseInt(config.depth, 10) || 2);
        },
        collectConfig(form, config) {
            config.scope = field('modal_menu_scope')?.value === 'current' ? 'current' : 'all';
            config.orientation = field('modal_menu_orientation')?.value === 'vertical' ? 'vertical' : 'horizontal';
            config.depth = parseInt(field('modal_menu_depth')?.value, 10) || 2;
        },
    });

    global.MubloBlockEditorAdapters = Object.freeze(registry);
})(window);
