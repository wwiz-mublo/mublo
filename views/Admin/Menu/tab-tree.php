<?php
/**
 * Admin Menu - Tab 2: 메인 메뉴 트리
 *
 * Index.php에서 include되어 실행됨.
 * Index.php의 모든 PHP 변수에 접근 가능.
 *
 * @var array $tree 메뉴 트리 (계층형)
 * @var array $allActiveItems 전체 활성 메뉴 아이템 (풀 목록용)
 * @var array $providerOptions 제공자 옵션 ['plugin' => [...], 'package' => [...]]
 */

/**
 * pair_code로 묶인 트리 노드를 병합 (같은 레벨에서 연속된 pair 노드를 하나로)
 */
function mergePairedTreeNodes(array $nodes): array {
    $merged = [];
    $skipCodes = [];

    foreach ($nodes as $node) {
        $code = $node['menu_code'] ?? '';
        if (in_array($code, $skipCodes, true)) {
            continue;
        }

        $pairCode = $node['pair_code'] ?? '';
        if ($pairCode) {
            foreach ($nodes as $other) {
                $otherCode = $other['menu_code'] ?? '';
                if ($otherCode === $code) continue;
                if (($other['pair_code'] ?? '') === $pairCode) {
                    $node['_paired_label'] = $other['label'] ?? '';
                    $node['_paired_menu_code'] = $otherCode;
                    $skipCodes[] = $otherCode;
                    break;
                }
            }
        }

        if (!empty($node['children'])) {
            $node['children'] = mergePairedTreeNodes($node['children']);
        }

        $merged[] = $node;
    }

    return $merged;
}

/**
 * 트리 노드 렌더링 헬퍼 함수
 */
function renderTreeNodes(array $nodes, int $depth = 0): string {
    $nodes = mergePairedTreeNodes($nodes);
    $html = '<ul class="tree-list list-unstyled sortable-tree" data-depth="' . $depth . '">';

    foreach ($nodes as $node) {
        $url = $node['url'] ?? '';
        $minLevel = (int) ($node['min_level'] ?? 0);
        $pairCode = $node['pair_code'] ?? '';
        $pairedMenuCode = $node['_paired_menu_code'] ?? '';
        $pairedLabel = $node['_paired_label'] ?? '';

        $html .= '<li class="tree-node mb-1" data-node-id="' . $node['node_id'] . '" data-menu-code="' . htmlspecialchars($node['menu_code']) . '" data-path-code="' . htmlspecialchars($node['path_code']) . '" data-depth="' . $depth . '" data-url="' . htmlspecialchars($url) . '" data-min-level="' . $minLevel . '"';
        if ($pairCode) {
            $html .= ' data-pair-code="' . htmlspecialchars($pairCode) . '"';
        }
        if ($pairedMenuCode) {
            $html .= ' data-paired-menu-code="' . htmlspecialchars($pairedMenuCode) . '"';
        }
        $html .= '>';
        $html .= '<div class="node-content d-flex align-items-center">';

        // depth 들여쓰기
        if ($depth > 0) {
            $html .= '<span class="depth-indicator text-muted me-2">└</span>';
        }

        // 제공자 배지
        $pType = $node['provider_type'] ?? 'core';
        $pName = $node['provider_name'] ?? '';
        if ($pType === 'plugin') {
            $html .= '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle me-1" style="font-size:0.75rem">' . htmlspecialchars($pName ?: 'Plugin') . '</span>';
        } elseif ($pType === 'package') {
            $html .= '<span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle me-1" style="font-size:0.75rem">' . htmlspecialchars($pName ?: 'Package') . '</span>';
        } else {
            $html .= '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle me-1" style="font-size:0.75rem">Core</span>';
        }

        $html .= '<span class="menu-label">' . htmlspecialchars($node['label']) . '</span>';

        // pair 인디케이터
        if ($pairedLabel) {
            $html .= '<span class="pair-indicator ms-1" style="font-size:0.85rem"><span class="text-muted">↔</span> ' . htmlspecialchars($pairedLabel) . '</span>';
        }

        // URL 표시
        if (!empty($url)) {
            $html .= '<code class="menu-url text-muted small ms-2">' . htmlspecialchars($url) . '</code>';
        }

        // 접근 레벨 badge (레벨 1 이상만 표시)
        if ($minLevel > 0) {
            $html .= '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle small ms-2">Lv.' . $minLevel . '+</span>';
        }

        $html .= '<span class="flex-grow-1"></span>';
        $html .= '<button type="button" class="btn btn-xs btn-outline-danger btn-remove-node" data-node-id="' . $node['node_id'] . '" title="제거"><i class="bi bi-x"></i></button>';
        $html .= '</div>';

        // 하위 메뉴 영역 (비어있어도 드롭 가능하도록)
        $html .= '<div class="children-container">';
        if (!empty($node['children'])) {
            $html .= renderTreeNodes($node['children'], $depth + 1);
        } else {
            // 빈 하위 메뉴 드롭 영역
            $html .= '<ul class="tree-list list-unstyled sortable-tree child-drop-zone" data-depth="' . ($depth + 1) . '" style="display: none;"></ul>';
        }
        $html .= '</div>';

        $html .= '</li>';
    }

    $html .= '</ul>';
    return $html;
}
?>
<div class="row gx-2">
    <!-- 왼쪽: 메뉴 아이템 풀 -->
    <div class="col-md-4 mb-2 mb-md-0">
        <div class="card">
            <div class="card-hero">
                <i class="bi bi-list-nested text-pastel-blue"></i>
                <span>메뉴 아이템</span>
            </div>
            <div class="card-body" style="max-height: calc(100vh - 280px); overflow-y: auto;">
                <div id="item-pool">
                    <?php
                    // 풀 아이템 1개 렌더 (그룹별로 재사용). 클론 소스라 +버튼/드래그로 트리에 복제.
                    // $mypageBadge는 Index 스코프 클로저라 use로 캡처해야 함(클로저는 외부변수 자동상속 안 됨).
                    $renderPoolItem = function (array $item) use ($mypageBadge): void {
                        $pType = $item['provider_type'] ?? 'core';
                        $pName = $item['provider_name'] ?? '';
                        ?>
                        <div class="item-pool-item d-flex justify-content-between align-items-center p-2 mb-1 border rounded"
                             data-menu-code="<?= htmlspecialchars($item['menu_code']) ?>"
                             data-label="<?= htmlspecialchars($item['label']) ?>"
                             data-url="<?= htmlspecialchars($item['url'] ?? '') ?>"
                             data-min-level="<?= (int) ($item['min_level'] ?? 0) ?>"
                             data-provider-type="<?= htmlspecialchars($pType) ?>"
                             data-provider-name="<?= htmlspecialchars($pName) ?>"
                             <?php if (!empty($item['pair_code'])): ?>data-pair-code="<?= htmlspecialchars($item['pair_code']) ?>"<?php endif; ?>
                             style="cursor: grab;">
                            <span class="d-flex align-items-center gap-2" style="font-size:0.9rem">
                                <i class="bi bi-arrows-move text-secondary" style="font-size:1.1rem; min-width:1.1rem;"></i>
                                <?php if ($pType === 'plugin'): ?>
                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size:0.75rem"><?= htmlspecialchars($pName ?: 'Plugin') ?></span>
                                <?php elseif ($pType === 'package'): ?>
                                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle" style="font-size:0.75rem"><?= htmlspecialchars($pName ?: 'Package') ?></span>
                                <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle" style="font-size:0.75rem">Core</span>
                                <?php endif; ?>
                                <?= htmlspecialchars($item['label']) ?><?= $mypageBadge($item['url'] ?? '') ?>
                                <?php if (!empty($item['pair_code'])): ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" style="font-size:0.65rem"><?= htmlspecialchars($item['pair_code']) ?></span>
                                <?php endif; ?>
                            </span>
                            <button type="button" class="btn btn-xs btn-outline-primary btn-add-to-tree"
                                    data-menu-code="<?= htmlspecialchars($item['menu_code']) ?>">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                        <?php
                    };
                    ?>
                    <?php if (!empty($groupedAllItems['core'])): ?>
                    <div class="pool-group-label mb-1" data-group-label="core">
                        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle" style="font-size:0.75rem">Core</span>
                    </div>
                    <?php foreach ($groupedAllItems['core'] as $item) $renderPoolItem($item); ?>
                    <?php endif; ?>

                    <?php if (!empty($groupedAllItems['plugin'])): ?>
                    <div class="pool-group-label mb-1 mt-2" data-group-label="plugin">
                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size:0.75rem">Plugin</span>
                    </div>
                    <?php foreach ($groupedAllItems['plugin'] as $pName => $pItems): ?>
                    <div class="pool-provider-label small text-info fw-semibold ms-2 mb-1">— <?= htmlspecialchars($pName) ?></div>
                    <?php foreach ($pItems as $item) $renderPoolItem($item); ?>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($groupedAllItems['package'])): ?>
                    <div class="pool-group-label mb-1 mt-2" data-group-label="package">
                        <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle" style="font-size:0.75rem">Package</span>
                    </div>
                    <?php foreach ($groupedAllItems['package'] as $pName => $pItems): ?>
                    <div class="pool-provider-label small text-primary fw-semibold ms-2 mb-1">— <?= htmlspecialchars($pName) ?></div>
                    <?php foreach ($pItems as $item) $renderPoolItem($item); ?>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 오른쪽: 메뉴 트리 -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-hero">
                <i class="bi bi-diagram-3 text-pastel-blue"></i>
                <span>메인 네비게이션</span>
                <button type="button" class="btn btn-primary btn-sm ms-auto" id="btn-save-tree">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
            <div class="card-body" style="min-height: 400px;">
                <div id="menu-tree">
                    <?php if (empty($tree)): ?>
                    <p class="text-muted text-center py-3 empty-tree-message">
                        왼쪽에서 메뉴를 드래그하거나 + 버튼을 클릭하여 추가하세요.
                    </p>
                    <ul class="tree-list list-unstyled sortable-tree" data-depth="0" style="min-height: 100px; border: 2px dashed #ddd; border-radius: 4px;"></ul>
                    <?php else: ?>
                    <?php echo renderTreeNodes($tree); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
