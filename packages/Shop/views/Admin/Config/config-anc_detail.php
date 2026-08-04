<?php
/**
 * 쇼핑몰 설정 - 상세정보 탭 순서
 *
 * @var array $config 쇼핑몰 설정
 * @var array $activeTemplates 활성 상품정보 템플릿 목록
 */
$allTabTypes  = ['detail', 'template', 'review', 'inquiry'];
$tabLabels    = [
    'detail'   => '상세설명',
    'template' => '상품정보',
    'review'   => '구매후기',
    'inquiry'  => '상품문의',
];
$dynamicTypes = ['review', 'inquiry'];

// 현재 저장된 순서 파싱 (미지의 타입은 제외, 누락된 타입은 뒤에 추가)
$savedOrder = array_values(array_filter(
    array_map('trim', explode(',', $config['detail_tab_order'] ?? '')),
    fn($t) => in_array($t, $allTabTypes, true)
));
foreach ($allTabTypes as $t) {
    if (!in_array($t, $savedOrder, true)) {
        $savedOrder[] = $t;
    }
}

// 활성화된 동적 탭
$enabledTabs = array_filter(array_map('trim', explode(',', $config['goods_view_tab'] ?? '')));

// 활성 템플릿: tab_id 기준 그룹핑
$activeTemplates = $activeTemplates ?? [];
$tplGroups = [];
foreach ($activeTemplates as $tpl) {
    $tabId   = !empty($tpl['tab_id']) ? $tpl['tab_id'] : ('tpl_' . $tpl['template_id']);
    $tabName = !empty($tpl['tab_name']) ? $tpl['tab_name'] : ($tpl['subject'] ?? '상품정보');
    if (!isset($tplGroups[$tabId])) {
        $tplGroups[$tabId] = ['tab_name' => $tabName, 'count' => 0];
    }
    $tplGroups[$tabId]['count']++;
}
?>
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-info-circle text-pastel-blue"></i>
        <span>상세정보 탭 순서</span>
    </div>
    <div class="card-body">
        <p class="alert alert-secondary small mb-2">
            <i class="bi bi-grip-vertical"></i> 드래그로 순서 변경 ·
            상세설명은 항상 표시되고, 상품정보는 등록된 템플릿이 있을 때, 구매후기·상품문의는 활성화 시 표시됩니다.
        </p>
        <ul class="list-group detail-tab-sortable" id="detailTabOrderList">
            <?php foreach ($savedOrder as $type):
                $isDynamic = in_array($type, $dynamicTypes, true);
                $isEnabled = $isDynamic && in_array($type, $enabledTabs, true);
            ?>
            <li class="list-group-item py-2" data-type="<?= $type ?>" draggable="true">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <span class="detail-tab-handle text-muted cursor-grab" title="드래그해서 순서 변경">
                        <i class="bi bi-grip-vertical fs-5"></i>
                    </span>
                    <?php if ($isDynamic): ?>
                    <div class="form-check mb-0">
                        <input type="checkbox" class="form-check-input tab-enable-check"
                               id="tab_en_<?= $type ?>"
                               <?= $isEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="tab_en_<?= $type ?>">
                            <?= htmlspecialchars($tabLabels[$type] ?? $type) ?>
                        </label>
                    </div>
                    <?php elseif ($type === 'detail'): ?>
                    <span class="fw-medium"><?= htmlspecialchars($tabLabels[$type] ?? $type) ?></span>
                    <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle" style="font-weight:400;font-size:0.72em;">항상 표시</span>
                    <?php elseif ($type === 'template'): ?>
                    <span class="fw-medium"><?= htmlspecialchars($tabLabels[$type] ?? $type) ?></span>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle" style="font-weight:400;font-size:0.72em;">데이터 있을 때 표시</span>
                    <a href="/admin/shop/info-templates" target="_blank" rel="noopener" class="text-secondary d-inline-flex align-items-center" title="상품정보 템플릿 관리 (새 창)">
                        <i class="bi bi-gear"></i>
                    </a>
                    <?php if (!empty($tplGroups)): ?>
                        <?php $ti = 0; foreach ($tplGroups as $grp): ?>
                        <span class="small text-secondary d-inline-flex align-items-center gap-1<?= $ti === 0 ? ' ms-2' : '' ?>">
                            <i class="bi bi-file-text"></i><?= htmlspecialchars($grp['tab_name']) ?><?php if ($grp['count'] > 1): ?> <span class="text-muted">·<?= (int) $grp['count'] ?></span><?php endif; ?>
                        </span>
                        <?php $ti++; endforeach; ?>
                    <?php else: ?>
                        <span class="small text-muted ms-2">등록된 템플릿 없음</span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="fw-medium"><?= htmlspecialchars($tabLabels[$type] ?? $type) ?></span>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle" style="font-weight:400;font-size:0.72em;">데이터 있을 때 표시</span>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <input type="hidden" name="formData[detail_tab_order]" id="detailTabOrderHidden"
               value="<?= htmlspecialchars(implode(',', $savedOrder)) ?>">
        <input type="hidden" name="formData[goods_view_tab]" id="goodsViewTabHidden"
               value="<?= htmlspecialchars($config['goods_view_tab'] ?? '') ?>">
    </div>
</div>
