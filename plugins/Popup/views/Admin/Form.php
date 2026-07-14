<?php
/**
 * 팝업 생성/수정 폼 (HTML 전용, 에디터 기반)
 *
 * @var string $pageTitle 페이지 제목
 * @var bool $isEdit 수정 모드 여부
 * @var array $popup 팝업 데이터
 */
$isEdit = $isEdit ?? false;
$popup = $popup ?? [];
$popupId = (int) ($popup['popup_id'] ?? 0);
?>

<?= editor_css() ?>

<form id="popup-form">

<div class="page-container form-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>
                팝업 정보를 <?= $isEdit ? '수정' : '등록' ?>합니다.
            </p>
        </div>
        <div class="page-title-actions">
            <a href="<?= htmlspecialchars($listUrl ?? '/admin/popup/list') ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 목록
            </a>
            <button type="button"
                class="btn btn-sm btn-primary mublo-submit"
                data-target="/admin/popup/store"
                data-callback="popupSaved">
                <i class="bi bi-check-lg"></i> 저장
            </button>
        </div>
    </div>

    <!-- 숨김 필드 -->
    <input type="hidden" name="formData[popup_id]" value="<?= $popupId ?>">

    <!-- 2칸 레이아웃 -->
    <div class="page-block row">
        <!-- 왼쪽: 기본 정보 + 콘텐츠 -->
        <div class="col-lg-8">
            <!-- 기본 정보 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-megaphone text-pastel-blue"></i>
                    <span>기본 정보</span>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">팝업 제목 <span class="text-danger">*</span></label>
                        <input type="text" name="formData[title]" class="form-control"
                               value="<?= htmlspecialchars($popup['title'] ?? '') ?>"
                               placeholder="팝업 제목을 입력하세요" required>
                    </div>

                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">링크 URL</label>
                            <input type="text" name="formData[link_url]" class="form-control"
                                   value="<?= htmlspecialchars($popup['link_url'] ?? '') ?>"
                                   placeholder="클릭 시 이동할 URL (비워두면 링크 없음)">
                            <div class="form-text">팝업 전체 클릭 시 이동할 URL</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">링크 대상</label>
                            <select name="formData[link_target]" class="form-select">
                                <option value="_self" <?= ($popup['link_target'] ?? '_self') === '_self' ? 'selected' : '' ?>>현재창</option>
                                <option value="_blank" <?= ($popup['link_target'] ?? '_self') === '_blank' ? 'selected' : '' ?>>새창</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 팝업 콘텐츠 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-code-slash text-pastel-green"></i>
                    <span>팝업 콘텐츠</span>
                </div>
                <div class="card-body">
                    <?= editor_html('popup_html_content', $popup['html_content'] ?? '', [
                        'name' => 'formData[html_content]',
                        'height' => 400,
                        'toolbar' => 'full',
                        'placeholder' => '팝업 내용을 입력하세요',
                    ]) ?>
                </div>
            </div>
        </div>

        <!-- 오른쪽: 표시 설정 -->
        <div class="col-lg-4">
            <!-- 표시 설정 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-gear text-pastel-purple"></i>
                    <span>표시 설정</span>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input" id="is-active"
                                   name="formData[is_active]" value="1"
                                   <?= ((int) ($popup['is_active'] ?? 1)) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is-active">
                                <strong>사용</strong>
                            </label>
                        </div>
                        <div class="form-text">비활성화하면 프론트에 표시되지 않습니다.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">위치</label>
                        <select name="formData[position]" class="form-select">
                            <option value="center" <?= ($popup['position'] ?? 'center') === 'center' ? 'selected' : '' ?>>중앙</option>
                            <option value="top-left" <?= ($popup['position'] ?? '') === 'top-left' ? 'selected' : '' ?>>좌상단</option>
                            <option value="top-right" <?= ($popup['position'] ?? '') === 'top-right' ? 'selected' : '' ?>>우상단</option>
                            <option value="bottom-left" <?= ($popup['position'] ?? '') === 'bottom-left' ? 'selected' : '' ?>>좌하단</option>
                            <option value="bottom-right" <?= ($popup['position'] ?? '') === 'bottom-right' ? 'selected' : '' ?>>우하단</option>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">너비</label>
                            <div class="input-group">
                                <input type="number" name="formData[width]" class="form-control"
                                    value="<?= (int) ($popup['width'] ?? 500) ?>" min="0">
                                <div class="input-group-text">px</div>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">높이</label>
                            <div class="input-group">
                                <input type="number" name="formData[height]" class="form-control"
                                    value="<?= (int) ($popup['height'] ?? 0) ?>" min="0">
                                <div class="input-group-text">px</div>
                            </div>
                            <div class="form-text">0 = 자동</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">표시 기기</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input type="radio" class="form-check-input" id="device-all"
                                       name="formData[display_device]" value="all"
                                       <?= ($popup['display_device'] ?? 'all') === 'all' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="device-all">전체</label>
                            </div>
                            <div class="form-check">
                                <input type="radio" class="form-check-input" id="device-pc"
                                       name="formData[display_device]" value="pc"
                                       <?= ($popup['display_device'] ?? '') === 'pc' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="device-pc">PC전용</label>
                            </div>
                            <div class="form-check">
                                <input type="radio" class="form-check-input" id="device-mo"
                                       name="formData[display_device]" value="mo"
                                       <?= ($popup['display_device'] ?? '') === 'mo' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="device-mo">모바일전용</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">정렬 순서</label>
                        <input type="number" name="formData[sort_order]" class="form-control"
                               value="<?= (int) ($popup['sort_order'] ?? 0) ?>" min="0">
                        <div class="form-text">작을수록 먼저 표시</div>
                    </div>
                </div>
            </div>

            <!-- 표시 기간 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-calendar-range text-pastel-orange"></i>
                    <span>표시 기간</span>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">표시 기간</label>
                        <div class="d-flex flex-column gap-2">
                            <div class="input-group">
                                <span class="input-group-text">시작</span>
                                <input type="date" name="formData[start_date]" class="form-control"
                                    value="<?= htmlspecialchars($popup['start_date'] ?? '') ?>">
                            </div>
                            <div class="input-group">
                                <span class="input-group-text">종료</span>
                                <input type="date" name="formData[end_date]" class="form-control"
                                    value="<?= htmlspecialchars($popup['end_date'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-text">비워두면 상시 노출</div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">안 보기 유지 시간</label>
                        <input type="number" name="formData[hide_duration]" class="form-control"
                               value="<?= (int) ($popup['hide_duration'] ?? 24) ?>" min="0">
                        <div class="form-text">시간 단위, 0 = 매번 표시</div>
                    </div>
                </div>
            </div>

            <?php if ($isEdit): ?>
            <!-- 정보 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-info-circle text-pastel-sky"></i>
                    <span>정보</span>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">팝업 ID</dt>
                        <dd class="col-sm-7"><?= $popupId ?></dd>

                        <?php if (!empty($popup['created_at'])): ?>
                        <dt class="col-sm-5">등록일</dt>
                        <dd class="col-sm-7"><?= htmlspecialchars($popup['created_at']) ?></dd>
                        <?php endif; ?>

                        <?php if (!empty($popup['updated_at'])): ?>
                        <dt class="col-sm-5">수정일</dt>
                        <dd class="col-sm-7 mb-0"><?= htmlspecialchars($popup['updated_at']) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</form>

<?= editor_js() ?>

<script>
// 저장 완료 콜백
MubloRequest.registerCallback('popupSaved', function(response) {
    if (response.result === 'success') {
        alert(response.message || '저장되었습니다.');
        var redirect = (response.data && response.data.redirect) || '/admin/popup/list';
        location.href = redirect;
    } else {
        alert(response.message || '저장에 실패했습니다.');
    }
});
</script>
