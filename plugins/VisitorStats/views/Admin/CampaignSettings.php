<?php $currentTab = 'campaign-settings'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<!-- 키 추가 폼 -->
<div class="card mb-3">
    <div class="card-hero">
        <i class="bi bi-plus-circle text-chart-indigo"></i>
        <span>캠페인 키 추가</span>
    </div>
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small">캠페인 키 <span class="text-danger">*</span></label>
                <input type="text" id="ck-key" class="form-control form-control-sm"
                       placeholder="영문,숫자,_,- (예: blog_feb)" style="width:180px;">
            </div>
            <div class="col-auto">
                <label class="form-label small">그룹명</label>
                <input type="text" id="ck-group" class="form-control form-control-sm"
                       placeholder="예: 블로그 홍보" style="width:180px;">
            </div>
            <div class="col-auto">
                <label class="form-label small">메모</label>
                <input type="text" id="ck-memo" class="form-control form-control-sm"
                       placeholder="배포 위치 등" style="width:240px;">
            </div>
            <div class="col-auto">
                <button type="button" id="ck-add-btn" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg"></i> 추가
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 키 목록 -->
<div class="card">
    <div class="card-hero">
        <i class="bi bi-list-check text-chart-emerald"></i>
        <span>등록된 캠페인 키</span>
        <span class="text-muted small fw-normal ms-auto"><?= count($keys ?? []) ?>건</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover vs-table mb-0">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>캠페인 키</th>
                        <th>그룹명</th>
                        <th>메모</th>
                        <th style="width:80px;" class="text-center">상태</th>
                        <th style="width:170px;" class="text-nowrap">생성일</th>
                        <th style="width:180px;" class="text-center">관리</th>
                    </tr>
                </thead>
                <tbody id="ck-tbody">
                    <?php if (empty($keys)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">등록된 캠페인 키가 없습니다.</td></tr>
                    <?php else: ?>
                    <?php foreach ($keys as $i => $k): ?>
                    <tr data-id="<?= (int) $k['key_id'] ?>">
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td>
                            <code><?= htmlspecialchars($k['campaign_key'], ENT_QUOTES, 'UTF-8') ?></code>
                        </td>
                        <td>
                            <span class="ck-view-group"><?= htmlspecialchars($k['group_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" class="form-control form-control-sm ck-edit-group d-none"
                                   value="<?= htmlspecialchars($k['group_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </td>
                        <td>
                            <span class="ck-view-memo"><?= htmlspecialchars($k['memo'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" class="form-control form-control-sm ck-edit-memo d-none"
                                   value="<?= htmlspecialchars($k['memo'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </td>
                        <td class="text-center">
                            <?php if ((int) $k['is_active']): ?>
                                <span class="badge bg-success">활성</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">비활성</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars($k['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-center">
                            <button type="button" class="btn btn-outline-secondary btn-sm ck-copy-btn"
                                    data-key="<?= htmlspecialchars($k['campaign_key'], ENT_QUOTES, 'UTF-8') ?>"
                                    title="URL 복사"><i class="bi bi-clipboard"></i></button>
                            <button type="button" class="btn btn-outline-primary btn-sm ck-edit-btn"
                                    title="수정"><i class="bi bi-pencil"></i></button>
                            <button type="button" class="btn btn-outline-primary btn-sm ck-save-btn d-none"
                                    title="저장"><i class="bi bi-check-lg"></i></button>
                            <?php if ((int) $k['is_active']): ?>
                            <button type="button" class="btn btn-outline-warning btn-sm ck-toggle-btn"
                                    data-active="0" title="비활성화"><i class="bi bi-pause"></i></button>
                            <?php else: ?>
                            <button type="button" class="btn btn-outline-success btn-sm ck-toggle-btn"
                                    data-active="1" title="활성화"><i class="bi bi-play"></i></button>
                            <?php endif; ?>
                            <?php if (($k['campaign_key'] ?? '') !== 'qr'): // QR 키는 시스템 예약 — 삭제 불가 ?>
                            <button type="button" class="btn btn-outline-danger btn-sm ck-del-btn"
                                    title="삭제"><i class="bi bi-trash"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var siteDomain = '<?= htmlspecialchars($siteDomain ?? '', ENT_QUOTES, 'UTF-8') ?>';

    // 키 추가
    document.getElementById('ck-add-btn').addEventListener('click', function () {
        var key = document.getElementById('ck-key').value.trim();
        var group = document.getElementById('ck-group').value.trim();
        var memo = document.getElementById('ck-memo').value.trim();

        if (!key) { MubloRequest.showAlert('캠페인 키를 입력해 주세요.'); return; }

        MubloRequest.requestJson('/admin/visitor-stats/api/campaign-key/create', {
            campaign_key: key,
            group_name: group,
            memo: memo
        }, { method: 'POST' })
            .then(function () {
                location.reload();
            });
    });

    // URL 복사
    document.querySelectorAll('.ck-copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var key = this.dataset.key;
            var protocol = location.protocol + '//';
            var url = protocol + (siteDomain || location.host) + '/?k=' + encodeURIComponent(key);
            navigator.clipboard.writeText(url).then(function () {
                MubloRequest.showAlert('URL이 클립보드에 복사되었습니다.\n' + url);
            });
        });
    });

    // 수정 모드 전환
    document.querySelectorAll('.ck-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tr = this.closest('tr');
            tr.querySelectorAll('.ck-view-group, .ck-view-memo').forEach(function (el) { el.classList.add('d-none'); });
            tr.querySelectorAll('.ck-edit-group, .ck-edit-memo').forEach(function (el) { el.classList.remove('d-none'); });
            tr.querySelector('.ck-edit-btn').classList.add('d-none');
            tr.querySelector('.ck-save-btn').classList.remove('d-none');
        });
    });

    // 저장
    document.querySelectorAll('.ck-save-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tr = this.closest('tr');
            var keyId = parseInt(tr.dataset.id, 10);
            var group = tr.querySelector('.ck-edit-group').value.trim();
            var memo = tr.querySelector('.ck-edit-memo').value.trim();

            MubloRequest.requestJson('/admin/visitor-stats/api/campaign-key/update', {
                key_id: keyId,
                group_name: group,
                memo: memo
            }, { method: 'POST' })
                .then(function () {
                    location.reload();
                });
        });
    });

    // 활성/비활성 토글
    document.querySelectorAll('.ck-toggle-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tr = this.closest('tr');
            var keyId = parseInt(tr.dataset.id, 10);
            var isActive = parseInt(this.dataset.active, 10);

            MubloRequest.requestJson('/admin/visitor-stats/api/campaign-key/update', {
                key_id: keyId,
                is_active: isActive
            }, { method: 'POST' })
                .then(function () {
                    location.reload();
                });
        });
    });

    // 삭제
    document.querySelectorAll('.ck-del-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('이 캠페인 키를 삭제하시겠습니까?\n(집계 데이터는 유지됩니다)')) return;

            var tr = this.closest('tr');
            var keyId = parseInt(tr.dataset.id, 10);

            MubloRequest.requestJson('/admin/visitor-stats/api/campaign-key/delete', {
                key_id: keyId
            }, { method: 'POST' })
                .then(function () {
                    location.reload();
                });
        });
    });
});
</script>
