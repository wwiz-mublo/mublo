<?php
/**
 * Admin Extensions - Index
 *
 * 확장 기능 관리 페이지 (플러그인/패키지)
 *
 * @var string $pageTitle 페이지 제목
 * @var array $plugins 플러그인 목록 (manifest + enabled)
 * @var array $packages 패키지 목록 (manifest + enabled)
 */

// 패키지 종속 플러그인(parent 有)은 독립 카드가 아니라 부모 패키지 카드 안에
// "부가 기능" 토글로 표시한다 — 종속이라는 개념과 화면이 일치하도록.
// 표시되지 않는 항목(hidden, 비슈퍼의 super_only)은 여기서 걸러
// 카운트 배지·전체 선택도 실제 카드 수와 일치시킨다.
$packages = array_filter($packages, function (array $manifest) use ($isSuper) {
    if (!empty($manifest['hidden'])) {
        return false;
    }
    return empty($manifest['super_only']) || !empty($isSuper);
});

$standalonePlugins = [];
$nestedByPackage = [];
foreach ($plugins as $pname => $pmanifest) {
    if (!empty($pmanifest['hidden'])) {
        continue;
    }
    $parent = $pmanifest['parent'] ?? '';
    if ($parent !== '') {
        $nestedByPackage[$parent][$pname] = $pmanifest;
    } else {
        if (!empty($pmanifest['super_only']) && empty($isSuper)) {
            continue;
        }
        $standalonePlugins[$pname] = $pmanifest;
    }
}
?>
<form name="frm" id="frm">
<div class="page-container form-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '확장 기능') ?></h3>
            <p>사용할 플러그인과 패키지를 선택하고, 드래그하여 관리자 메뉴 순서를 변경할 수 있습니다.</p>
        </div>
        <div class="page-title-actions">
            <button type="button"
                class="btn btn-sm btn-primary mublo-submit"
                data-target="/admin/extensions/update"
                data-callback="extensionsSaved">
                <i class="bi bi-check-lg"></i> 저장
            </button>
            <?php if ($isSuper ?? false): ?>
            <button type="button"
                class="btn btn-sm btn-outline-primary"
                data-bs-toggle="modal"
                data-bs-target="#extension_upload_modal">
                <i class="bi bi-upload"></i> 확장 업로드
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- 2열 레이아웃 -->
    <div class="page-block row">
        <!-- 플러그인 (좌측) -->
        <div class="col-12 col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center">
                    <span><i class="bi bi-puzzle"></i> 플러그인</span>
                    <span class="badge bg-secondary ms-2"><?= count($standalonePlugins) ?></span>
                    <?php if (!empty($standalonePlugins)): ?>
                    <div class="form-check ms-auto mb-0">
                        <input type="checkbox" class="form-check-input ext-check-all" id="check_all_plugins" data-container="sortable-plugins">
                        <label class="form-check-label small" for="check_all_plugins">전체 선택</label>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($standalonePlugins)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-puzzle fs-1 d-block mb-3"></i>
                        <p class="mb-0">설치된 플러그인이 없습니다.</p>
                        <small>plugins/ 디렉토리에 플러그인을 추가하세요.</small>
                    </div>
                    <?php else: ?>
                    <div class="row g-3" id="sortable-plugins">
                        <?php foreach ($standalonePlugins as $name => $manifest): ?>
                        <div class="col-12 col-md-4" data-name="<?= htmlspecialchars($name) ?>">
                            <div class="card h-100 ext-card <?= ($manifest['enabled'] ?? false) ? 'border-primary' : '' ?>" style="cursor: grab;">
                                <div class="card-body py-3">
                                    <div class="form-check">
                                        <?php $locked = ($manifest['mandatory'] ?? false) && ($manifest['enabled'] ?? false); ?>
                                        <input type="checkbox"
                                               class="form-check-input ext-check"
                                               name="formData[plugins][]"
                                               value="<?= htmlspecialchars($name) ?>"
                                               id="plugin_<?= htmlspecialchars($name) ?>"
                                               <?= ($manifest['enabled'] ?? false) ? 'checked' : '' ?>
                                               <?= $locked ? 'disabled' : '' ?>>
                                        <label class="form-check-label fw-bold" for="plugin_<?= htmlspecialchars($name) ?>">
                                            <i class="<?= htmlspecialchars($manifest['icon'] ?? 'bi-grid') ?> me-1"></i>
                                            <?= htmlspecialchars($manifest['label'] ?? $name) ?>
                                            <?php if ($locked): ?><span class="badge bg-secondary ms-1" title="끌 수 없는 필수 항목">필수</span><?php endif; ?>
                                        </label>
                                    </div>
                                    <p class="text-muted small mb-2 ps-4" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                        <?= htmlspecialchars($manifest['description'] ?? '') ?>
                                    </p>
                                    <div class="ps-4">
                                        <small class="text-muted">
                                            <i class="bi bi-tag"></i> v<?= htmlspecialchars($manifest['version'] ?? '1.0.0') ?>
                                        </small>
                                        <?php if (!empty($manifest['author'])): ?>
                                        <small class="text-muted ms-2">
                                            <i class="bi bi-person"></i> <?= htmlspecialchars($manifest['author']) ?>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 패키지 (우측) -->
        <div class="col-12 col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center">
                    <span><i class="bi bi-box"></i> 패키지</span>
                    <span class="badge bg-secondary ms-2"><?= count($packages) ?></span>
                    <?php if (!empty($packages)): ?>
                    <div class="form-check ms-auto mb-0">
                        <input type="checkbox" class="form-check-input ext-check-all" id="check_all_packages" data-container="sortable-packages">
                        <label class="form-check-label small" for="check_all_packages">전체 선택</label>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($packages)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-box fs-1 d-block mb-3"></i>
                        <p class="mb-0">설치된 패키지가 없습니다.</p>
                        <small>packages/ 디렉토리에 패키지를 추가하세요.</small>
                    </div>
                    <?php else: ?>
                    <div class="row g-3" id="sortable-packages">
                        <?php foreach ($packages as $name => $manifest): ?>
                        <div class="col-12 col-md-6" data-name="<?= htmlspecialchars($name) ?>">
                            <div class="card h-100 ext-card <?= ($manifest['enabled'] ?? false) ? 'border-primary' : '' ?>" style="cursor: grab;">
                                <div class="card-body py-3">
                                    <div class="form-check">
                                        <?php $locked = ($manifest['mandatory'] ?? false) && ($manifest['enabled'] ?? false); ?>
                                        <input type="checkbox"
                                               class="form-check-input ext-check"
                                               name="formData[packages][]"
                                               value="<?= htmlspecialchars($name) ?>"
                                               id="package_<?= htmlspecialchars($name) ?>"
                                               <?= ($manifest['enabled'] ?? false) ? 'checked' : '' ?>
                                               <?= $locked ? 'disabled' : '' ?>>
                                        <label class="form-check-label fw-bold" for="package_<?= htmlspecialchars($name) ?>">
                                            <i class="<?= htmlspecialchars($manifest['icon'] ?? 'bi-grid') ?> me-1"></i>
                                            <?= htmlspecialchars($manifest['label'] ?? $name) ?>
                                            <?php if ($locked): ?><span class="badge bg-secondary ms-1" title="끌 수 없는 필수 항목">필수</span><?php endif; ?>
                                        </label>
                                    </div>
                                    <p class="text-muted small mb-2 ps-4" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                        <?= htmlspecialchars($manifest['description'] ?? '') ?>
                                    </p>
                                    <div class="ps-4">
                                        <small class="text-muted">
                                            <i class="bi bi-tag"></i> v<?= htmlspecialchars($manifest['version'] ?? '1.0.0') ?>
                                        </small>
                                        <?php if (!empty($manifest['author'])): ?>
                                        <small class="text-muted ms-2">
                                            <i class="bi bi-person"></i> <?= htmlspecialchars($manifest['author']) ?>
                                        </small>
                                        <?php endif; ?>
                                    </div>

                                    <?php // 패키지 종속 플러그인 — 카드 요약 + 전체 설정 모달 ?>
                                    <?php if (!empty($nestedByPackage[$name])):
                                        $nestedPlugins = $nestedByPackage[$name];
                                        $nestedTotal = count($nestedPlugins);
                                        $nestedEnabled = count(array_filter(
                                            $nestedPlugins,
                                            static fn(array $plugin): bool => !empty($plugin['enabled'])
                                        ));
                                        $summaryName = array_key_first(array_filter(
                                            $nestedPlugins,
                                            static fn(array $plugin): bool => !empty($plugin['enabled'])
                                        )) ?? array_key_first($nestedPlugins);
                                        $summaryPlugin = $nestedPlugins[$summaryName];
                                        $modalId = 'nested_plugins_' . substr(sha1($name), 0, 10);
                                    ?>
                                    <div class="ps-4 mt-2 pt-2 border-top nested-summary"
                                         data-parent="<?= htmlspecialchars($name) ?>">
                                        <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                            <small class="text-muted"><i class="bi bi-puzzle"></i> 부가 기능</small>
                                            <span class="badge bg-secondary nested-count">
                                                <?= $nestedEnabled ?>/<?= $nestedTotal ?> 사용
                                            </span>
                                        </div>
                                        <div class="row g-1 align-items-stretch nested-summary-actions">
                                            <div class="col-6 d-flex">
                                                <label class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle d-flex align-items-center justify-content-center gap-1 mb-0 w-100 fw-medium cursor-pointer nested-summary-label"
                                                       style="min-width: 0; height: 28px;"
                                                       title="<?= htmlspecialchars($summaryPlugin['description'] ?? '') ?>">
                                                    <input type="checkbox"
                                                           class="form-check-input m-0 flex-shrink-0 nested-summary-check"
                                                           style="width: 13px; height: 13px;"
                                                           data-parent="<?= htmlspecialchars($name) ?>"
                                                           data-plugin="<?= htmlspecialchars($summaryName) ?>"
                                                           <?= ($summaryPlugin['enabled'] ?? false) ? 'checked' : '' ?>>
                                                    <i class="<?= htmlspecialchars($summaryPlugin['icon'] ?? 'bi-puzzle') ?> flex-shrink-0 nested-summary-icon"></i>
                                                    <span class="text-truncate nested-summary-name"><?= htmlspecialchars($summaryPlugin['label'] ?? $summaryName) ?></span>
                                                </label>
                                            </div>
                                            <div class="col-6 d-flex">
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-secondary d-flex align-items-center justify-content-center gap-1 w-100 nested-view-all"
                                                        style="height: 28px;"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#<?= $modalId ?>">
                                                    <i class="bi bi-list-check"></i> 전체보기
                                                </button>
                                            </div>
                                        </div>
                                        <?php if ($nestedTotal > 1): ?>
                                        <small class="text-muted d-block mt-1 nested-remaining">외 <?= $nestedTotal - 1 ?>개</small>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php // 실제 제출 체크박스는 카드와 중복되지 않도록 모달에만 둔다. ?>
    <?php foreach ($packages as $name => $manifest):
        if (empty($nestedByPackage[$name])) continue;
        $modalId = 'nested_plugins_' . substr(sha1($name), 0, 10);
    ?>
    <div class="modal fade nested-plugin-modal"
         id="<?= $modalId ?>"
         tabindex="-1"
         aria-labelledby="<?= $modalId ?>_label"
         aria-hidden="true"
         data-parent="<?= htmlspecialchars($name) ?>">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="<?= $modalId ?>_label">
                            <?= htmlspecialchars($manifest['label'] ?? $name) ?> 부가 기능
                        </h5>
                        <small class="text-muted">사용할 부가 기능을 선택한 뒤 확인을 눌러주세요.</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="닫기"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-secondary py-2 nested-parent-warning d-none" role="alert">
                        <i class="bi bi-info-circle"></i> 먼저 부모 패키지를 활성화해야 합니다.
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($nestedByPackage[$name] as $pname => $pm):
                            $locked = ($pm['mandatory'] ?? false) && ($pm['enabled'] ?? false);
                            $checkId = 'nested_' . substr(sha1($pname), 0, 12);
                        ?>
                        <label class="list-group-item list-group-item-action py-3 nested-plugin-item"
                               for="<?= $checkId ?>">
                            <div class="d-flex align-items-start gap-3">
                                <input type="checkbox"
                                       class="form-check-input mt-1 nested-check"
                                       name="formData[plugins][]"
                                       value="<?= htmlspecialchars($pname) ?>"
                                       id="<?= $checkId ?>"
                                       data-parent="<?= htmlspecialchars($name) ?>"
                                       data-label="<?= htmlspecialchars($pm['label'] ?? $pname) ?>"
                                       data-icon="<?= htmlspecialchars($pm['icon'] ?? 'bi-puzzle') ?>"
                                       data-description="<?= htmlspecialchars($pm['description'] ?? '') ?>"
                                       data-locked="<?= $locked ? '1' : '0' ?>"
                                       <?= ($pm['enabled'] ?? false) ? 'checked' : '' ?>
                                       <?= $locked ? 'disabled' : '' ?>>
                                <i class="<?= htmlspecialchars($pm['icon'] ?? 'bi-puzzle') ?> fs-5 text-secondary"></i>
                                <span class="flex-grow-1">
                                    <span class="fw-semibold">
                                        <?= htmlspecialchars($pm['label'] ?? $pname) ?>
                                        <?php if ($locked): ?><span class="badge bg-secondary ms-1">필수</span><?php endif; ?>
                                    </span>
                                    <span class="text-muted small d-block mt-1">
                                        <?= htmlspecialchars($pm['description'] ?? '') ?>
                                    </span>
                                    <span class="text-muted small d-block mt-1">
                                        <i class="bi bi-tag"></i> v<?= htmlspecialchars($pm['version'] ?? '1.0.0') ?>
                                    </span>
                                </span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                    <button type="button"
                            class="btn btn-primary nested-modal-confirm"
                            data-bs-dismiss="modal">
                        <i class="bi bi-check-lg"></i> 확인
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
</form>

<?php if ($isSuper ?? false): ?>
<!-- 확장 zip 업로드 모달 — 파일 input 포함 폼이라 메인 폼(#frm) 밖에 둔다 -->
<div class="modal fade" id="extension_upload_modal" tabindex="-1" aria-labelledby="extension_upload_label" aria-hidden="true">
    <div class="modal-dialog">
        <form id="extension_upload_form" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="extension_upload_label">
                    <i class="bi bi-upload"></i> 확장 업로드
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="닫기"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="extension_type">확장 종류</label>
                    <select class="form-select" name="extension_type" id="extension_type">
                        <option value="plugin">플러그인 (plugins/)</option>
                        <option value="package">패키지 (packages/)</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="extension_zip">zip 파일</label>
                    <input type="file" class="form-control" name="extension_zip" id="extension_zip" accept=".zip" required>
                </div>
                <div class="alert alert-light border small mb-0">
                    <div class="mb-1"><i class="bi bi-info-circle"></i> zip 루트에 확장 폴더 하나가 들어 있어야 합니다. (예: <code>Banner/manifest.json</code>)</div>
                    <div class="text-muted mb-1">업로드가 끝나면 목록에 나타나며, 체크하고 저장해야 활성화됩니다. 이미 설치된 확장은 덮어쓸 수 없습니다.</div>
                    <div class="text-warning"><i class="bi bi-shield-exclamation"></i> 서명되지 않은 ZIP은 출처를 확인한 경우에만 설치하세요. 확장은 서버에서 PHP 코드를 실행합니다.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button"
                        class="btn btn-primary mublo-submit"
                        data-target="/admin/extensions/upload"
                        data-callback="extensionUploaded">
                    <i class="bi bi-upload"></i> 업로드
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// 체크박스 토글 시 border 업데이트
document.querySelectorAll('.ext-check').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var card = this.closest('.ext-card');
        if (this.checked) {
            card.classList.add('border-primary');
        } else {
            card.classList.remove('border-primary');
        }
    });
});

function nestedChecksForParent(parentName) {
    return Array.from(document.querySelectorAll('.nested-check[data-parent]')).filter(function(cb) {
        return cb.dataset.parent === parentName;
    });
}

function updateNestedSummary(parentName) {
    var summary = Array.from(document.querySelectorAll('.nested-summary[data-parent]')).find(function(el) {
        return el.dataset.parent === parentName;
    });
    if (!summary) return;

    var checks = nestedChecksForParent(parentName);
    if (!checks.length) return;

    var enabled = checks.filter(function(cb) { return cb.checked; });
    var representative = enabled[0] || checks[0];
    var proxy = summary.querySelector('.nested-summary-check');
    var label = summary.querySelector('.nested-summary-label');
    var icon = summary.querySelector('.nested-summary-icon');

    proxy.dataset.plugin = representative.value;
    proxy.checked = representative.checked;
    summary.querySelector('.nested-summary-name').textContent = representative.dataset.label;
    summary.querySelector('.nested-count').textContent = enabled.length + '/' + checks.length + ' 사용';
    label.title = representative.dataset.description || '';
    icon.className = (representative.dataset.icon || 'bi-puzzle') + ' nested-summary-icon';
}

// 패키지 종속 플러그인(부가 기능) — 부모 패키지가 꺼지면 함께 꺼지고 잠긴다
function syncNestedPlugins() {
    document.querySelectorAll('.nested-plugin-modal[data-parent]').forEach(function(modal) {
        var parentName = modal.dataset.parent;
        var parent = document.getElementById('package_' + parentName);
        var parentOn = !!(parent && parent.checked);
        nestedChecksForParent(parentName).forEach(function(cb) {
            if (!parentOn && cb.checked) cb.checked = false;
            cb.disabled = !parentOn || cb.dataset.locked === '1';
            cb.closest('.nested-plugin-item').classList.toggle('opacity-50', !parentOn);
        });
        modal.querySelector('.nested-parent-warning').classList.toggle('d-none', parentOn);

        var summary = Array.from(document.querySelectorAll('.nested-summary[data-parent]')).find(function(el) {
            return el.dataset.parent === parentName;
        });
        if (summary) {
            summary.querySelector('.nested-summary-check').disabled = !parentOn;
            summary.classList.toggle('opacity-50', !parentOn);
        }
        updateNestedSummary(parentName);
    });
}
document.querySelectorAll('input[name="formData[packages][]"]').forEach(function(cb) {
    cb.addEventListener('change', syncNestedPlugins);
});
syncNestedPlugins();

document.querySelectorAll('.nested-check[data-parent]').forEach(function(cb) {
    cb.addEventListener('change', function() {
        updateNestedSummary(this.dataset.parent);
    });
});

document.querySelectorAll('.nested-summary-check[data-parent]').forEach(function(proxy) {
    proxy.addEventListener('change', function() {
        var pluginName = this.dataset.plugin;
        var target = nestedChecksForParent(this.dataset.parent).find(function(cb) {
            return cb.value === pluginName;
        });
        if (target && !target.disabled) target.checked = this.checked;
        updateNestedSummary(this.dataset.parent);
    });
});

// 모달 취소·닫기·바깥 클릭·ESC는 열기 전 상태로 복원한다.
document.querySelectorAll('.nested-plugin-modal').forEach(function(modal) {
    modal.addEventListener('show.bs.modal', function() {
        this.dataset.confirmed = '0';
        this.dataset.snapshot = JSON.stringify(nestedChecksForParent(this.dataset.parent).map(function(cb) {
            return { name: cb.value, checked: cb.checked };
        }));
    });

    modal.querySelector('.nested-modal-confirm').addEventListener('click', function() {
        modal.dataset.confirmed = '1';
    });

    modal.addEventListener('hidden.bs.modal', function() {
        if (this.dataset.confirmed !== '1') {
            var snapshot = JSON.parse(this.dataset.snapshot || '[]');
            snapshot.forEach(function(item) {
                var cb = nestedChecksForParent(modal.dataset.parent).find(function(candidate) {
                    return candidate.value === item.name;
                });
                if (cb) cb.checked = item.checked;
            });
        }
        updateNestedSummary(this.dataset.parent);
    });
});

// 전체 선택 — 헤더 체크박스와 개별 체크박스 양방향 동기화 (필수 항목은 잠긴 채 유지)
document.querySelectorAll('.ext-check-all').forEach(function(master) {
    var container = document.getElementById(master.dataset.container);
    if (!container) return;
    var items = container.querySelectorAll('.ext-check');

    // 부분 선택은 중간 상태(－)로 표시 — 중간 상태에서 클릭하면 전체 선택으로 간다
    function syncMaster() {
        var checked = container.querySelectorAll('.ext-check:checked').length;
        master.checked = checked === items.length;
        master.indeterminate = checked > 0 && checked < items.length;
    }

    master.addEventListener('change', function() {
        // 개별 아이템의 change가 syncMaster를 되불러 master.checked를 중간에
        // 바꿔버리므로, 목표 상태를 먼저 고정해 두고 순회한다
        var on = master.checked;
        items.forEach(function(cb) {
            if (cb.disabled || cb.checked === on) return;
            cb.checked = on;
            cb.dispatchEvent(new Event('change')); // border·부가 기능 동기화 재사용
        });
        syncMaster();
    });
    items.forEach(function(cb) { cb.addEventListener('change', syncMaster); });
    syncMaster();
});

// 드래그 정렬
['sortable-plugins', 'sortable-packages'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) {
        new Sortable(el, {
            animation: 150,
            ghostClass: 'opacity-25',
            chosenClass: 'shadow',
            dragClass: 'shadow-lg',
        });
    }
});

MubloRequest.registerCallback('extensionsSaved', function(response) {
    if (response.result === 'success') {
        MubloRequest.showToast(response.message || '확장 기능 설정이 저장되었습니다.', 'success');
        setTimeout(function() { location.reload(); }, 600);
    } else {
        MubloRequest.showAlert(response.message || '저장에 실패했습니다.', 'error');
    }
});

MubloRequest.registerCallback('extensionUploaded', function(response) {
    if (response.result === 'success') {
        MubloRequest.showToast(response.message || '확장이 설치되었습니다.', 'success');
        setTimeout(function() { location.reload(); }, 800);
    } else {
        MubloRequest.showAlert(response.message || '업로드에 실패했습니다.', 'error');
    }
});
</script>
