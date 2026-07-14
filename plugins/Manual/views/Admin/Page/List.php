<?php
/**
 * 매뉴얼 페이지 트리 목록 (관리자)
 *
 * @var string $pageTitle
 * @var array  $book  [{book_id, title, slug, ...}]
 * @var array  $tree  중첩 트리 [{page_id, parent_id, title, slug, depth, sort_order, is_active, children: [...]}, ...]
 */
$book = $book ?? [];
$tree = $tree ?? [];
$bookId = (int) ($book['book_id'] ?? 0);

/**
 * 트리 노드 재귀 렌더링
 */
if (!function_exists('mublo_manual_render_tree')) {
    function mublo_manual_render_tree(array $nodes): void
    {
        echo '<ol class="manual-tree__list">';
        foreach ($nodes as $node) {
            $pageId = (int) $node['page_id'];
            $active = (int) $node['is_active'] === 1;
            echo '<li class="manual-tree__item" data-page-id="' . $pageId . '">';
            echo '<div class="manual-tree__row">';
            echo '<span class="manual-tree__handle" title="드래그하여 이동"><i class="bi bi-grip-vertical"></i></span>';
            echo '<span class="manual-tree__title">' . htmlspecialchars($node['title']) . '</span>';
            echo '<code class="manual-tree__slug">' . htmlspecialchars($node['slug']) . '</code>';
            if (!$active) {
                echo '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle ms-2">미사용</span>';
            }
            echo '<span class="manual-tree__actions">';
            echo '<a href="/admin/manual/page/create/' . (int) $node['book_id'] . '?parent_id=' . $pageId . '" class="btn btn-outline-secondary btn-sm" title="하위 페이지 추가"><i class="bi bi-plus-lg"></i></a> ';
            echo '<a href="/admin/manual/page/edit/' . $pageId . '" class="btn btn-outline-secondary btn-sm" title="수정"><i class="bi bi-pencil"></i></a> ';
            echo '<button type="button" class="btn btn-outline-danger btn-sm" onclick="deletePage(' . $pageId . ')" title="삭제"><i class="bi bi-trash"></i></button>';
            echo '</span>';
            echo '</div>';
            // 자식 목록 컨테이너는 항상 렌더 — 비어 있어도 드롭 대상이 되어 하위로 이동 가능
            mublo_manual_render_tree($node['children'] ?? []);
            echo '</li>';
        }
        echo '</ol>';
    }
}
?>
<link rel="stylesheet" href="<?= asset('/serve/plugin/Manual/assets/css/manual-admin.css') ?>">

<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($book['title'] ?? '') ?> · 페이지 관리</h3>
            <p>목차(페이지)를 트리로 구성합니다. 드래그로 순서와 상·하위 계층을 바꿀 수 있습니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/manual" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> 매뉴얼 목록
            </a>
            <a href="/manual/<?= htmlspecialchars($book['slug'] ?? '') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-box-arrow-up-right"></i> 열람
            </a>
            <a href="/admin/manual/page/create/<?= $bookId ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> 페이지 추가
            </a>
        </div>
    </div>

    <div class="page-block">
        <div class="card">
            <div class="card-hero">
                <i class="bi bi-list-nested text-pastel-blue"></i>
                <span>목차 트리</span>
                <button type="button" class="btn btn-primary btn-xs ms-auto" id="btnSaveTree" style="display:none;" onclick="saveTree()">
                    <i class="bi bi-check-lg"></i> 순서 저장
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($tree)): ?>
                    <div class="text-center text-muted py-5">
                        등록된 페이지가 없습니다. 우측 상단 <strong>페이지 추가</strong>로 시작하세요.
                    </div>
                <?php else: ?>
                    <div id="manualTree" class="manual-tree" data-book-id="<?= $bookId ?>">
                        <?php mublo_manual_render_tree($tree); ?>
                    </div>
                    <div class="alert alert-info d-flex align-items-center gap-2 mt-3 mb-0 small">
                        <i class="bi bi-info-circle"></i>
                        <span>드래그로 순서·계층을 바꾼 뒤 <strong>순서 저장</strong>을 눌러야 반영됩니다.</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="/serve/plugin/Manual/assets/js/manual-tree.js"></script>
<script>
function deletePage(pageId) {
    if (!confirm('이 페이지를 삭제하시겠습니까?\n하위 페이지가 있으면 함께 삭제됩니다.')) {
        return;
    }
    MubloRequest.requestJson('/admin/manual/page', { page_id: pageId }, { method: 'DELETE', loading: true })
        .then(function () { location.reload(); });
}

function saveTree() {
    var el = document.getElementById('manualTree');
    if (!el || typeof ManualTree === 'undefined') return;
    var payload = {
        book_id: parseInt(el.dataset.bookId, 10),
        nodes: ManualTree.serialize(el),
    };
    MubloRequest.requestJson('/admin/manual/page/save-tree', payload, { method: 'PUT', loading: true })
        .then(function () {
            if (typeof MubloRequest.showToast === 'function') MubloRequest.showToast('목차 순서가 저장되었습니다.', 'success');
            document.getElementById('btnSaveTree').style.display = 'none';
        });
}

document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('manualTree');
    if (el && typeof ManualTree !== 'undefined') {
        ManualTree.init(el, function () {
            document.getElementById('btnSaveTree').style.display = '';
        });
    }
});
</script>
