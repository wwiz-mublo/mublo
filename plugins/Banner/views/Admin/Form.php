<?php
/**
 * 배너 생성/수정 폼
 *
 * @var string $pageTitle 페이지 제목
 * @var bool $isEdit 수정 모드 여부
 * @var array $banner 배너 데이터
 * @var string|null $error 에러 메시지
 * @var array $extensionFields 패키지 확장 필드 목록
 */
$isEdit = $isEdit ?? false;
$banner = $banner ?? [];
$bannerId = (int) ($banner['banner_id'] ?? 0);
$extensionFields = $extensionFields ?? [];
?>

<form id="banner-form">

<div class="page-container form-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>
                배너 정보를 <?= $isEdit ? '수정' : '등록' ?>합니다.
            </p>
        </div>
        <div class="page-title-actions">
            <a href="<?= htmlspecialchars($listUrl ?? '/admin/banner/list') ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 목록
            </a>
            <button type="button"
                class="btn btn-sm btn-primary mublo-submit"
                data-target="/admin/banner/store"
                data-callback="bannerSaved">
                <i class="bi bi-check-lg"></i> 저장
            </button>
        </div>
    </div>

    <?php if (!empty($error)): ?>
    <div class="alert alert-danger mt-3"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- 숨김 필드 -->
    <input type="hidden" name="formData[banner_id]" value="<?= $bannerId ?>">

    <!-- 2칸 레이아웃 -->
    <div class="page-block row">
        <!-- 왼쪽: 기본 정보 + 이미지 -->
        <div class="col-lg-8">
            <!-- 기본 정보 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-megaphone text-pastel-blue"></i>
                    <span>기본 정보</span>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">배너 제목 <span class="text-danger">*</span></label>
                        <input type="text" name="formData[title]" class="form-control"
                               value="<?= htmlspecialchars($banner['title'] ?? '') ?>"
                               placeholder="배너 제목 (alt 텍스트로 사용)" required>
                    </div>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">링크 URL</label>
                            <input type="text" name="formData[link_url]" class="form-control"
                                   value="<?= htmlspecialchars($banner['link_url'] ?? '') ?>"
                                   placeholder="클릭 시 이동할 URL (비워두면 링크 없음)">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">링크 대상</label>
                            <select name="formData[link_target]" class="form-select">
                                <option value="_self" <?= ($banner['link_target'] ?? '_self') === '_self' ? 'selected' : '' ?>>현재 창</option>
                                <option value="_blank" <?= ($banner['link_target'] ?? '_self') === '_blank' ? 'selected' : '' ?>>새 창</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 이미지 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-image text-pastel-green"></i>
                    <span>이미지</span>
                </div>
                <div class="card-body">
                    <!-- PC 이미지 -->
                    <div class="mb-4">
                        <label class="form-label">PC 이미지 <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" name="formData[pc_image_url]" class="form-control" id="pc-image-url"
                                   value="<?= htmlspecialchars($banner['pc_image_url'] ?? '') ?>"
                                   placeholder="이미지 URL 또는 파일 업로드">
                            <label class="btn btn-outline-secondary" for="pc-image-file">
                                <i class="bi bi-upload"></i> 업로드
                            </label>
                        </div>
                        <input type="file" name="fileData[pc_image]" id="pc-image-file"
                               accept="image/*" class="d-none">
                        <div class="form-text">데스크탑에서 표시되는 배너 이미지입니다.</div>
                        <div id="pc-preview" class="mt-2" <?= empty($banner['pc_image_url']) ? 'style="display:none;"' : '' ?>>
                            <img id="pc-preview-img"
                                 src="<?= htmlspecialchars($banner['pc_image_url'] ?? '') ?>"
                                 alt="PC 미리보기"
                                 style="max-width:100%; max-height:200px; border:1px solid #ddd; border-radius:4px;">
                        </div>
                    </div>

                    <!-- 모바일 이미지 -->
                    <div class="mb-0">
                        <label class="form-label">모바일 이미지</label>
                        <div class="input-group">
                            <input type="text" name="formData[mo_image_url]" class="form-control" id="mo-image-url"
                                   value="<?= htmlspecialchars($banner['mo_image_url'] ?? '') ?>"
                                   placeholder="모바일 이미지 URL (비워두면 PC 이미지 사용)">
                            <label class="btn btn-outline-secondary" for="mo-image-file">
                                <i class="bi bi-upload"></i> 업로드
                            </label>
                        </div>
                        <input type="file" name="fileData[mo_image]" id="mo-image-file"
                               accept="image/*" class="d-none">
                        <div class="form-text">모바일에서 대체 표시됩니다. 비워두면 PC 이미지가 사용됩니다.</div>
                        <div id="mo-preview" class="mt-2" <?= empty($banner['mo_image_url']) ? 'style="display:none;"' : '' ?>>
                            <img id="mo-preview-img"
                                 src="<?= htmlspecialchars($banner['mo_image_url'] ?? '') ?>"
                                 alt="모바일 미리보기"
                                 style="max-width:100%; max-height:150px; border:1px solid #ddd; border-radius:4px;">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 오른쪽: 노출 설정 + 정보 -->
        <div class="col-lg-4">
            <!-- 노출 설정 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-gear text-pastel-purple"></i>
                    <span>노출 설정</span>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input" id="is-active"
                                   name="formData[is_active]" value="1"
                                   <?= ((int) ($banner['is_active'] ?? 1)) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is-active">
                                <strong>사용</strong>
                            </label>
                        </div>
                        <div class="form-text">비활성화하면 프론트에 표시되지 않습니다.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">정렬 순서</label>
                        <input type="number" name="formData[sort_order]" class="form-control"
                               value="<?= (int) ($banner['sort_order'] ?? 0) ?>" min="0">
                        <div class="form-text">작을수록 먼저 표시</div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">노출 기간</label>
                        <div class="d-flex flex-column gap-2">
                            <div class="input-group">
                                <span class="input-group-text">시작</span>
                                <input type="date" name="formData[start_date]" class="form-control"
                                       value="<?= htmlspecialchars($banner['start_date'] ?? '') ?>">
                            </div>
                            <div class="input-group">
                                <span class="input-group-text">종료</span>
                                <input type="date" name="formData[end_date]" class="form-control"
                                       value="<?= htmlspecialchars($banner['end_date'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-text">비워두면 상시 노출</div>
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
                        <dt class="col-sm-5">배너 ID</dt>
                        <dd class="col-sm-7"><?= $bannerId ?></dd>

                        <?php if (!empty($banner['created_at'])): ?>
                        <dt class="col-sm-5">등록일</dt>
                        <dd class="col-sm-7"><?= htmlspecialchars($banner['created_at']) ?></dd>
                        <?php endif; ?>

                        <?php if (!empty($banner['updated_at'])): ?>
                        <dt class="col-sm-5">수정일</dt>
                        <dd class="col-sm-7 mb-0"><?= htmlspecialchars($banner['updated_at']) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($extensionFields)): ?>
            <!-- 추가 설정 (패키지 제공) -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-puzzle text-pastel-teal"></i>
                    <span>추가 설정</span>
                </div>
                <div class="card-body">
                    <?php foreach ($extensionFields as $field): ?>
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars($field['label'] ?? '') ?></label>
                        <?php if (($field['type'] ?? 'text') === 'select'): ?>
                        <select name="formData[<?= htmlspecialchars($field['key']) ?>]" class="form-select">
                            <option value="">선택</option>
                            <?php foreach ($field['options'] ?? [] as $opt): ?>
                            <option value="<?= htmlspecialchars($opt['value'] ?? '') ?>"
                                <?= ($field['value'] ?? '') === ($opt['value'] ?? '') ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt['label'] ?? '') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php elseif (($field['type'] ?? 'text') === 'hidden'): ?>
                        <input type="hidden" name="formData[<?= htmlspecialchars($field['key']) ?>]"
                               value="<?= htmlspecialchars($field['value'] ?? '') ?>">
                        <?php else: ?>
                        <input type="text" name="formData[<?= htmlspecialchars($field['key']) ?>]"
                               class="form-control"
                               value="<?= htmlspecialchars($field['value'] ?? '') ?>"
                               placeholder="<?= htmlspecialchars($field['placeholder'] ?? '') ?>">
                        <?php endif; ?>
                        <?php if (!empty($field['helpText'])): ?>
                        <div class="form-text"><?= htmlspecialchars($field['helpText']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

</form>

<script>
// 저장 완료 콜백
MubloRequest.registerCallback('bannerSaved', function(response) {
    if (response.result === 'success') {
        alert(response.message || '저장되었습니다.');
        var redirect = (response.data && response.data.redirect) || '/admin/banner/list';
        location.href = redirect;
    } else {
        alert(response.message || '저장에 실패했습니다.');
    }
});

// 이미지 미리보기
document.addEventListener('DOMContentLoaded', function() {
    setupImagePreview('pc-image-file', 'pc-image-url', 'pc-preview', 'pc-preview-img');
    setupImagePreview('mo-image-file', 'mo-image-url', 'mo-preview', 'mo-preview-img');

    function setupImagePreview(fileId, urlId, previewId, previewImgId) {
        var fileInput = document.getElementById(fileId);
        var urlInput = document.getElementById(urlId);
        var preview = document.getElementById(previewId);
        var previewImg = document.getElementById(previewImgId);

        if (fileInput) {
            fileInput.addEventListener('change', function() {
                var file = this.files[0];
                if (!file) return;

                var reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    preview.style.display = '';
                };
                reader.readAsDataURL(file);
            });
        }

        if (urlInput) {
            urlInput.addEventListener('change', function() {
                var url = this.value.trim();
                if (url) {
                    previewImg.src = url;
                    preview.style.display = '';
                } else {
                    preview.style.display = 'none';
                }
            });
        }
    }
});
</script>
