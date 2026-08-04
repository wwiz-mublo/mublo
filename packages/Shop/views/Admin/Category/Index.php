<?php
/**
 * 카테고리 관리 (2탭: 아이템 + 트리)
 *
 * Core 메뉴 관리와 동일한 패턴:
 * - 탭1: 카테고리 아이템 CRUD (shop_category_items)
 * - 탭2: 카테고리 트리 빌더 (shop_category_tree) — Sortable.js 드래그앤드롭
 *
 * @var string $pageTitle
 * @var string $activeTab  items|tree
 * @var array  $items      카테고리 아이템 목록
 * @var array  $tree       계층형 트리 데이터
 * @var array  $flatTree   트리 flat (아이템 정보 포함)
 * @var array  $usedCodes  트리에서 사용 중인 카테고리 코드
 * @var array  $registeredMap 메뉴 등록된 카테고리 코드 → item_id 맵
 * @var array  $levelOptions 레벨 옵션
 */
$usedCodes = $usedCodes ?? [];
$registeredMap = $registeredMap ?? [];

// 트리 노드 재귀 렌더링 (메뉴와 동일한 ul/li 구조)
function renderCategoryTreeNodes(array $nodes, int $depth = 0): string {
    $html = '<ul class="tree-list list-unstyled sortable-tree" data-depth="' . $depth . '">';

    foreach ($nodes as $node) {
        $code = htmlspecialchars($node['category_code']);
        $name = htmlspecialchars($node['name']);

        $html .= '<li class="tree-node" data-code="' . $code . '" data-name="' . $name . '" data-depth="' . $depth . '">';
        $html .= '<div class="node-content d-flex align-items-center gap-2">';

        // 깊이 표시
        if ($depth > 0) {
            $html .= '<span class="depth-indicator text-muted">└</span>';
        } else {
            $html .= '<i class="bi bi-folder text-muted"></i>';
        }

        $html .= '<span class="node-label">' . $name . '</span>';
        $html .= '<code class="node-code text-muted small">' . $code . '</code>';
        $html .= '<span class="flex-grow-1"></span>';
        $html .= '<button type="button" class="btn btn-sm btn-outline-danger btn-remove-node" title="제거"><i class="bi bi-x"></i></button>';
        $html .= '</div>';

        // 자식 컨테이너
        $html .= '<div class="children-container">';
        if (!empty($node['children'])) {
            $html .= renderCategoryTreeNodes($node['children'], $depth + 1);
        } else {
            $html .= '<ul class="tree-list list-unstyled sortable-tree child-drop-zone" data-depth="' . ($depth + 1) . '" style="display:none;"></ul>';
        }
        $html .= '</div>';

        $html .= '</li>';
    }

    $html .= '</ul>';
    return $html;
}
?>


<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '카테고리 관리') ?></h3>
            <p>상품 카테고리를 관리합니다. 메뉴 아이템으로 등록하여 상단 메뉴에 추가할 수 있습니다.</p>
        </div>
    </div>

    <!-- 탭 네비게이션 -->
    <ul class="nav nav-tabs mt-4 mb-2" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= ($activeTab ?? 'items') === 'items' ? 'active' : '' ?>"
                    id="tab-items" data-bs-toggle="tab" data-bs-target="#pane-items"
                    type="button" role="tab">
                <i class="bi bi-list-ul"></i> 카테고리 아이템
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= ($activeTab ?? 'items') === 'tree' ? 'active' : '' ?>"
                    id="tab-tree" data-bs-toggle="tab" data-bs-target="#pane-tree"
                    type="button" role="tab">
                <i class="bi bi-diagram-3"></i> 카테고리 트리
            </button>
        </li>
    </ul>

    <!-- 탭 콘텐츠 -->
    <div class="tab-content">

        <!-- ================================================ -->
        <!-- 탭1: 카테고리 아이템 -->
        <!-- ================================================ -->
        <div class="tab-pane fade <?= ($activeTab ?? 'items') === 'items' ? 'show active' : '' ?>"
             id="pane-items" role="tabpanel">

            <div class="d-flex justify-content-end mb-2">
                <button type="button" class="btn btn-primary btn-sm" id="btn-add-item">
                    <i class="bi bi-plus-lg"></i> 카테고리 추가
                </button>
            </div>

            <?php if (empty($items)): ?>
                <p class="text-muted text-center py-5 mb-0">등록된 카테고리가 없습니다.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="items-table">
                        <thead>
                            <tr>
                                <th>카테고리명</th>
                                <th class="text-center" style="width:120px">코드</th>
                                <th class="text-center" style="width:80px">상태</th>
                                <th class="text-center" style="width:120px">트리 사용</th>
                                <th class="text-center" style="width:140px">관리</th>
                                <th class="text-center" style="width:120px">메뉴 등록</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <?php
                                $code = $item['category_code'];
                                $inTree = in_array($code, $usedCodes);
                                $isRegistered = isset($registeredMap[$code]);
                            ?>
                            <tr data-code="<?= htmlspecialchars($code) ?>">
                                <td>
                                    <span class="fw-medium"><?= htmlspecialchars($item['name']) ?></span>
                                    <?php if (!empty($item['description'])): ?>
                                        <small class="text-muted d-block"><?= htmlspecialchars($item['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <code class="small"><?= htmlspecialchars($code) ?></code>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($item['is_active'])): ?>
                                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">활성</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">비활성</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($inTree): ?>
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">사용 중</span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit-item"
                                            data-id="<?= (int) $item['category_id'] ?>" title="수정">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete-item"
                                            data-id="<?= (int) $item['category_id'] ?>"
                                            <?= $inTree ? 'disabled title="트리에서 먼저 제거하세요"' : 'title="삭제"' ?>>
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                                <td class="text-center menu-action-cell">
                                    <?php if ($isRegistered): ?>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger btn-menu-unregister-single"
                                                data-code="<?= htmlspecialchars($code) ?>">
                                            <i class="bi bi-x-lg"></i> 해제
                                        </button>
                                    <?php else: ?>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary btn-menu-register-single"
                                                data-code="<?= htmlspecialchars($code) ?>">
                                            <i class="bi bi-plus-lg"></i> 등록
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ================================================ -->
        <!-- 탭2: 카테고리 트리 -->
        <!-- ================================================ -->
        <div class="tab-pane fade <?= ($activeTab ?? 'items') === 'tree' ? 'show active' : '' ?>"
             id="pane-tree" role="tabpanel">

            <div class="row g-3 mt-1">
                <!-- 왼쪽: 아이템 풀 -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center gap-2">
                            <span class="fw-semibold" style="font-size:0.9rem">
                                <i class="bi bi-folder text-pastel-blue"></i> 카테고리 아이템
                            </span>
                            <input type="text" class="form-control form-control-sm w-auto"
                                   id="pool-search" placeholder="검색...">
                        </div>
                        <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                            <div id="item-pool">
                                <?php if (empty($items)): ?>
                                    <p class="text-muted text-center py-3 small mb-0">아이템이 없습니다.<br>먼저 카테고리 아이템을 등록하세요.</p>
                                <?php else: ?>
                                    <?php foreach ($items as $item): ?>
                                        <?php if (empty($item['is_active'])) continue; ?>
                                        <?php $used = in_array($item['category_code'], $usedCodes); ?>
                                        <div class="pool-item <?= $used ? 'used' : '' ?>"
                                             data-code="<?= htmlspecialchars($item['category_code']) ?>"
                                             data-name="<?= htmlspecialchars($item['name']) ?>">
                                            <span class="pool-label"><?= htmlspecialchars($item['name']) ?></span>
                                            <span class="pool-code"><?= htmlspecialchars($item['category_code']) ?></span>
                                            <button type="button" class="btn btn-sm btn-outline-primary btn-add-to-tree"
                                                    data-code="<?= htmlspecialchars($item['category_code']) ?>">
                                                <i class="bi bi-plus"></i>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 오른쪽: 트리 빌더 -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center gap-2">
                            <span class="fw-semibold" style="font-size:0.9rem">
                                <i class="bi bi-diagram-3 text-pastel-green"></i> 카테고리 트리 구조
                            </span>
                            <button type="button" class="btn btn-primary btn-sm" id="btn-save-tree">
                                <i class="bi bi-check-lg"></i> 저장
                            </button>
                        </div>
                        <div class="card-body" style="min-height: 400px;">
                            <div id="category-tree">
                                <?php if (empty($tree)): ?>
                                    <p class="text-muted text-center py-3 tree-empty-message">
                                        왼쪽에서 카테고리를 드래그하거나 + 버튼을 클릭하여 추가하세요.
                                    </p>
                                    <ul class="tree-list list-unstyled sortable-tree" data-depth="0" style="min-height: 100px; border: 2px dashed #ddd; border-radius: 4px;"></ul>
                                <?php else: ?>
                                    <?= renderCategoryTreeNodes($tree) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /tab-content -->
</div>

<!-- 아이템 생성/수정 모달 -->
<div class="modal fade" id="itemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="itemModalTitle">카테고리 추가</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="fld_category_id" value="0">

                <div class="mb-3">
                    <label for="fld_name" class="form-label">카테고리명 <span class="text-danger">*</span></label>
                    <input type="text" id="fld_name" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label for="fld_description" class="form-label">설명</label>
                    <input type="text" id="fld_description" class="form-control">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="fld_allow_member_level" class="form-label">접근 허용 레벨</label>
                        <select id="fld_allow_member_level" class="form-select">
                            <?php foreach ($levelOptions ?? [0 => '전체'] as $value => $label): ?>
                            <option value="<?= (int) $value ?>"><?= htmlspecialchars($label) ?> (Lv.<?= (int) $value ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 d-flex flex-column justify-content-end gap-2">
                        <div class="form-check form-switch">
                            <input type="checkbox" id="fld_allow_coupon" class="form-check-input" checked>
                            <label class="form-check-label" for="fld_allow_coupon">쿠폰 사용 허용</label>
                        </div>
                        <div class="form-check form-switch">
                            <input type="checkbox" id="fld_is_adult" class="form-check-input">
                            <label class="form-check-label" for="fld_is_adult">성인인증 필요</label>
                        </div>
                    </div>
                </div>

                <div class="form-check form-switch">
                    <input type="checkbox" id="fld_is_active" class="form-check-input" checked>
                    <label class="form-check-label" for="fld_is_active">활성화</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary btn-sm" id="btn-save-item">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var itemModal = new bootstrap.Modal(document.getElementById('itemModal'));

    // ==========================================
    // 탭1: 아이템 CRUD
    // ==========================================

    document.getElementById('btn-add-item').addEventListener('click', function() {
        resetItemForm();
        document.getElementById('itemModalTitle').textContent = '카테고리 추가';
        itemModal.show();
    });

    document.addEventListener('click', function(e) {
        var editBtn = e.target.closest('.btn-edit-item');
        if (editBtn) {
            var categoryId = editBtn.dataset.id;
            MubloRequest.requestJson('/admin/shop/categories/item-view?category_id=' + categoryId, null, {method: 'GET'})
                .then(function(res) {
                    if (res.result === 'success') {
                        var item = res.data;
                        document.getElementById('fld_category_id').value = item.category_id;
                        document.getElementById('fld_name').value = item.name || '';
                        document.getElementById('fld_description').value = item.description || '';
                        document.getElementById('fld_allow_member_level').value = item.allow_member_level || 0;
                        document.getElementById('fld_allow_coupon').checked = !!parseInt(item.allow_coupon);
                        document.getElementById('fld_is_adult').checked = !!parseInt(item.is_adult);
                        document.getElementById('fld_is_active').checked = !!parseInt(item.is_active);
                        document.getElementById('itemModalTitle').textContent = '카테고리 수정';
                        itemModal.show();
                    } else {
                        alert(res.message || '조회에 실패했습니다.');
                    }
                })
                .catch(function() { alert('조회 중 오류가 발생했습니다.'); });
        }

        var deleteBtn = e.target.closest('.btn-delete-item');
        if (deleteBtn && !deleteBtn.disabled) {
            var categoryId = deleteBtn.dataset.id;
            if (confirm('이 카테고리 아이템을 삭제하시겠습니까?')) {
                MubloRequest.requestJson('/admin/shop/categories/item-delete', {
                    category_id: parseInt(categoryId)
                }).then(function(res) {
                    if (res.result === 'success') {
                        location.reload();
                    } else {
                        alert(res.message || '삭제에 실패했습니다.');
                    }
                }).catch(function() { alert('삭제 중 오류가 발생했습니다.'); });
            }
        }
    });

    document.getElementById('btn-save-item').addEventListener('click', function() {
        var name = document.getElementById('fld_name').value.trim();
        if (!name) {
            alert('카테고리명을 입력해주세요.');
            document.getElementById('fld_name').focus();
            return;
        }

        var data = {
            category_id: parseInt(document.getElementById('fld_category_id').value) || 0,
            name: name,
            description: document.getElementById('fld_description').value.trim(),
            allow_member_level: parseInt(document.getElementById('fld_allow_member_level').value) || 0,
            allow_coupon: document.getElementById('fld_allow_coupon').checked ? 1 : 0,
            is_adult: document.getElementById('fld_is_adult').checked ? 1 : 0,
            is_active: document.getElementById('fld_is_active').checked ? 1 : 0
        };

        MubloRequest.requestJson('/admin/shop/categories/item-store', data)
            .then(function(res) {
                if (res.result === 'success') {
                    itemModal.hide();
                    location.reload();
                } else {
                    alert(res.message || '저장에 실패했습니다.');
                }
            })
            .catch(function() { alert('저장 중 오류가 발생했습니다.'); });
    });

    function resetItemForm() {
        document.getElementById('fld_category_id').value = 0;
        document.getElementById('fld_name').value = '';
        document.getElementById('fld_description').value = '';
        document.getElementById('fld_allow_member_level').value = 0;
        document.getElementById('fld_allow_coupon').checked = true;
        document.getElementById('fld_is_adult').checked = false;
        document.getElementById('fld_is_active').checked = true;
    }

    // ==========================================
    // 탭2: 트리 빌더 (Sortable.js 드래그앤드롭)
    // ==========================================

    // 풀 검색
    document.getElementById('pool-search').addEventListener('input', function() {
        var keyword = this.value.toLowerCase();
        document.querySelectorAll('#item-pool .pool-item').forEach(function(el) {
            var name = (el.dataset.name || '').toLowerCase();
            var code = (el.dataset.code || '').toLowerCase();
            el.style.display = (name.indexOf(keyword) >= 0 || code.indexOf(keyword) >= 0) ? '' : 'none';
        });
    });

    // + 버튼 클릭으로 추가
    document.getElementById('item-pool').addEventListener('click', function(e) {
        var addBtn = e.target.closest('.btn-add-to-tree');
        if (!addBtn) return;
        var poolItem = addBtn.closest('.pool-item');
        if (!poolItem || poolItem.classList.contains('used')) return;

        var code = poolItem.dataset.code;
        var name = poolItem.dataset.name;

        var rootList = document.querySelector('#category-tree > .sortable-tree[data-depth="0"]');
        if (!rootList) {
            // 루트 리스트가 없으면 생성
            rootList = document.createElement('ul');
            rootList.className = 'tree-list list-unstyled sortable-tree';
            rootList.dataset.depth = '0';
            document.getElementById('category-tree').appendChild(rootList);
            initSortableTree(rootList);
        }

        var newNode = createTreeNode(code, name, 0);
        rootList.appendChild(newNode);
        poolItem.classList.add('used');
        hideEmptyTreeMessage();
    });

    // 트리 노드 제거
    document.getElementById('category-tree').addEventListener('click', function(e) {
        var removeBtn = e.target.closest('.btn-remove-node');
        if (!removeBtn) return;

        var node = removeBtn.closest('.tree-node');
        if (!node) return;

        // 자기 + 자식 모두 풀 복원
        var allNodes = node.querySelectorAll('.tree-node');
        allNodes.forEach(function(child) {
            restoreToPool(child.dataset.code);
        });
        restoreToPool(node.dataset.code);

        node.remove();
        checkTreeEmpty();
    });

    // 트리 저장
    document.getElementById('btn-save-tree').addEventListener('click', function() {
        var rootTree = document.querySelector('#category-tree > .sortable-tree[data-depth="0"]');
        var treeData = collectTreeData(rootTree);

        MubloRequest.requestJson('/admin/shop/categories/tree-update', {
            tree: treeData
        }).then(function(res) {
            if (res.result === 'success') {
                alert('카테고리 트리가 저장되었습니다.');
                location.reload();
            } else {
                alert(res.message || '저장에 실패했습니다.');
            }
        }).catch(function() { alert('트리 저장 중 오류가 발생했습니다.'); });
    });

    // ==========================================
    // Sortable.js 초기화
    // ==========================================

    function initSortableTree(ul) {
        new Sortable(ul, {
            group: 'category-tree',
            animation: 150,
            fallbackOnBody: true,
            swapThreshold: 0.5,
            invertSwap: true,
            invertedSwapThreshold: 0.5,
            draggable: '.tree-node',
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',

            onStart: function() {
                document.querySelectorAll('.child-drop-zone').forEach(function(zone) {
                    zone.style.display = 'block';
                });
            },

            onEnd: function() {
                document.querySelectorAll('.child-drop-zone').forEach(function(zone) {
                    if (zone.children.length === 0) {
                        zone.style.display = 'none';
                    }
                });
                updateDepthIndicators();
            },

            onAdd: function(evt) {
                // 풀에서 드래그로 추가된 경우
                if (evt.item.classList.contains('pool-item')) {
                    var code = evt.item.dataset.code;
                    var name = evt.item.dataset.name;
                    var depth = parseInt(evt.to.dataset.depth) || 0;

                    var newNode = createTreeNode(code, name, depth);
                    evt.item.replaceWith(newNode);
                    hideEmptyTreeMessage();

                    // 풀에서 used 표시
                    var poolItem = document.querySelector('#item-pool .pool-item[data-code="' + code + '"]');
                    if (poolItem) poolItem.classList.add('used');
                }

                if (evt.to.children.length > 0) {
                    evt.to.style.display = 'block';
                }
            }
        });
    }

    // 풀도 Sortable (clone 모드)
    var itemPool = document.getElementById('item-pool');
    if (itemPool) {
        new Sortable(itemPool, {
            group: { name: 'category-tree', pull: 'clone', put: false },
            sort: false,
            animation: 150,
            filter: '.btn-add-to-tree, .used',
            preventOnFilter: false,

            onStart: function() {
                document.querySelectorAll('.child-drop-zone').forEach(function(zone) {
                    zone.style.display = 'block';
                });
            },

            onEnd: function() {
                document.querySelectorAll('.child-drop-zone').forEach(function(zone) {
                    if (zone.children.length === 0) {
                        zone.style.display = 'none';
                    }
                });
            }
        });
    }

    // 기존 트리 노드 Sortable 초기화
    document.querySelectorAll('#category-tree .sortable-tree').forEach(function(ul) {
        initSortableTree(ul);
    });

    // ==========================================
    // 트리 헬퍼
    // ==========================================

    function createTreeNode(code, name, depth) {
        depth = depth || 0;

        var li = document.createElement('li');
        li.className = 'tree-node';
        li.dataset.code = code;
        li.dataset.name = name;
        li.dataset.depth = depth;

        var leadingIcon = depth > 0
            ? '<span class="depth-indicator text-muted">└</span>'
            : '<i class="bi bi-folder text-muted"></i>';

        li.innerHTML =
            '<div class="node-content d-flex align-items-center gap-2">' +
                leadingIcon +
                '<span class="node-label">' + escapeHtml(name) + '</span>' +
                '<code class="node-code text-muted small">' + escapeHtml(code) + '</code>' +
                '<span class="flex-grow-1"></span>' +
                '<button type="button" class="btn btn-sm btn-outline-danger btn-remove-node" title="제거"><i class="bi bi-x"></i></button>' +
            '</div>' +
            '<div class="children-container">' +
                '<ul class="tree-list list-unstyled sortable-tree child-drop-zone" data-depth="' + (depth + 1) + '" style="display:none;"></ul>' +
            '</div>';

        // 새 노드의 자식 drop zone에 Sortable 초기화
        setTimeout(function() {
            var childList = li.querySelector('.child-drop-zone');
            if (childList) initSortableTree(childList);
        }, 0);

        return li;
    }

    function updateDepthIndicators() {
        document.querySelectorAll('#category-tree .tree-node').forEach(function(node) {
            var parentList = node.closest('.tree-list');
            var depth = parseInt(parentList.dataset.depth) || 0;
            node.dataset.depth = depth;

            var nodeContent = node.querySelector('.node-content');
            var existingIndicator = nodeContent.querySelector('.depth-indicator');
            var existingFolder = nodeContent.querySelector('.bi-folder');

            if (existingIndicator) existingIndicator.remove();
            if (existingFolder) existingFolder.parentElement && existingFolder.remove();

            var leadingEl;
            if (depth > 0) {
                leadingEl = document.createElement('span');
                leadingEl.className = 'depth-indicator text-muted';
                leadingEl.textContent = '└';
            } else {
                leadingEl = document.createElement('i');
                leadingEl.className = 'bi bi-folder text-muted';
            }

            nodeContent.insertBefore(leadingEl, nodeContent.firstChild);
        });
    }

    function collectTreeData(ul) {
        var data = [];
        if (!ul) return data;

        var children = ul.children;
        for (var i = 0; i < children.length; i++) {
            var li = children[i];
            if (!li.classList.contains('tree-node')) continue;

            var node = {
                category_code: li.dataset.code,
                children: []
            };

            var childContainer = li.querySelector(':scope > .children-container');
            if (childContainer) {
                var childUl = childContainer.querySelector(':scope > ul.tree-list');
                if (childUl && childUl.children.length > 0) {
                    node.children = collectTreeData(childUl);
                }
            }

            data.push(node);
        }

        return data;
    }

    function restoreToPool(code) {
        var poolItem = document.querySelector('#item-pool .pool-item[data-code="' + code + '"]');
        if (poolItem) poolItem.classList.remove('used');
    }

    function hideEmptyTreeMessage() {
        var msg = document.querySelector('#category-tree .tree-empty-message');
        if (msg) msg.style.display = 'none';
    }

    function checkTreeEmpty() {
        var rootTree = document.querySelector('#category-tree > .sortable-tree[data-depth="0"]');
        if (!rootTree || rootTree.children.length === 0) {
            var msg = document.querySelector('#category-tree .tree-empty-message');
            if (msg) msg.style.display = '';
        }
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ==========================================
    // 메뉴 등록/해제
    // ==========================================

    var actionUnregister = '<button type="button" class="btn btn-sm btn-outline-danger btn-menu-unregister-single">'
        + '<i class="bi bi-x-lg"></i> 해제</button>';
    var actionRegister = '<button type="button" class="btn btn-sm btn-outline-primary btn-menu-register-single">'
        + '<i class="bi bi-plus-lg"></i> 등록</button>';

    function setRowRegistered(code) {
        var tr = document.querySelector('#items-table tr[data-code="' + code + '"]');
        if (!tr) return;
        var actionCell = tr.querySelector('.menu-action-cell');
        if (actionCell) {
            actionCell.innerHTML = actionUnregister;
            actionCell.querySelector('.btn-menu-unregister-single').dataset.code = code;
        }
    }

    function setRowUnregistered(code) {
        var tr = document.querySelector('#items-table tr[data-code="' + code + '"]');
        if (!tr) return;
        var actionCell = tr.querySelector('.menu-action-cell');
        if (actionCell) {
            actionCell.innerHTML = actionRegister;
            actionCell.querySelector('.btn-menu-register-single').dataset.code = code;
        }
    }

    // 개별 등록
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-menu-register-single');
        if (!btn) return;
        var code = btn.dataset.code;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        MubloRequest.requestJson('/admin/shop/categories/menu-register', {
            category_codes: [code]
        }).then(function(res) {
            if (res && res.result === 'success') {
                setRowRegistered(code);
            } else {
                alert((res && res.message) || '메뉴 등록에 실패했습니다.');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-plus-lg"></i> 등록';
            }
        }).catch(function() {
            alert('메뉴 등록 중 오류가 발생했습니다.');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-plus-lg"></i> 등록';
        });
    });

    // 개별 해제
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-menu-unregister-single');
        if (!btn) return;
        var code = btn.dataset.code;
        if (!confirm('이 카테고리의 메뉴 등록을 해제하시겠습니까?')) return;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        MubloRequest.requestJson('/admin/shop/categories/menu-unregister', {
            category_codes: [code]
        }).then(function(res) {
            if (res && res.result === 'success') {
                setRowUnregistered(code);
            } else {
                alert((res && res.message) || '메뉴 해제에 실패했습니다.');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-x-lg"></i> 해제';
            }
        }).catch(function() {
            alert('메뉴 해제 중 오류가 발생했습니다.');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-x-lg"></i> 해제';
        });
    });
});
</script>
