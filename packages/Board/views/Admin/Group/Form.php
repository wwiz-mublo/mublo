<?php
/**
 * Admin Boardgroup - Form
 *
 * 게시판 그룹 생성/수정 폼
 *
 * @var string $pageTitle 페이지 제목
 * @var bool $isEdit 수정 모드 여부
 * @var array|null $group 그룹 데이터
 * @var array $levelOptions 권한 레벨 옵션
 * @var array $groupAdmins 그룹 관리자 목록
 * @var array $boards 그룹에 속한 게시판 목록
 */

$group = $group ?? [];
$isEdit = $isEdit ?? false;
$levelOptions = $levelOptions ?? [];
$groupAdmins = $groupAdmins ?? [];
$boards = $boards ?? [];
$boardCount = count($boards);
?>
<form name="frm" id="frm">
<div class="page-container form-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '그룹 추가') ?></h3>
            <p>
                <?php if ($isEdit): ?>
                그룹 정보를 수정합니다.
                <?php if ($boardCount > 0): ?>
                <span class="text-info">(이 그룹에 <?= $boardCount ?>개의 게시판이 있습니다)</span>
                <?php endif; ?>
                <?php else: ?>
                새로운 게시판 그룹을 생성합니다.
                <?php endif; ?>
            </p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/board/group" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 목록
            </a>
            <button type="button"
                class="btn btn-sm btn-primary mublo-submit"
                data-target="/admin/board/group/store"
                data-callback="groupSaved">
                <i class="bi bi-check-lg"></i> 저장
            </button>
        </div>
    </div>

    <!-- 숨김 필드 -->
    <?php if ($isEdit): ?>
    <input type="hidden" name="formData[group_id]" value="<?= $group['group_id'] ?? '' ?>">
    <?php endif; ?>

    <!-- 2칸 레이아웃 -->
    <div class="page-block row">
        <!-- 왼쪽 칸: 기본 정보 + 권한 설정 -->
        <div class="col-lg-6">
            <!-- 기본 정보 -->
            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-info-circle text-pastel-blue"></i>
                    <span>기본 정보</span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">슬러그 <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text"
                                       class="form-control"
                                       name="formData[group_slug]"
                                       id="group_slug"
                                       value="<?= htmlspecialchars($group['group_slug'] ?? '') ?>"
                                       pattern="[a-z0-9\-]+"
                                       placeholder="community"
                                       required
                                       <?= $isEdit && $boardCount > 0 ? 'readonly' : '' ?>>
                                <button type="button" class="btn btn-outline-secondary" id="btn-check-slug">
                                    중복확인
                                </button>
                            </div>
                            <div class="form-text">영문 소문자, 숫자, 하이픈(-) 사용 가능 (예: /community/group/<b>슬러그</b>)</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">그룹명 <span class="text-danger">*</span></label>
                            <input type="text"
                                   class="form-control"
                                   name="formData[group_name]"
                                   value="<?= htmlspecialchars($group['group_name'] ?? '') ?>"
                                   placeholder="커뮤니티"
                                   required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">그룹 설명</label>
                        <input type="text"
                               class="form-control"
                               name="formData[group_description]"
                               value="<?= htmlspecialchars($group['group_description'] ?? '') ?>"
                               placeholder="그룹에 대한 간단한 설명 (선택사항)">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">상태</label>
                        <div class="form-check form-switch">
                            <input type="checkbox"
                                   class="form-check-input"
                                   name="formData[is_active]"
                                   id="is_active"
                                   value="1"
                                   <?= ($group['is_active'] ?? true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">사용</label>
                        </div>
                        <div class="form-text">비활성화된 그룹의 게시판은 표시되지 않습니다.</div>
                    </div>
                </div>
            </div>

            <!-- 권한 설정 -->
            <div class="card mt-4">
                <div class="card-hero">
                    <i class="bi bi-shield-lock text-pastel-green"></i>
                    <span>권한 설정 (기본값)</span>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3 small">
                        그룹에 속한 게시판의 기본 권한을 설정합니다. 게시판별로 개별 설정이 가능합니다.
                    </p>
                    <div class="row">
                        <?php
                        $levelFields = [
                            'list_level' => ['label' => '목록 보기', 'default' => 0],
                            'read_level' => ['label' => '글 읽기', 'default' => 0],
                            'write_level' => ['label' => '글쓰기', 'default' => 1],
                            'comment_level' => ['label' => '댓글 쓰기', 'default' => 1],
                            'download_level' => ['label' => '다운로드', 'default' => 0],
                        ];
                        foreach ($levelFields as $field => $info):
                            $currentValue = $group[$field] ?? $info['default'];
                        ?>
                        <div class="col-6 col-md-4 mb-3">
                            <label class="form-label"><?= $info['label'] ?></label>
                            <select class="form-select form-select-sm" name="formData[<?= $field ?>]">
                                <?php foreach ($levelOptions as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $currentValue == $value ? 'selected' : '' ?>>
                                    Lv.<?= $value ?> <?= $label ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 오른쪽 칸: 그룹 관리자 + 게시판 목록 -->
        <div class="col-lg-6">
            <!-- 그룹 관리자 -->
            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-people text-pastel-purple"></i>
                    <span>그룹 관리자</span>
                    <?php if ($isEdit): ?>
                    <button type="button" class="btn btn-xs btn-outline-primary ms-auto" id="btn-add-admin">
                        <i class="bi bi-plus-lg"></i> 추가
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!$isEdit): ?>
                    <p class="text-muted mb-0 small">
                        <i class="bi bi-info-circle"></i>
                        그룹 저장 후 관리자를 추가할 수 있습니다.
                    </p>
                    <?php elseif (empty($groupAdmins)): ?>
                    <p class="text-muted mb-0 small" id="no-admin-msg">
                        <i class="bi bi-info-circle"></i>
                        등록된 그룹 관리자가 없습니다.
                    </p>
                    <?php endif; ?>

                    <div id="admin-list">
                        <?php foreach ($groupAdmins as $admin): ?>
                        <div class="d-flex align-items-center justify-content-between py-2 border-bottom admin-item" data-member-id="<?= $admin['member_id'] ?>">
                            <div>
                                <span class="fw-medium"><?= htmlspecialchars($admin['user_id'] ?? '') ?></span>
                                <span class="text-muted small ms-2">Lv.<?= $admin['level_value'] ?? 0 ?></span>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-admin">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 게시판 목록 -->
            <div class="card mt-4">
                <div class="card-hero">
                    <i class="bi bi-collection text-pastel-sky"></i>
                    <span>게시판 목록</span>
                    <?php if ($isEdit): ?>
                    <a href="/admin/board/config/create?group_id=<?= $group['group_id'] ?? '' ?>" class="btn btn-xs btn-outline-primary ms-auto">
                        <i class="bi bi-plus-lg"></i> 게시판 추가
                    </a>
                    <?php endif; ?>
                </div>
                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                    <?php if (!$isEdit): ?>
                    <p class="text-muted mb-0 small">
                        <i class="bi bi-info-circle"></i>
                        그룹 저장 후 게시판을 추가할 수 있습니다.
                    </p>
                    <?php elseif (empty($boards)): ?>
                    <p class="text-muted mb-0 small">
                        <i class="bi bi-info-circle"></i>
                        이 그룹에 속한 게시판이 없습니다.
                    </p>
                    <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($boards as $board): ?>
                        <a href="/admin/board/config/edit?id=<?= $board['board_id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2">
                            <div>
                                <span class="fw-medium"><?= htmlspecialchars($board['board_name'] ?? '') ?></span>
                                <span class="text-muted small ms-2">/<?= htmlspecialchars($board['board_slug'] ?? '') ?></span>
                            </div>
                            <span class="badge <?= ($board['is_active'] ?? false) ? 'bg-success' : 'bg-secondary' ?>">
                                <?= ($board['is_active'] ?? false) ? '사용' : '미사용' ?>
                            </span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<!-- 관리자 추가 모달 -->
<div class="modal fade" id="adminModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">그룹 관리자 추가</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">회원 검색</label>
                    <input type="text" class="form-control" id="admin-search" placeholder="아이디로 검색">
                </div>
                <div id="admin-search-results" style="max-height: 200px; overflow-y: auto;">
                    <!-- 검색 결과 표시 -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// 저장 완료 콜백
MubloRequest.registerCallback('groupSaved', function(response) {
    if (response.result === 'success') {
        alert(response.message || '저장되었습니다.');
        if (response.data && response.data.redirect) {
            location.href = response.data.redirect;
        } else {
            location.href = '/admin/board/group';
        }
    } else {
        alert(response.message || '저장에 실패했습니다.');
    }
});

// 슬러그 중복 확인
document.getElementById('btn-check-slug')?.addEventListener('click', function() {
    const slugInput = document.getElementById('group_slug');
    const slug = slugInput.value.trim();

    if (!slug) {
        alert('슬러그를 입력해주세요.');
        slugInput.focus();
        return;
    }

    if (!/^[a-z0-9-]+$/.test(slug)) {
        alert('슬러그는 영문 소문자, 숫자, 하이픈만 사용 가능합니다.');
        return;
    }

    const excludeId = document.querySelector('input[name="formData[group_id]"]')?.value || 0;

    MubloRequest.requestJson('/admin/board/group/check-slug', {
        slug: slug,
        exclude_id: parseInt(excludeId)
    }).then(response => {
        if (response.result === 'success') {
            alert(response.message || '사용 가능한 슬러그입니다.');
        } else {
            alert(response.message || '사용할 수 없는 슬러그입니다.');
        }
    }).catch(err => {
        alert('확인 중 오류가 발생했습니다.');
        console.error(err);
    });
});

// 슬러그 입력 시 소문자 변환
document.getElementById('group_slug')?.addEventListener('input', function() {
    this.value = this.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
});

<?php if ($isEdit): ?>
// 그룹 관리자 관련 기능
const groupId = <?= $group['group_id'] ?? 0 ?>;
let adminModal;

document.addEventListener('DOMContentLoaded', function() {
    adminModal = new bootstrap.Modal(document.getElementById('adminModal'));
});

// 관리자 추가 버튼
document.getElementById('btn-add-admin')?.addEventListener('click', function() {
    document.getElementById('admin-search').value = '';
    document.getElementById('admin-search-results').innerHTML = '';
    adminModal.show();
});

// 회원 검색
let searchTimeout;
document.getElementById('admin-search')?.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const keyword = this.value.trim();

    if (keyword.length < 2) {
        document.getElementById('admin-search-results').innerHTML =
            '<p class="text-muted small">2글자 이상 입력하세요.</p>';
        return;
    }

    searchTimeout = setTimeout(() => {
        MubloRequest.requestJson('/admin/member/search', { keyword: keyword, limit: 10 })
            .then(response => {
                const container = document.getElementById('admin-search-results');
                if (response.result === 'success' && response.data?.members?.length > 0) {
                    container.innerHTML = response.data.members.map(m => `
                        <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                            <div>
                                <span class="fw-medium">${m.user_id || ''}</span>
                                <span class="text-muted small ms-2">Lv.${m.level_value || 0}</span>
                            </div>
                            <button type="button" class="btn btn-sm btn-primary btn-select-admin"
                                data-member-id="${m.member_id}"
                                data-user-id="${m.user_id || ''}"
                                data-level="${m.level_value || 0}">
                                선택
                            </button>
                        </div>
                    `).join('');

                    // 선택 버튼 이벤트
                    container.querySelectorAll('.btn-select-admin').forEach(btn => {
                        btn.addEventListener('click', function() {
                            addAdmin(this.dataset.memberId, this.dataset.userId, this.dataset.level);
                        });
                    });
                } else {
                    container.innerHTML = '<p class="text-muted small">검색 결과가 없습니다.</p>';
                }
            })
            .catch(err => {
                console.error(err);
                document.getElementById('admin-search-results').innerHTML =
                    '<p class="text-danger small">검색 중 오류가 발생했습니다.</p>';
            });
    }, 300);
});

// 관리자 추가
function addAdmin(memberId, userId, levelValue) {
    // 이미 등록된 관리자인지 확인
    if (document.querySelector(`.admin-item[data-member-id="${memberId}"]`)) {
        alert('이미 등록된 관리자입니다.');
        return;
    }

    MubloRequest.requestJson('/admin/board/group/admin-add', {
        group_id: groupId,
        member_id: parseInt(memberId)
    }).then(response => {
        if (response.result === 'success') {
            // 목록에 추가
            document.getElementById('no-admin-msg')?.remove();
            const adminList = document.getElementById('admin-list');
            const html = `
                <div class="d-flex align-items-center justify-content-between py-2 border-bottom admin-item" data-member-id="${memberId}">
                    <div>
                        <span class="fw-medium">${userId}</span>
                        <span class="text-muted small ms-2">Lv.${levelValue}</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-admin">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            `;
            adminList.insertAdjacentHTML('beforeend', html);
            adminModal.hide();

            // 새로 추가된 항목에 이벤트 바인딩
            bindRemoveEvents();
        } else {
            alert(response.message || '관리자 추가에 실패했습니다.');
        }
    }).catch(err => {
        alert('오류가 발생했습니다.');
        console.error(err);
    });
}

// 관리자 제거
function bindRemoveEvents() {
    document.querySelectorAll('.btn-remove-admin').forEach(btn => {
        btn.onclick = function() {
            const item = this.closest('.admin-item');
            const memberId = item.dataset.memberId;

            if (!confirm('이 관리자를 제거하시겠습니까?')) return;

            MubloRequest.requestJson('/admin/board/group/admin-remove', {
                group_id: groupId,
                member_id: parseInt(memberId)
            }).then(response => {
                if (response.result === 'success') {
                    item.remove();
                } else {
                    alert(response.message || '관리자 제거에 실패했습니다.');
                }
            }).catch(err => {
                alert('오류가 발생했습니다.');
                console.error(err);
            });
        };
    });
}

// 초기 바인딩
bindRemoveEvents();
<?php endif; ?>
</script>
