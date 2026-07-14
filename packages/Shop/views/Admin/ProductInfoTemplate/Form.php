<?php
/**
 * 상품정보 템플릿 등록/수정
 *
 * @var string $pageTitle
 * @var array|null $template
 * @var array $categories
 */
$isEdit = !empty($template);
$t = $template ?? [];
$categories = $categories ?? [];
$currentCategoryCode = $t['category_code'] ?? '';
?>
<?= editor_css() ?>

<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>상품 상세페이지에 표시되는 정보 템플릿을 <?= $isEdit ? '수정' : '등록' ?>합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/shop/info-templates" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 목록
            </a>
            <button type="button" class="btn btn-primary btn-sm" onclick="saveTemplate()">
                <i class="bi bi-check-lg"></i> <?= $isEdit ? '수정' : '등록' ?>
            </button>
        </div>
    </div>

    <form id="templateForm" enctype="multipart/form-data">
        <?php if ($isEdit): ?>
        <input type="hidden" name="formData[template_id]" value="<?= $t['template_id'] ?>">
        <?php endif; ?>

        <div class="page-block row">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-body">
                        <label for="subject" class="form-label fw-semibold">제목 <span class="text-danger">*</span></label>
                        <input type="text" id="subject" name="formData[subject]" class="form-control"
                               value="<?= htmlspecialchars($t['subject'] ?? '') ?>"
                               placeholder="예: 상품 스펙 정보">
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body">
                        <label class="form-label fw-semibold">본문</label>
                        <?= editor_html('template-editor', $t['content'] ?? '', [
                            'height' => 500,
                            'name' => 'formData[content]',
                        ]) ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-body">
                        <label class="form-label fw-semibold">탭명 <span class="text-danger">*</span></label>
                        <input type="text" name="formData[tab_name]" class="form-control"
                               value="<?= htmlspecialchars($t['tab_name'] ?? '') ?>"
                               placeholder="예: 상품정보">
                        <div class="form-text">탭에 표시되는 이름</div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body">
                        <label class="form-label fw-semibold">적용 카테고리</label>
                        <select name="formData[category_code]" class="form-select">
                            <option value="">전체 (공통)</option>
                            <?php foreach ($categories as $cat): ?>
                            <?php $indent = str_repeat('ㄴ ', max(0, (int)($cat['depth'] ?? 1) - 1)); ?>
                            <option value="<?= htmlspecialchars($cat['category_code']) ?>"
                                <?= $currentCategoryCode === $cat['category_code'] ? 'selected' : '' ?>>
                                <?= $indent . htmlspecialchars($cat['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">선택한 카테고리와 하위 카테고리의 상품에 적용됩니다.</div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">상태</label>
                            <select name="formData[status]" class="form-select">
                                <option value="Y" <?= ($t['status'] ?? 'Y') === 'Y' ? 'selected' : '' ?>>사용</option>
                                <option value="N" <?= ($t['status'] ?? 'Y') === 'N' ? 'selected' : '' ?>>미사용</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label fw-semibold">정렬순서</label>
                            <input type="number" name="formData[sort_order]" class="form-control"
                                   value="<?= (int)($t['sort_order'] ?? 0) ?>" min="0">
                            <div class="form-text">숫자가 작을수록 먼저 표시됩니다.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center">
            <a href="/admin/shop/info-templates" class="btn btn-secondary"><i class="bi bi-list"></i> 목록</a>
            <button type="button" class="btn btn-primary" onclick="saveTemplate()">
                <i class="bi bi-check-lg"></i> <?= $isEdit ? '수정' : '등록' ?>
            </button>
        </div>
    </form>
</div>

<?= editor_js() ?>

<script>
function saveTemplate() {
    if (typeof MubloEditor !== 'undefined') {
        MubloEditor.get('template-editor')?.sync();
    }

    var form = document.getElementById('templateForm');
    var fd = new FormData(form);

    MubloRequest.sendRequest({
        method: 'POST',
        url: '/admin/shop/info-templates/store',
        payloadType: MubloRequest.PayloadType.FORM,
        data: fd,
    }).then(function() {
        location.href = '/admin/shop/info-templates';
    });
}
</script>
