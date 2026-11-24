<?php
/**
 * 공통 Mublo 사이트 편집기 opt-in 화면.
 * 기존 /admin/block-editor는 그대로 유지하고 ?mode=shared 에서만 연다.
 *
 * @var array $initialContexts
 * @var string $initialContext
 * @var string $returnUrl
 */
?>
<link rel="stylesheet" href="<?= asset('/assets/vendor/mublo-site-editor/site-editor.css') ?>">
<style>
html, body { margin: 0; min-height: 100%; background: #f5f6f9; }
.mublo-shared-editor-nav { display:flex; align-items:center; gap:10px; min-height:42px; padding:0 12px; border-bottom:1px solid #e2e5eb; background:#fff; font:13px/1.4 system-ui,sans-serif; }
.mublo-shared-editor-nav a { color:#334155; text-decoration:none; }
#mubloSharedEditor { padding:8px; }
</style>
<nav class="mublo-shared-editor-nav" aria-label="편집기 이동">
    <a href="<?= htmlspecialchars($returnUrl ?? '/admin/block-page?activeCode=004_002', ENT_QUOTES) ?>">← 관리자</a>
    <a href="/admin/block-editor">기존 고급 편집기</a>
</nav>
<main id="mubloSharedEditor"></main>
<script>
window.MubloSharedEditorConfig = {
    contexts: <?= json_encode($initialContexts ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>,
    initialContext: <?= json_encode($initialContext ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>
};
</script>
<script src="<?= asset('/assets/vendor/mublo-site-editor/site-editor.js') ?>"></script>
<script src="<?= asset('/assets/js/admin/shared-site-editor.js') ?>"></script>
